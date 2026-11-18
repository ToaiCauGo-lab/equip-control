
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";

// Xử lý thêm, sửa, xóa
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "add" || $action === "update") {
            $id = (int)($_POST["id"] ?? 0);
            $code = trim($_POST["device_code"] ?? "");
            $name = trim($_POST["device_name"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $quantity = (int)($_POST["quantity"] ?? 0);
            $unit = trim($_POST["unit"] ?? "Cái");
            $location = trim($_POST["location"] ?? "");
            $status = $_POST["status"] ?? "Đang sử dụng";
            $description = trim($_POST["description"] ?? "");

            $allowedStatus = [
                "Đang sử dụng",
                "Đang bảo trì",
                "Ngừng sử dụng"
            ];

            if ($code === "" || $name === "") {
                throw new Exception("Vui lòng nhập mã và tên thiết bị.");
            }

            if ($quantity < 0) {
                throw new Exception("Số lượng không được nhỏ hơn 0.");
            }

            if (!in_array($status, $allowedStatus, true)) {
                throw new Exception("Trạng thái thiết bị không hợp lệ.");
            }

            if ($action === "add") {
                $sql = "INSERT INTO devices
                    (device_code, device_name, category, quantity, unit, location, status, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $code, $name, $category, $quantity,
                    $unit, $location, $status, $description
                ]);

                // Ghi nhật ký thêm thiết bị
                $newId = (int)$conn->lastInsertId();

                logActivity(
                    $conn,
                    "Thêm thiết bị",
                    "Đã thêm thiết bị: {$name} | Mã: {$code} | Số lượng: {$quantity} {$unit} | ID: {$newId}"
                );

                $message = "Thêm thiết bị thành công!";

            } else {
                if ($id <= 0) {
                    throw new Exception("Thiết bị cần cập nhật không hợp lệ.");
                }

                // Lấy thông tin cũ trước khi cập nhật để ghi nhật ký
                $oldStmt = $conn->prepare("SELECT * FROM devices WHERE id = ?");
                $oldStmt->execute([$id]);
                $oldDevice = $oldStmt->fetch();

                if (!$oldDevice) {
                    throw new Exception("Không tìm thấy thiết bị cần cập nhật.");
                }

                $sql = "UPDATE devices SET
                    device_code = ?,
                    device_name = ?,
                    category = ?,
                    quantity = ?,
                    unit = ?,
                    location = ?,
                    status = ?,
                    description = ?
                    WHERE id = ?";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $code, $name, $category, $quantity,
                    $unit, $location, $status, $description, $id
                ]);

                // Ghi nhật ký cập nhật thiết bị
                logActivity(
                    $conn,
                    "Sửa thiết bị",
                    "Đã cập nhật thiết bị: {$name} | Mã: {$code} | ID: {$id}. Thông tin trước khi sửa: {$oldDevice['device_name']} - {$oldDevice['device_code']}"
                );

                $message = "Cập nhật thiết bị thành công!";
            }

        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            if ($id <= 0) {
                throw new Exception("Thiết bị cần xóa không hợp lệ.");
            }

            // Lấy thông tin thiết bị trước khi xóa
            $oldStmt = $conn->prepare("SELECT * FROM devices WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldDevice = $oldStmt->fetch();

            if (!$oldDevice) {
                throw new Exception("Không tìm thấy thiết bị cần xóa.");
            }

            $stmt = $conn->prepare("DELETE FROM devices WHERE id = ?");
            $stmt->execute([$id]);

            // Chỉ ghi nhật ký nếu xóa thành công
            logActivity(
                $conn,
                "Xóa thiết bị",
                "Đã xóa thiết bị: {$oldDevice['device_name']} | Mã: {$oldDevice['device_code']} | ID: {$id}"
            );

            $message = "Xóa thiết bị thành công!";
        }

    } catch (PDOException $ex) {
        if ($ex->getCode() === "23000") {
            $message = "Mã thiết bị đã tồn tại hoặc thiết bị đang có dữ liệu mượn/bảo trì nên chưa thể xóa.";
        } else {
            $message = "Có lỗi cơ sở dữ liệu. Vui lòng kiểm tra lại thao tác.";
        }

        $messageType = "error";

    } catch (Exception $ex) {
        $message = $ex->getMessage();
        $messageType = "error";
    }
}

