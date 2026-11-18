
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function componentEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$validStatuses = ["Đang sử dụng", "Ngừng sử dụng"];
$message = "";
$messageType = "success";

// XỬ LÝ THÊM, SỬA, XÓA
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "add" || $action === "update") {
            $id = (int)($_POST["id"] ?? 0);
            $code = trim($_POST["component_code"] ?? "");
            $name = trim($_POST["component_name"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $quantity = (int)($_POST["quantity"] ?? 0);
            $minQuantity = (int)($_POST["min_quantity"] ?? 5);
            $unit = trim($_POST["unit"] ?? "Cái");
            $location = trim($_POST["location"] ?? "");
            $status = $_POST["status"] ?? "Đang sử dụng";
            $description = trim($_POST["description"] ?? "");

            if ($code === "" || $name === "") {
                throw new Exception("Vui lòng nhập mã và tên linh kiện.");
            }

            if ($quantity < 0 || $minQuantity < 0) {
                throw new Exception("Số lượng không được nhỏ hơn 0.");
            }

            if (!in_array($status, $validStatuses, true)) {
                throw new Exception("Trạng thái không hợp lệ.");
            }

            if ($action === "add") {
                $stmt = $conn->prepare("
                    INSERT INTO components
                    (component_code, component_name, category, quantity,
                     min_quantity, unit, location, status, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $code, $name, $category, $quantity,
                    $minQuantity, $unit, $location, $status, $description
                ]);

                logActivity(
                    $conn,
                    "Thêm linh kiện",
                    "Đã thêm linh kiện {$name}, mã {$code}, số lượng {$quantity} {$unit}."
                );

                $message = "Thêm linh kiện thành công!";

            } else {
                $oldStmt = $conn->prepare("SELECT * FROM components WHERE id = ?");
                $oldStmt->execute([$id]);
                $old = $oldStmt->fetch();

                if (!$old) {
                    throw new Exception("Không tìm thấy linh kiện cần sửa.");
                }

                $stmt = $conn->prepare("
                    UPDATE components SET
                        component_code = ?,
                        component_name = ?,
                        category = ?,
                        quantity = ?,
                        min_quantity = ?,
                        unit = ?,
                        location = ?,
                        status = ?,
                        description = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $code, $name, $category, $quantity,
                    $minQuantity, $unit, $location, $status,
                    $description, $id
                ]);

                logActivity(
                    $conn,
                    "Sửa linh kiện",
                    "Đã cập nhật linh kiện {$name}, mã {$code}, ID {$id}."
                );

                $message = "Cập nhật linh kiện thành công!";
            }

        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            $oldStmt = $conn->prepare("SELECT * FROM components WHERE id = ?");
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetch();

            if (!$old) {
                throw new Exception("Không tìm thấy linh kiện cần xóa.");
            }

            $stmt = $conn->prepare("DELETE FROM components WHERE id = ?");
            $stmt->execute([$id]);

            logActivity(
                $conn,
                "Xóa linh kiện",
                "Đã xóa linh kiện {$old['component_name']}, mã {$old['component_code']}."
            );

            $message = "Xóa linh kiện thành công!";
        }

    } catch (PDOException $e) {
        $messageType = "error";

        if ($e->getCode() === "23000") {
            $message = $action === "delete"
                ? "Không thể xóa linh kiện vì đã có lịch sử nhập xuất kho."
                : "Mã linh kiện đã tồn tại. Hãy nhập mã khác.";
        } else {
            $message = "Có lỗi cơ sở dữ liệu. Vui lòng kiểm tra lại.";
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// LẤY DỮ LIỆU CHỈNH SỬA
$editComponent = null;

if (isset($_GET["edit"])) {
    $stmt = $conn->prepare("SELECT * FROM components WHERE id = ?");
    $stmt->execute([(int)$_GET["edit"]]);
    $editComponent = $stmt->fetch();
}

// TÌM KIẾM VÀ LỌC
$keyword = trim($_GET["keyword"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$stockFilter = $_GET["stock"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(component_code LIKE ? OR component_name LIKE ? OR category LIKE ? OR location LIKE ?)";
    $search = "%{$keyword}%";
    array_push($params, $search, $search, $search, $search);
}

if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}

if ($stockFilter === "low") {
    $where[] = "quantity <= min_quantity";
} elseif ($stockFilter === "normal") {
    $where[] = "quantity > min_quantity";
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// THỐNG KÊ
$totalComponents = (int)$conn->query("SELECT COUNT(*) FROM components")->fetchColumn();

$totalQuantity = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0) FROM components
")->fetchColumn();

$lowStockCount = (int)$conn->query("
    SELECT COUNT(*) FROM components WHERE quantity <= min_quantity
")->fetchColumn();

$activeCount = (int)$conn->query("
    SELECT COUNT(*) FROM components WHERE status = 'Đang sử dụng'
")->fetchColumn();

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("SELECT COUNT(*) FROM components $whereSQL");
$countStmt->execute($params);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT * FROM components
    $whereSQL
    ORDER BY id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$components = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý linh kiện - EQUIP CONTROL</title>

<link rel="stylesheet" href="/equip-control/assets/css/unified.css">

<style>
* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    background: #0b1120;
    color: #e5edf8;
}

/* SIDEBAR CỐ ĐỊNH BÊN TRÁI */
.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    overflow-y: auto !important;
    z-index: 1000 !important;
    margin: 0 !important;
}

/* NỘI DUNG LUÔN NẰM BÊN PHẢI SIDEBAR */
.main-content {
    position: relative !important;
    display: block !important;
    margin-left: 250px !important;
    margin-top: 0 !important;
    width: calc(100% - 250px) !important;
    min-width: 0 !important;
    min-height: 100vh;
    padding: 0 !important;
    box-sizing: border-box !important;
}

.component-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
    box-sizing: border-box;
}

.component-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.component-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.component-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.component-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 19px;
    min-width: 0;
}

.component-stat span {
    color: #91a6bd;
    font-size: 12px;
    display: block;
    margin-bottom: 10px;
}

.component-stat strong {
    color: #22d3ee;
    font-size: 25px;
}

.component-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    width: 100%;
    min-width: 0;
}

