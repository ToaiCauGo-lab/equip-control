
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function borrowEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";

// XỬ LÝ MƯỢN VÀ TRẢ THIẾT BỊ
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {

        // =========================
        // TẠO PHIẾU MƯỢN
        // =========================
        if ($action === "borrow") {

            $deviceId = (int)($_POST["device_id"] ?? 0);
            $borrowerName = trim($_POST["borrower_name"] ?? "");
            $borrowerContact = trim($_POST["borrower_contact"] ?? "");
            $quantity = (int)($_POST["quantity"] ?? 0);
            $borrowDate = $_POST["borrow_date"] ?? "";
            $expectedDate = $_POST["expected_return_date"] ?? "";
            $note = trim($_POST["note"] ?? "");

            if ($deviceId <= 0 || $borrowerName === "") {
                throw new Exception("Vui lòng chọn thiết bị và nhập người mượn.");
            }

            if ($quantity <= 0) {
                throw new Exception("Số lượng mượn phải lớn hơn 0.");
            }

            if (!$borrowDate || !$expectedDate) {
                throw new Exception("Vui lòng chọn ngày mượn và ngày dự kiến trả.");
            }

            if ($expectedDate < $borrowDate) {
                throw new Exception("Ngày dự kiến trả không được trước ngày mượn.");
            }

            $conn->beginTransaction();

            // Khóa thiết bị trong quá trình xử lý
            $stmt = $conn->prepare("
                SELECT id, device_code, device_name, quantity, unit, status
                FROM devices
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$deviceId]);

            $device = $stmt->fetch();

            if (!$device) {
                throw new Exception("Không tìm thấy thiết bị.");
            }

            if ($device["status"] !== "Đang sử dụng") {
                throw new Exception("Thiết bị hiện không ở trạng thái có thể cho mượn.");
            }

            $availableQuantity = (int)$device["quantity"];

            if ($quantity > $availableQuantity) {
                throw new Exception(
                    "Không đủ thiết bị để cho mượn. Số lượng hiện có: "
                    . $availableQuantity . " " . $device["unit"] . "."
                );
            }

            // Trừ số lượng thiết bị
            $update = $conn->prepare("
                UPDATE devices
                SET quantity = quantity - ?
                WHERE id = ?
            ");
            $update->execute([$quantity, $deviceId]);

            // Tạo phiếu mượn
            $insert = $conn->prepare("
                INSERT INTO borrow_records
                (
                    device_id,
                    borrower_name,
                    borrower_contact,
                    quantity,
                    borrow_date,
                    expected_return_date,
                    status,
                    note
                )
                VALUES (?, ?, ?, ?, ?, ?, 'Đang mượn', ?)
            ");

            $insert->execute([
                $deviceId,
                $borrowerName,
                $borrowerContact,
                $quantity,
                $borrowDate,
                $expectedDate,
                $note
            ]);

            $borrowId = (int)$conn->lastInsertId();

            $conn->commit();

            // GHI NHẬT KÝ
            logActivity(
                $conn,
                "Mượn thiết bị",
                "Tạo phiếu mượn #{$borrowId}. Người mượn: {$borrowerName}. "
                . "Thiết bị: {$device['device_name']} "
                . "(Mã: {$device['device_code']}), số lượng: {$quantity} "
                . "{$device['unit']}. Ngày mượn: {$borrowDate}, "
                . "dự kiến trả: {$expectedDate}."
            );

            $message = "Tạo phiếu mượn thiết bị thành công!";

        // =========================
        // XÁC NHẬN TRẢ THIẾT BỊ
        // =========================
        } elseif ($action === "return") {

            $borrowId = (int)($_POST["borrow_id"] ?? 0);
            $actualReturnDate = $_POST["actual_return_date"] ?? date("Y-m-d");

            if ($borrowId <= 0) {
                throw new Exception("Phiếu mượn không hợp lệ.");
            }

            if (!$actualReturnDate) {
                throw new Exception("Vui lòng chọn ngày trả.");
            }

            $conn->beginTransaction();

            // Khóa phiếu mượn
            $stmt = $conn->prepare("
                SELECT *
                FROM borrow_records
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$borrowId]);

            $borrow = $stmt->fetch();

            if (!$borrow) {
                throw new Exception("Không tìm thấy phiếu mượn.");
            }

            if ($borrow["status"] !== "Đang mượn") {
                throw new Exception("Phiếu này đã được trả trước đó.");
            }

            if ($actualReturnDate < $borrow["borrow_date"]) {
                throw new Exception("Ngày trả không được trước ngày mượn.");
            }

            // Khóa thiết bị
            $deviceStmt = $conn->prepare("
                SELECT id, device_code, device_name, unit
                FROM devices
                WHERE id = ?
                FOR UPDATE
            ");
            $deviceStmt->execute([$borrow["device_id"]]);

            $device = $deviceStmt->fetch();

            if (!$device) {
                throw new Exception("Không tìm thấy thiết bị liên quan đến phiếu mượn.");
            }

            // Cộng lại số lượng thiết bị
            $updateDevice = $conn->prepare("
                UPDATE devices
                SET quantity = quantity + ?
                WHERE id = ?
            ");
            $updateDevice->execute([
                (int)$borrow["quantity"],
                (int)$borrow["device_id"]
            ]);

            // Cập nhật phiếu mượn
            $updateBorrow = $conn->prepare("
                UPDATE borrow_records
                SET
                    status = 'Đã trả',
                    actual_return_date = ?
                WHERE id = ?
            ");
            $updateBorrow->execute([
                $actualReturnDate,
                $borrowId
            ]);

            $conn->commit();

            // GHI NHẬT KÝ
            logActivity(
                $conn,
                "Trả thiết bị",
                "Hoàn tất phiếu mượn #{$borrowId}. Người mượn: "
                . "{$borrow['borrower_name']}. Thiết bị: {$device['device_name']} "
                . "(Mã: {$device['device_code']}), số lượng trả: "
                . "{$borrow['quantity']} {$device['unit']}. "
                . "Ngày trả thực tế: {$actualReturnDate}."
            );

            $message = "Xác nhận trả thiết bị thành công!";

        } else {
            throw new Exception("Thao tác không hợp lệ.");
        }

    } catch (PDOException $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = "Có lỗi cơ sở dữ liệu. Vui lòng kiểm tra lại.";
        $messageType = "error";

    } catch (Exception $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = $e->getMessage();
        $messageType = "error";
    }
}

// DANH SÁCH THIẾT BỊ
$deviceStmt = $conn->query("
    SELECT id, device_code, device_name, quantity, unit, status
    FROM devices
    ORDER BY device_name ASC
");
$devices = $deviceStmt->fetchAll();

// THỐNG KÊ
$totalBorrowing = (int)$conn->query("
    SELECT COUNT(*)
    FROM borrow_records
    WHERE status = 'Đang mượn'
")->fetchColumn();

$totalReturned = (int)$conn->query("
    SELECT COUNT(*)
    FROM borrow_records
    WHERE status = 'Đã trả'
")->fetchColumn();

$totalRecords = (int)$conn->query("
    SELECT COUNT(*) FROM borrow_records
")->fetchColumn();

$overdueCount = (int)$conn->query("
    SELECT COUNT(*)
    FROM borrow_records
    WHERE status = 'Đang mượn'
    AND expected_return_date < CURDATE()
")->fetchColumn();

// TÌM KIẾM VÀ LỌC
$keyword = trim($_GET["keyword"] ?? "");
$statusFilter = $_GET["status"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(
        b.borrower_name LIKE ?
        OR b.borrower_contact LIKE ?
        OR d.device_code LIKE ?
        OR d.device_name LIKE ?
    )";

    $search = "%{$keyword}%";

    array_push(
        $params,
        $search,
        $search,
        $search,
        $search
    );
}

if (in_array($statusFilter, ["Đang mượn", "Đã trả"], true)) {
    $where[] = "b.status = ?";
    $params[] = $statusFilter;
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM borrow_records b
    INNER JOIN devices d ON b.device_id = d.id
    $whereSQL
");
$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// LỊCH SỬ MƯỢN TRẢ
$stmt = $conn->prepare("
    SELECT
        b.*,
        d.device_code,
        d.device_name,
        d.unit
    FROM borrow_records b
    INNER JOIN devices d ON b.device_id = d.id
    $whereSQL
    ORDER BY b.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$borrowRecords = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý mượn trả - EQUIP CONTROL</title>

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

/* SIDEBAR */
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

/* NỘI DUNG */
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

.borrow-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
}

.borrow-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.borrow-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.borrow-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.borrow-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 19px;
    min-width: 0;
}

.borrow-stat span {
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.borrow-stat strong {
    color: #22d3ee;
    font-size: 25px;
}

.borrow-stat.overdue strong {
    color: #fbbf24;
}

.borrow-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    width: 100%;
    min-width: 0;
}

.borrow-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.borrow-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.borrow-field {
    min-width: 0;
}

.borrow-field label {
    display: block;
    color: #a9bfd5;
    font-size: 12px;
    margin-bottom: 7px;
}

.borrow-field input,
.borrow-field select,
.borrow-field textarea {
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

.borrow-field input:focus,
.borrow-field select:focus,
.borrow-field textarea:focus {
    border-color: #22d3ee;
}

.borrow-field textarea {
    min-height: 75px;
    resize: vertical;
}

.borrow-field.full {
    grid-column: 1 / -1;
}

.borrow-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 17px;
}

.borrow-actions form {
    margin: 0;
}

.borrow-btn {
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

.borrow-btn-primary {
    background: #0891b2;
    color: white;
}

.borrow-btn-secondary {
    background: #30445c;
    color: white;
}

.borrow-btn-return {
    background: #14532d;
    color: #bbf7d0;
}

.borrow-search {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.borrow-search input,
.borrow-search select {
    padding: 10px 12px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
}

.borrow-search input {
    flex: 1;
    min-width: 200px;
}

.borrow-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.borrow-table {
    width: 100%;
    min-width: 1100px;
    border-collapse: collapse;
    white-space: nowrap;
}

.borrow-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c;
    color: #22d3ee;
    font-size: 12px;
}

.borrow-table td {
    padding: 13px 11px;
    border-bottom: 1px solid #263b52;
    color: #d4e1ef;
    font-size: 12px;
}

.borrow-table tbody tr:hover {
    background: rgba(34, 211, 238, .04);
}

.borrow-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.borrowing {
    background: #49371e;
    color: #fcd34d;
}

.returned {
    background: #123c32;
    color: #86efac;
}

.borrow-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
}

.borrow-alert.success {
    color: #86efac;
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
}

.borrow-alert.error {
    color: #fca5a5;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
}

.borrow-overdue {
    color: #fbbf24 !important;
    font-weight: 600;
}

.borrow-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.borrow-pagination a {
    padding: 8px 12px;
    border-radius: 6px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    font-size: 12px;
}

.borrow-pagination .current {
    background: #0891b2;
    color: white;
}

.borrow-empty {
    text-align: center;
    padding: 30px !important;
    color: #91a6bd !important;
}

.borrow-note {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 14px;
}

@media(max-width: 950px) {
    .borrow-stats {
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

    .borrow-content {
        padding: 16px;
    }
}

@media(max-width: 600px) {
    .borrow-form-grid,
    .borrow-stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
<div class="borrow-content">

    <h1 class="borrow-heading">QUẢN LÝ MƯỢN TRẢ THIẾT BỊ</h1>

    <p class="borrow-subtitle">
        Theo dõi phiếu mượn, người mượn và tình trạng trả thiết bị.
    </p>

    <?php if ($message !== ""): ?>
        <div class="borrow-alert <?= borrowEscape($messageType) ?>">
            <?= borrowEscape($message) ?>
        </div>
    <?php endif; ?>

    <div class="borrow-stats">

        <div class="borrow-stat">
            <span>Phiếu đang mượn</span>
            <strong><?= number_format($totalBorrowing) ?></strong>
        </div>

        <div class="borrow-stat">
            <span>Phiếu đã trả</span>
            <strong><?= number_format($totalReturned) ?></strong>
        </div>

        <div class="borrow-stat">
            <span>Tổng số phiếu</span>
            <strong><?= number_format($totalRecords) ?></strong>
        </div>

        <div class="borrow-stat overdue">
            <span>Phiếu quá hạn</span>
            <strong><?= number_format($overdueCount) ?></strong>
        </div>

    </div>

    <section class="borrow-panel">

        <h3>TẠO PHIẾU MƯỢN THIẾT BỊ</h3>

        <form method="POST">

            <input type="hidden" name="action" value="borrow">

            <div class="borrow-form-grid">

                <div class="borrow-field">
                    <label>Chọn thiết bị *</label>

                    <select name="device_id" required>
                        <option value="">-- Chọn thiết bị --</option>

                        <?php foreach ($devices as $device): ?>
                            <option
                                value="<?= (int)$device["id"] ?>"
                                <?= $device["status"] !== "Đang sử dụng" || (int)$device["quantity"] <= 0 ? "disabled" : "" ?>
                            >
                                <?= borrowEscape($device["device_code"]) ?>
                                -
                                <?= borrowEscape($device["device_name"]) ?>
                                (Còn <?= (int)$device["quantity"] ?>
                                <?= borrowEscape($device["unit"]) ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="borrow-field">
                    <label>Người mượn *</label>
                    <input type="text" name="borrower_name" required
                           placeholder="Nhập họ tên người mượn">
                </div>

                <div class="borrow-field">
                    <label>Số điện thoại / Liên hệ</label>
                    <input type="text" name="borrower_contact"
                           placeholder="Nhập số điện thoại hoặc thông tin liên hệ">
                </div>

                <div class="borrow-field">
                    <label>Số lượng mượn *</label>
                    <input type="number" name="quantity" min="1"
                           value="1" required>
                </div>

                <div class="borrow-field">
                    <label>Ngày mượn *</label>
                    <input type="date" name="borrow_date"
                           value="<?= date("Y-m-d") ?>" required>
                </div>

                <div class="borrow-field">
                    <label>Ngày dự kiến trả *</label>
                    <input type="date" name="expected_return_date"
                           value="<?= date("Y-m-d", strtotime("+7 days")) ?>" required>
                </div>

                <div class="borrow-field full">
                    <label>Ghi chú</label>
                    <textarea name="note"
                              placeholder="Nhập mục đích mượn hoặc ghi chú..."></textarea>
                </div>

            </div>

            <div class="borrow-actions">
                <button type="submit" class="borrow-btn borrow-btn-primary">
                    TẠO PHIẾU MƯỢN
                </button>
            </div>

        </form>

    </section>

    <section class="borrow-panel">

        <h3>DANH SÁCH PHIẾU MƯỢN TRẢ</h3>

        <form method="GET" class="borrow-search">

            <input type="text" name="keyword"
                   value="<?= borrowEscape($keyword) ?>"
                   placeholder="Tìm người mượn, số điện thoại, mã hoặc tên thiết bị...">

            <select name="status">
                <option value="">Tất cả trạng thái</option>

                <option value="Đang mượn"
                    <?= $statusFilter === "Đang mượn" ? "selected" : "" ?>>
                    Đang mượn
                </option>

                <option value="Đã trả"
                    <?= $statusFilter === "Đã trả" ? "selected" : "" ?>>
                    Đã trả
                </option>
            </select>

            <button type="submit" class="borrow-btn borrow-btn-primary">
                TÌM KIẾM
            </button>

            <a href="/equip-control/borrow.php"
               class="borrow-btn borrow-btn-secondary">
                LÀM MỚI
            </a>

        </form>

        <div class="borrow-table-wrap">

            <table class="borrow-table">

                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã phiếu</th>
                        <th>Thiết bị</th>
                        <th>Người mượn</th>
                        <th>Liên hệ</th>
                        <th>Số lượng</th>
                        <th>Ngày mượn</th>
                        <th>Dự kiến trả</th>
                        <th>Ngày trả thực tế</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($borrowRecords): ?>

                    <?php foreach ($borrowRecords as $index => $record): ?>

                        <?php
                        $isBorrowing = $record["status"] === "Đang mượn";

                        $isOverdue = $isBorrowing
                            && $record["expected_return_date"] < date("Y-m-d");
                        ?>

                        <tr>

                            <td><?= $offset + $index + 1 ?></td>

                            <td>#<?= (int)$record["id"] ?></td>

                            <td>
                                <?= borrowEscape($record["device_name"]) ?>
                                <br>
                                <small style="color:#91a6bd">
                                    <?= borrowEscape($record["device_code"]) ?>
                                </small>
                            </td>

                            <td><?= borrowEscape($record["borrower_name"]) ?></td>

                            <td><?= borrowEscape($record["borrower_contact"]) ?></td>

                            <td>
                                <?= (int)$record["quantity"] ?>
                                <?= borrowEscape($record["unit"]) ?>
                            </td>

                            <td>
                                <?= date("d/m/Y", strtotime($record["borrow_date"])) ?>
                            </td>

                            <td class="<?= $isOverdue ? "borrow-overdue" : "" ?>">
                                <?= date("d/m/Y", strtotime($record["expected_return_date"])) ?>

                                <?php if ($isOverdue): ?>
                                    <br>
                                    <small>Quá hạn</small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= $record["actual_return_date"]
                                    ? date("d/m/Y", strtotime($record["actual_return_date"]))
                                    : "Chưa trả" ?>
                            </td>

                            <td>
                                <span class="borrow-status <?= $isBorrowing ? "borrowing" : "returned" ?>">
                                    <?= borrowEscape($record["status"]) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($isBorrowing): ?>

                                    <form method="POST"
                                        onsubmit="return confirm('Xác nhận người này đã trả thiết bị?');">

                                        <input type="hidden" name="action" value="return">

                                        <input type="hidden" name="borrow_id"
                                               value="<?= (int)$record["id"] ?>">

                                        <input type="hidden" name="actual_return_date"
                                               value="<?= date("Y-m-d") ?>">

                                        <button type="submit"
                                            class="borrow-btn borrow-btn-return">
                                            XÁC NHẬN TRẢ
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span style="color:#86efac;font-size:12px">
                                        Đã hoàn tất
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="11" class="borrow-empty">
                            Chưa có phiếu mượn phù hợp.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="borrow-pagination">

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                    <?php
                    $query = $_GET;
                    $query["page"] = $i;
                    ?>

                    <a href="?<?= borrowEscape(http_build_query($query)) ?>"
                       class="<?= $page === $i ? "current" : "" ?>">
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

            </div>

        <?php endif; ?>

        <p class="borrow-note">
            Hiển thị <?= count($borrowRecords) ?> / <?= $filteredTotal ?> phiếu mượn.
        </p>

    </section>

</div>
</main>

</body>
</html>