// Lấy thông tin thiết bị cần sửa
$editDevice = null;

if (isset($_GET["edit"])) {
    $editId = (int)$_GET["edit"];

    $stmt = $conn->prepare("SELECT * FROM devices WHERE id = ?");
    $stmt->execute([$editId]);
    $editDevice = $stmt->fetch();

    if (!$editDevice) {
        $message = "Không tìm thấy thiết bị cần sửa.";
        $messageType = "error";
    }
}

// Tìm kiếm và lọc
$keyword = trim($_GET["keyword"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(device_code LIKE ? OR device_name LIKE ? OR category LIKE ?)";
    $search = "%" . $keyword . "%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$validFilters = ["Đang sử dụng", "Đang bảo trì", "Ngừng sử dụng"];

if (in_array($statusFilter, $validFilters, true)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Thống kê
$totalDevices = (int)$conn->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$usingDevices = (int)$conn->query("SELECT COUNT(*) FROM devices WHERE status = 'Đang sử dụng'")->fetchColumn();
$repairDevices = (int)$conn->query("SELECT COUNT(*) FROM devices WHERE status = 'Đang bảo trì'")->fetchColumn();
$stoppedDevices = (int)$conn->query("SELECT COUNT(*) FROM devices WHERE status = 'Ngừng sử dụng'")->fetchColumn();

// Phân trang
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("SELECT COUNT(*) FROM devices $whereSql");
$countStmt->execute($params);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM devices $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

$pageTitle = "Quản lý thiết bị";
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - EQUIP CONTROL</title>
    <link rel="stylesheet" href="/equip-control/assets/css/unified.css">

    <style>
        .devices-content {
            padding: 25px;
            width: 100%;
            box-sizing: border-box;
            color: #e8f0fa;
        }

        .devices-heading {
            margin: 0 0 8px;
            font-size: 25px;
            color: #22d3ee;
        }

        .devices-subtitle {
            color: #91a6bd;
            margin-bottom: 25px;
        }

        .devices-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .devices-stat {
            background: #111f31;
            border: 1px solid #223a54;
            border-radius: 12px;
            padding: 20px;
        }

        .devices-stat span {
            display: block;
            color: #91a6bd;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .devices-stat strong {
            color: #22d3ee;
            font-size: 27px;
        }

        .devices-panel {
            background: #111f31;
            border: 1px solid #223a54;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
        }

        .devices-panel h3 {
            color: #22d3ee;
            margin: 0 0 18px;
            font-size: 17px;
        }

        .devices-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .devices-field label {
            display: block;
            color: #a9bfd5;
            font-size: 13px;
            margin-bottom: 7px;
        }

        .devices-field input,
        .devices-field select,
        .devices-field textarea,
        .devices-search input,
        .devices-search select {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border-radius: 7px;
            border: 1px solid #304963;
            background: #0b1726;
            color: #e8f0fa;
            outline: none;
        }

        .devices-field input:focus,
        .devices-field select:focus,
        .devices-field textarea:focus {
            border-color: #22d3ee;
        }

        .devices-field textarea {
            min-height: 75px;
            resize: vertical;
        }

        .devices-field.full {
            grid-column: 1 / -1;
        }

        .devices-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-top: 17px;
        }

        .devices-btn {
            display: inline-block;
            padding: 10px 16px;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .devices-btn-primary {
            background: #0891b2;
            color: white;
        }

        .devices-btn-secondary {
            background: #30445c;
            color: white;
        }

        .devices-btn-edit {
            background: #174b72;
            color: #bae6fd;
        }

        .devices-btn-delete {
            background: #7f1d1d;
            color: #fecaca;
        }

        .devices-search {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .devices-search input {
            flex: 1;
        }

        .devices-search select {
            max-width: 210px;
        }

        .devices-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .devices-table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .devices-table th,
        .devices-table td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #263b52;
            font-size: 13px;
        }

        .devices-table th {
            color: #22d3ee;
            background: #17283c;
            white-space: nowrap;
        }

        .devices-table td {
            color: #d4e1ef;
        }

        .devices-table tr:hover td {
            background: rgba(34, 211, 238, .04);
        }

        .device-status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            white-space: nowrap;
        }

        .status-using {
            background: rgba(34,197,94,.13);
            color: #86efac;
        }

        .status-repair {
            background: rgba(245,158,11,.13);
            color: #fcd34d;
        }

        .status-stopped {
            background: rgba(239,68,68,.13);
            color: #fca5a5;
        }

        .devices-alert {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .devices-alert.success {
            background: rgba(34,197,94,.12);
            border: 1px solid rgba(34,197,94,.3);
            color: #86efac;
        }

        .devices-alert.error {
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
        }

        .devices-pagination {
            display: flex;
            gap: 7px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .devices-pagination a,
        .devices-pagination span {
            padding: 8px 12px;
            border-radius: 6px;
            background: #1b3047;
            color: #d4e1ef;
            text-decoration: none;
            font-size: 13px;
        }

        .devices-pagination .current {
            background: #0891b2;
            color: white;
        }

        .devices-empty {
            text-align: center !important;
            padding: 30px !important;
            color: #91a6bd !important;
        }

        @media(max-width: 900px) {
            .devices-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width: 600px) {
            .devices-content {
                padding: 15px;
            }

            .devices-form-grid,
            .devices-stats {
                grid-template-columns: 1fr;
            }

            .devices-search {
                flex-direction: column;
            }

            .devices-search select {
                max-width: none;
            }
        }
    </style>
</head>

<body>

<div class="page-container">

    <?php require_once __DIR__ . "/includes/sidebar.php"; ?>

    <main class="main-content">
        <div class="devices-content">

            <h1 class="devices-heading">QUẢN LÝ THIẾT BỊ</h1>
            <p class="devices-subtitle">Theo dõi, cập nhật và quản lý toàn bộ thiết bị trong hệ thống.</p>

            <?php if ($message !== ""): ?>
                <div class="devices-alert <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <div class="devices-stats">
                <div class="devices-stat">
                    <span>Tổng số thiết bị</span>
                    <strong><?= $totalDevices ?></strong>
                </div>

                <div class="devices-stat">
                    <span>Đang sử dụng</span>
                    <strong><?= $usingDevices ?></strong>
                </div>

                <div class="devices-stat">
                    <span>Đang bảo trì</span>
                    <strong><?= $repairDevices ?></strong>
                </div>

                <div class="devices-stat">
                    <span>Ngừng sử dụng</span>
                    <strong><?= $stoppedDevices ?></strong>
                </div>
            </div>

            <section class="devices-panel">
                <h3><?= $editDevice ? "CẬP NHẬT THIẾT BỊ" : "THÊM THIẾT BỊ MỚI" ?></h3>

                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editDevice ? "update" : "add" ?>">

                    <?php if ($editDevice): ?>
                        <input type="hidden" name="id" value="<?= (int)$editDevice["id"] ?>">
                    <?php endif; ?>

                    <div class="devices-form-grid">

                        <div class="devices-field">
                            <label>Mã thiết bị *</label>
                            <input type="text" name="device_code" required
                                value="<?= e($editDevice["device_code"] ?? "") ?>"
                                placeholder="Ví dụ: TB001">
                        </div>

                        <div class="devices-field">
                            <label>Tên thiết bị *</label>
                            <input type="text" name="device_name" required
                                value="<?= e($editDevice["device_name"] ?? "") ?>"
                                placeholder="Nhập tên thiết bị">
                        </div>

                        <div class="devices-field">
                            <label>Danh mục</label>
                            <input type="text" name="category"
                                value="<?= e($editDevice["category"] ?? "") ?>"
                                placeholder="Ví dụ: Máy đo, máy tính...">
                        </div>

                        <div class="devices-field">
                            <label>Số lượng</label>
                            <input type="number" name="quantity" min="0"
                                value="<?= e($editDevice["quantity"] ?? 0) ?>">
                        </div>

                        <div class="devices-field">
                            <label>Đơn vị</label>
                            <input type="text" name="unit"
                                value="<?= e($editDevice["unit"] ?? "Cái") ?>"
                                placeholder="Cái, Bộ, Chiếc...">
                        </div>

                        <div class="devices-field">
                            <label>Vị trí lưu trữ</label>
                            <input type="text" name="location"
                                value="<?= e($editDevice["location"] ?? "") ?>"
                                placeholder="Ví dụ: Kho A, Phòng kỹ thuật...">
                        </div>

                        <div class="devices-field">
                            <label>Trạng thái</label>
                            <select name="status">
                                <?php
                                $currentStatus = $editDevice["status"] ?? "Đang sử dụng";
                                $statuses = ["Đang sử dụng", "Đang bảo trì", "Ngừng sử dụng"];
                                foreach ($statuses as $s):
                                ?>
                                    <option value="<?= e($s) ?>" <?= $currentStatus === $s ? "selected" : "" ?>>
                                        <?= e($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="devices-field full">
                            <label>Mô tả</label>
                            <textarea name="description" placeholder="Thông tin mô tả thiết bị..."><?= e($editDevice["description"] ?? "") ?></textarea>
                        </div>

                    </div>

                    <div class="devices-actions">
                        <button type="submit" class="devices-btn devices-btn-primary">
                            <?= $editDevice ? "LƯU CẬP NHẬT" : "THÊM THIẾT BỊ" ?>
                        </button>

                        <?php if ($editDevice): ?>
                            <a href="/equip-control/devices.php" class="devices-btn devices-btn-secondary">
                                HỦY CHỈNH SỬA
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="devices-panel">
                <h3>DANH SÁCH THIẾT BỊ</h3>

                <form method="GET" class="devices-search">
                    <input type="text" name="keyword"
                        value="<?= e($keyword) ?>"
                        placeholder="Tìm theo mã, tên hoặc danh mục...">

                    <select name="status">
                        <option value="">Tất cả trạng thái</option>
                        <?php foreach ($validFilters as $s): ?>
                            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? "selected" : "" ?>>
                                <?= e($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="devices-btn devices-btn-primary" type="submit">TÌM KIẾM</button>
                    <a href="/equip-control/devices.php" class="devices-btn devices-btn-secondary">LÀM MỚI</a>
                </form>

                <div class="devices-table-wrap">
                    <table class="devices-table">
                        <thead>
                            <tr>
                                <th>STT</th>
                                <th>Mã thiết bị</th>
                                <th>Tên thiết bị</th>
                                <th>Danh mục</th>
                                <th>Số lượng</th>
                                <th>Vị trí</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($devices) > 0): ?>
                                <?php foreach ($devices as $index => $device): ?>
                                    <?php
                                    $statusClass = "status-using";

                                    if ($device["status"] === "Đang bảo trì") {
                                        $statusClass = "status-repair";
                                    } elseif ($device["status"] === "Ngừng sử dụng") {
                                        $statusClass = "status-stopped";
                                    }
                                    ?>

                                    <tr>
                                        <td><?= $offset + $index + 1 ?></td>
                                        <td><?= e($device["device_code"]) ?></td>
                                        <td><?= e($device["device_name"]) ?></td>
                                        <td><?= e($device["category"]) ?></td>
                                        <td><?= (int)$device["quantity"] ?> <?= e($device["unit"]) ?></td>
                                        <td><?= e($device["location"]) ?></td>

                                        <td>
                                            <span class="device-status <?= $statusClass ?>">
                                                <?= e($device["status"]) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="devices-actions">
                                                <a class="devices-btn devices-btn-edit"
                                                   href="?edit=<?= (int)$device["id"] ?>">
                                                    Sửa
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('Mày có chắc chắn muốn xóa thiết bị này không?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$device["id"] ?>">

                                                    <button type="submit" class="devices-btn devices-btn-delete">
                                                        Xóa
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="devices-empty">
                                        Không tìm thấy thiết bị phù hợp.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="devices-pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php
                            $query = $_GET;
                            $query["page"] = $i;
                            $url = "?" . http_build_query($query);
                            ?>

                            <a href="<?= e($url) ?>"
                               class="<?= $page === $i ? "current" : "" ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                <p style="color:#91a6bd;font-size:12px;margin-top:15px;">
                    Hiển thị <?= count($devices) ?> / <?= $filteredTotal ?> thiết bị phù hợp.
                </p>
            </section>

        </div>
    </main>

</div>

</body>
</html>