.component-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.component-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.component-field {
    min-width: 0;
}

.component-field label {
    display: block;
    color: #a9bfd5;
    font-size: 12px;
    margin-bottom: 7px;
}

.component-field input,
.component-field select,
.component-field textarea {
    width: 100%;
    min-width: 0;
    padding: 11px 12px;
    background: #0b1726;
    color: #e5edf8;
    border: 1px solid #304963;
    border-radius: 7px;
    outline: none;
    font-size: 13px;
}

.component-field input:focus,
.component-field select:focus,
.component-field textarea:focus {
    border-color: #22d3ee;
}

.component-field textarea {
    min-height: 75px;
    resize: vertical;
}

.component-field.full {
    grid-column: 1 / -1;
}

.component-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 17px;
}

.component-actions form {
    margin: 0;
}

.component-btn {
    border: 0;
    border-radius: 7px;
    padding: 10px 15px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
}

.btn-primary {
    background: #0891b2;
    color: white;
}

.btn-secondary {
    background: #30445c;
    color: white;
}

.btn-edit {
    background: #174b72;
    color: #bae6fd;
}

.btn-delete {
    background: #7f1d1d;
    color: #fecaca;
}

.component-search {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.component-search input,
.component-search select {
    padding: 10px 12px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
}

.component-search input {
    flex: 1;
    min-width: 200px;
}

.component-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.component-table {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
    white-space: nowrap;
}

.component-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c;
    color: #22d3ee;
    font-size: 12px;
}

.component-table td {
    padding: 13px 11px;
    border-bottom: 1px solid #263b52;
    color: #d4e1ef;
    font-size: 12px;
}

.component-table tbody tr:hover {
    background: rgba(34, 211, 238, .04);
}

.component-status,
.stock-status {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 20px;
    font-size: 10px;
    white-space: nowrap;
}

.status-active,
.stock-normal {
    background: #123c32;
    color: #86efac;
}

.status-inactive {
    background: #45252d;
    color: #fca5a5;
}

.stock-low {
    background: #49371e;
    color: #fcd34d;
}

.component-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
}

.component-alert.success {
    color: #86efac;
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
}

.component-alert.error {
    color: #fca5a5;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
}

.component-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.component-pagination a {
    padding: 8px 12px;
    border-radius: 6px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    font-size: 12px;
}

.component-pagination .current {
    background: #0891b2;
    color: white;
}

.component-empty {
    text-align: center;
    padding: 30px !important;
    color: #91a6bd !important;
}

@media(max-width: 950px) {
    .component-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 768px) {
    .sidebar {
        width: 220px !important;
    }

    .main-content {
        margin-left: 220px !important;
        width: calc(100% - 220px) !important;
    }

    .component-content {
        padding: 16px;
    }
}

@media(max-width: 600px) {
    .component-form-grid,
    .component-stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
<div class="component-content">

    <h1 class="component-heading">QUẢN LÝ LINH KIỆN</h1>
    <p class="component-subtitle">
        Theo dõi số lượng, tình trạng và thông tin linh kiện trong kho.
    </p>

    <?php if ($message !== ""): ?>
        <div class="component-alert <?= componentEscape($messageType) ?>">
            <?= componentEscape($message) ?>
        </div>
    <?php endif; ?>

    <div class="component-stats">
        <div class="component-stat">
            <span>Tổng loại linh kiện</span>
            <strong><?= number_format($totalComponents) ?></strong>
        </div>

        <div class="component-stat">
            <span>Tổng số lượng tồn</span>
            <strong><?= number_format($totalQuantity) ?></strong>
        </div>

        <div class="component-stat">
            <span>Linh kiện sắp hết</span>
            <strong><?= number_format($lowStockCount) ?></strong>
        </div>

        <div class="component-stat">
            <span>Đang sử dụng</span>
            <strong><?= number_format($activeCount) ?></strong>
        </div>
    </div>

    <section class="component-panel">
        <h3>
            <?= $editComponent ? "CẬP NHẬT LINH KIỆN" : "THÊM LINH KIỆN MỚI" ?>
        </h3>

        <form method="POST">
            <input type="hidden" name="action"
                   value="<?= $editComponent ? "update" : "add" ?>">

            <?php if ($editComponent): ?>
                <input type="hidden" name="id"
                       value="<?= (int)$editComponent["id"] ?>">
            <?php endif; ?>

            <div class="component-form-grid">

                <div class="component-field">
                    <label>Mã linh kiện *</label>
                    <input type="text" name="component_code" required
                        value="<?= componentEscape($editComponent["component_code"] ?? "") ?>"
                        placeholder="Ví dụ: LK001">
                </div>

                <div class="component-field">
                    <label>Tên linh kiện *</label>
                    <input type="text" name="component_name" required
                        value="<?= componentEscape($editComponent["component_name"] ?? "") ?>"
                        placeholder="Nhập tên linh kiện">
                </div>

                <div class="component-field">
                    <label>Danh mục</label>
                    <input type="text" name="category"
                        value="<?= componentEscape($editComponent["category"] ?? "") ?>"
                        placeholder="Ví dụ: Điện trở, tụ điện...">
                </div>

                <div class="component-field">
                    <label>Số lượng tồn kho</label>
                    <input type="number" name="quantity" min="0"
                        value="<?= componentEscape($editComponent["quantity"] ?? 0) ?>">
                </div>

                <div class="component-field">
                    <label>Số lượng tối thiểu</label>
                    <input type="number" name="min_quantity" min="0"
                        value="<?= componentEscape($editComponent["min_quantity"] ?? 5) ?>">
                </div>

                <div class="component-field">
                    <label>Đơn vị</label>
                    <input type="text" name="unit"
                        value="<?= componentEscape($editComponent["unit"] ?? "Cái") ?>"
                        placeholder="Cái, Bộ, Chiếc...">
                </div>

                <div class="component-field">
                    <label>Vị trí lưu trữ</label>
                    <input type="text" name="location"
                        value="<?= componentEscape($editComponent["location"] ?? "") ?>"
                        placeholder="Ví dụ: Kệ A, Ngăn B...">
                </div>

                <div class="component-field">
                    <label>Trạng thái</label>
                    <select name="status">
                        <?php
                        $currentStatus = $editComponent["status"] ?? "Đang sử dụng";
                        foreach ($validStatuses as $status):
                        ?>
                            <option value="<?= componentEscape($status) ?>"
                                <?= $currentStatus === $status ? "selected" : "" ?>>
                                <?= componentEscape($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="component-field full">
                    <label>Mô tả</label>
                    <textarea name="description"
                        placeholder="Nhập thông tin mô tả..."><?= componentEscape($editComponent["description"] ?? "") ?></textarea>
                </div>

            </div>

            <div class="component-actions">
                <button type="submit" class="component-btn btn-primary">
                    <?= $editComponent ? "LƯU CẬP NHẬT" : "THÊM LINH KIỆN" ?>
                </button>

                <?php if ($editComponent): ?>
                    <a href="/equip-control/components.php"
                       class="component-btn btn-secondary">
                        HỦY CHỈNH SỬA
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="component-panel">
        <h3>DANH SÁCH LINH KIỆN</h3>

        <form method="GET" class="component-search">
            <input type="text" name="keyword"
                value="<?= componentEscape($keyword) ?>"
                placeholder="Tìm mã, tên, danh mục hoặc vị trí...">

            <select name="status">
                <option value="">Tất cả trạng thái</option>
                <?php foreach ($validStatuses as $status): ?>
                    <option value="<?= componentEscape($status) ?>"
                        <?= $statusFilter === $status ? "selected" : "" ?>>
                        <?= componentEscape($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="stock">
                <option value="">Tất cả tồn kho</option>
                <option value="low" <?= $stockFilter === "low" ? "selected" : "" ?>>
                    Sắp hết hàng
                </option>
                <option value="normal" <?= $stockFilter === "normal" ? "selected" : "" ?>>
                    Còn hàng
                </option>
            </select>

            <button type="submit" class="component-btn btn-primary">
                TÌM KIẾM
            </button>

            <a href="/equip-control/components.php"
               class="component-btn btn-secondary">
                LÀM MỚI
            </a>
        </form>

        <div class="component-table-wrap">
            <table class="component-table">
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã linh kiện</th>
                        <th>Tên linh kiện</th>
                        <th>Danh mục</th>
                        <th>Số lượng</th>
                        <th>Mức tối thiểu</th>
                        <th>Vị trí</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($components): ?>
                    <?php foreach ($components as $index => $component): ?>
                        <?php
                        $isLow = (int)$component["quantity"] <= (int)$component["min_quantity"];
                        $isActive = $component["status"] === "Đang sử dụng";
                        ?>

                        <tr>
                            <td><?= $offset + $index + 1 ?></td>
                            <td><?= componentEscape($component["component_code"]) ?></td>
                            <td><?= componentEscape($component["component_name"]) ?></td>
                            <td><?= componentEscape($component["category"]) ?></td>
                            <td>
                                <?= number_format((int)$component["quantity"]) ?>
                                <?= componentEscape($component["unit"]) ?>
                            </td>
                            <td><?= number_format((int)$component["min_quantity"]) ?></td>
                            <td><?= componentEscape($component["location"]) ?></td>
                            <td>
                                <span class="stock-status <?= $isLow ? "stock-low" : "stock-normal" ?>">
                                    <?= $isLow ? "Sắp hết hàng" : "Đủ hàng" ?>
                                </span>
                                <br>
                                <span class="component-status <?= $isActive ? "status-active" : "status-inactive" ?>">
                                    <?= componentEscape($component["status"]) ?>
                                </span>
                            </td>
                            <td>
                                <div class="component-actions">
                                    <a class="component-btn btn-edit"
                                       href="?edit=<?= (int)$component["id"] ?>">
                                        Sửa
                                    </a>

                                    <form method="POST"
                                        onsubmit="return confirm('Mày có chắc chắn muốn xóa linh kiện này không?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$component["id"] ?>">
                                        <button type="submit"
                                            class="component-btn btn-delete">
                                            Xóa
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="component-empty">
                            Không tìm thấy linh kiện phù hợp.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="component-pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php
                    $query = $_GET;
                    $query["page"] = $i;
                    ?>
                    <a href="?<?= componentEscape(http_build_query($query)) ?>"
                       class="<?= $page === $i ? "current" : "" ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <p style="color:#91a6bd;font-size:12px;margin-top:15px;">
            Hiển thị <?= count($components) ?> / <?= $filteredTotal ?> linh kiện.
        </p>
    </section>

</div>
</main>

</body>
</html>
