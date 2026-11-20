
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function maintenanceEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        // THÊM PHIẾU BẢO TRÌ
        if ($action === "add") {
            $deviceId = (int)($_POST["device_id"] ?? 0);
            $problem = trim($_POST["problem_description"] ?? "");
            $maintenanceDate = $_POST["maintenance_date"] ?? "";
            $expectedEndDate = $_POST["expected_end_date"] ?? "";
            $cost = (float)($_POST["maintenance_cost"] ?? 0);
            $technician = trim($_POST["technician"] ?? "");
            $note = trim($_POST["note"] ?? "");

            if ($deviceId <= 0 || $problem === "" || !$maintenanceDate) {
                throw new Exception("Vui lòng nhập đầy đủ thông tin bắt buộc.");
            }

            if ($cost < 0) {
                throw new Exception("Chi phí bảo trì không được âm.");
            }

            if ($expectedEndDate && $expectedEndDate < $maintenanceDate) {
                throw new Exception("Ngày dự kiến hoàn thành không được trước ngày bắt đầu.");
            }

            $conn->beginTransaction();

            $deviceStmt = $conn->prepare("
                SELECT id, device_code, device_name, status
                FROM devices
                WHERE id = ?
                FOR UPDATE
            ");
            $deviceStmt->execute([$deviceId]);
            $device = $deviceStmt->fetch();

            if (!$device) {
                throw new Exception("Không tìm thấy thiết bị.");
            }

            if ($device["status"] !== "Đang sử dụng") {
                throw new Exception("Thiết bị không ở trạng thái có thể bắt đầu bảo trì.");
            }

            $insert = $conn->prepare("
                INSERT INTO maintenance_records
                (
                    device_id,
                    problem_description,
                    maintenance_date,
                    expected_end_date,
                    maintenance_cost,
                    status,
                    technician,
                    note
                )
                VALUES (?, ?, ?, ?, ?, 'Đang bảo trì', ?, ?)
            ");

            $insert->execute([
                $deviceId,
                $problem,
                $maintenanceDate,
                $expectedEndDate ?: null,
                $cost,
                $technician,
                $note
            ]);

            $maintenanceId = (int)$conn->lastInsertId();

            $updateDevice = $conn->prepare("
                UPDATE devices
                SET status = 'Đang bảo trì'
                WHERE id = ?
            ");
            $updateDevice->execute([$deviceId]);

            $conn->commit();

            logActivity(
                $conn,
                "Thêm phiếu bảo trì",
                "Tạo phiếu bảo trì #{$maintenanceId} cho thiết bị "
                . "{$device['device_name']} (Mã: {$device['device_code']}). "
                . "Nội dung: {$problem}. Kỹ thuật viên: {$technician}. "
                . "Chi phí dự kiến/ghi nhận: " . number_format($cost) . " VNĐ."
            );

            $message = "Tạo phiếu bảo trì thành công!";

        // CẬP NHẬT PHIẾU BẢO TRÌ
        } elseif ($action === "update") {
            $maintenanceId = (int)($_POST["maintenance_id"] ?? 0);
            $problem = trim($_POST["problem_description"] ?? "");
            $expectedEndDate = $_POST["expected_end_date"] ?? "";
            $cost = (float)($_POST["maintenance_cost"] ?? 0);
            $technician = trim($_POST["technician"] ?? "");
            $note = trim($_POST["note"] ?? "");

            if ($maintenanceId <= 0 || $problem === "") {
                throw new Exception("Thông tin phiếu bảo trì không hợp lệ.");
            }

            if ($cost < 0) {
                throw new Exception("Chi phí bảo trì không được âm.");
            }

            $stmt = $conn->prepare("
                SELECT *
                FROM maintenance_records
                WHERE id = ?
            ");
            $stmt->execute([$maintenanceId]);
            $record = $stmt->fetch();

            if (!$record) {
                throw new Exception("Không tìm thấy phiếu bảo trì.");
            }

            if ($record["status"] !== "Đang bảo trì") {
                throw new Exception("Chỉ có thể cập nhật phiếu đang bảo trì.");
            }

            if (
                $expectedEndDate &&
                $expectedEndDate < $record["maintenance_date"]
            ) {
                throw new Exception("Ngày dự kiến hoàn thành không hợp lệ.");
            }

            $update = $conn->prepare("
                UPDATE maintenance_records
                SET
                    problem_description = ?,
                    expected_end_date = ?,
                    maintenance_cost = ?,
                    technician = ?,
                    note = ?
                WHERE id = ?
                AND status = 'Đang bảo trì'
            ");

            $update->execute([
                $problem,
                $expectedEndDate ?: null,
                $cost,
                $technician,
                $note,
                $maintenanceId
            ]);

            logActivity(
                $conn,
                "Cập nhật phiếu bảo trì",
                "Cập nhật phiếu #{$maintenanceId}. "
                . "Nội dung: {$problem}. Kỹ thuật viên: {$technician}. "
                . "Chi phí: " . number_format($cost) . " VNĐ."
            );

            $message = "Cập nhật phiếu bảo trì thành công!";

        // HOÀN THÀNH BẢO TRÌ
        } elseif ($action === "complete") {
            $maintenanceId = (int)($_POST["maintenance_id"] ?? 0);
            $actualEndDate = $_POST["actual_end_date"] ?? date("Y-m-d");

            if ($maintenanceId <= 0 || !$actualEndDate) {
                throw new Exception("Thông tin hoàn thành bảo trì không hợp lệ.");
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT *
                FROM maintenance_records
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$maintenanceId]);
            $record = $stmt->fetch();

            if (!$record) {
                throw new Exception("Không tìm thấy phiếu bảo trì.");
            }

            if ($record["status"] !== "Đang bảo trì") {
                throw new Exception("Phiếu bảo trì này đã hoàn thành trước đó.");
            }

            if ($actualEndDate < $record["maintenance_date"]) {
                throw new Exception("Ngày hoàn thành không được trước ngày bắt đầu.");
            }

            $deviceStmt = $conn->prepare("
                SELECT id, device_code, device_name
                FROM devices
                WHERE id = ?
                FOR UPDATE
            ");
            $deviceStmt->execute([$record["device_id"]]);
            $device = $deviceStmt->fetch();

            if (!$device) {
                throw new Exception("Không tìm thấy thiết bị liên quan.");
            }

            $updateRecord = $conn->prepare("
                UPDATE maintenance_records
                SET
                    status = 'Đã hoàn thành',
                    actual_end_date = ?
                WHERE id = ?
            ");
            $updateRecord->execute([
                $actualEndDate,
                $maintenanceId
            ]);

            $otherMaintenance = $conn->prepare("
                SELECT COUNT(*)
                FROM maintenance_records
                WHERE device_id = ?
                AND status = 'Đang bảo trì'
                AND id <> ?
            ");
            $otherMaintenance->execute([
                $record["device_id"],
                $maintenanceId
            ]);

            $remaining = (int)$otherMaintenance->fetchColumn();

            if ($remaining === 0) {
                $updateDevice = $conn->prepare("
                    UPDATE devices
                    SET status = 'Đang sử dụng'
                    WHERE id = ?
                ");
                $updateDevice->execute([$record["device_id"]]);
            }

            $conn->commit();

            logActivity(
                $conn,
                "Hoàn thành bảo trì",
                "Hoàn thành phiếu bảo trì #{$maintenanceId} của thiết bị "
                . "{$device['device_name']} (Mã: {$device['device_code']}). "
                . "Ngày hoàn thành: {$actualEndDate}. "
                . "Chi phí: " . number_format((float)$record["maintenance_cost"]) . " VNĐ."
            );

            $message = "Đã xác nhận hoàn thành bảo trì!";

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
    SELECT id, device_code, device_name, status
    FROM devices
    ORDER BY device_name ASC
");
$devices = $deviceStmt->fetchAll();

// THỐNG KÊ
$totalRecords = (int)$conn->query("
    SELECT COUNT(*) FROM maintenance_records
")->fetchColumn();

$totalOngoing = (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_records
    WHERE status = 'Đang bảo trì'
")->fetchColumn();

$totalCompleted = (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_records
    WHERE status = 'Đã hoàn thành'
")->fetchColumn();

$totalCost = (float)$conn->query("
    SELECT COALESCE(SUM(maintenance_cost), 0)
    FROM maintenance_records
")->fetchColumn();

// TÌM KIẾM VÀ LỌC
$keyword = trim($_GET["keyword"] ?? "");
$statusFilter = $_GET["status"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(
        d.device_code LIKE ?
        OR d.device_name LIKE ?
        OR m.problem_description LIKE ?
        OR m.technician LIKE ?
    )";

    $search = "%{$keyword}%";
    array_push($params, $search, $search, $search, $search);
}

if (in_array($statusFilter, ["Đang bảo trì", "Đã hoàn thành"], true)) {
    $where[] = "m.status = ?";
    $params[] = $statusFilter;
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM maintenance_records m
    INNER JOIN devices d ON m.device_id = d.id
    $whereSQL
");
$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// DANH SÁCH PHIẾU BẢO TRÌ
$stmt = $conn->prepare("
    SELECT
        m.*,
        d.device_code,
        d.device_name
    FROM maintenance_records m
    INNER JOIN devices d ON m.device_id = d.id
    $whereSQL
    ORDER BY m.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// PHIẾU ĐANG CHỌN ĐỂ SỬA
$editRecord = null;
$editId = (int)($_GET["edit"] ?? 0);

if ($editId > 0) {
    $editStmt = $conn->prepare("
        SELECT *
        FROM maintenance_records
        WHERE id = ?
        AND status = 'Đang bảo trì'
    ");
    $editStmt->execute([$editId]);
    $editRecord = $editStmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý bảo trì - EQUIP CONTROL</title>

<link rel="stylesheet" href="/assets/css/style.css?v=14">

<style>
* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    background: #0b1120 !important;
    color: #e5edf8;
}

body {
    display: block !important;
    overflow-x: hidden;
}

.page-container {
    display: block;
    width: 100%;
    min-height: 100vh;
    background: #0b1120;
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

/* NỘI DUNG CHÍNH */
.main-content {
    position: relative !important;
    display: block !important;
    margin-left: 250px !important;
    margin-top: 0 !important;
    width: calc(100% - 250px) !important;
    min-width: 0 !important;
    min-height: 100vh;
    padding: 0 !important;
    background: #0b1120 !important;
}

.maintenance-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
}

.maintenance-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.maintenance-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.maintenance-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.maintenance-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 19px;
    min-width: 0;
}

.maintenance-stat span {
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.maintenance-stat strong {
    color: #22d3ee;
    font-size: 23px;
}

.maintenance-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    width: 100%;
    min-width: 0;
}

.maintenance-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.maintenance-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.maintenance-field {
    min-width: 0;
}

.maintenance-field label {
    display: block;
    color: #a9bfd5;
    font-size: 12px;
    margin-bottom: 7px;
}

.maintenance-field input,
.maintenance-field select,
.maintenance-field textarea {
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

.maintenance-field input:focus,
.maintenance-field select:focus,
.maintenance-field textarea:focus {
    border-color: #22d3ee;
}

.maintenance-field textarea {
    min-height: 75px;
    resize: vertical;
}

.maintenance-field.full {
    grid-column: 1 / -1;
}

.maintenance-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 17px;
}

.maintenance-actions form {
    margin: 0;
}

.maintenance-btn {
    border: 0;
    border-radius: 7px;
    padding: 9px 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
}

.maintenance-primary {
    background: #0891b2;
    color: white;
}

.maintenance-secondary {
    background: #30445c;
    color: white;
}

.maintenance-complete {
    background: #14532d;
    color: #bbf7d0;
}

.maintenance-edit {
    background: #164e63;
    color: #a5f3fc;
}

.maintenance-search {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.maintenance-search input,
.maintenance-search select {
    padding: 10px 12px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
}

.maintenance-search input {
    flex: 1;
    min-width: 200px;
}

.maintenance-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.maintenance-table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
    white-space: nowrap;
}

.maintenance-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c;
    color: #22d3ee;
    font-size: 12px;
}

.maintenance-table td {
    padding: 13px 11px;
    border-bottom: 1px solid #263b52;
    color: #d4e1ef;
    font-size: 12px;
}

.maintenance-table tbody tr:hover {
    background: rgba(34, 211, 238, .04);
}

.maintenance-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.maintenance-ongoing {
    background: #49371e;
    color: #fcd34d;
}

.maintenance-done {
    background: #123c32;
    color: #86efac;
}

.maintenance-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
}

.maintenance-alert.success {
    color: #86efac;
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
}

.maintenance-alert.error {
    color: #fca5a5;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
}

.maintenance-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.maintenance-pagination a {
    padding: 8px 12px;
    border-radius: 6px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    font-size: 12px;
}

.maintenance-pagination .current {
    background: #0891b2;
    color: white;
}

.maintenance-empty {
    text-align: center;
    padding: 30px !important;
    color: #91a6bd !important;
}

.maintenance-note {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 14px;
}

@media(max-width: 950px) {
    .maintenance-stats {
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

    .maintenance-content {
        padding: 16px;
    }
}

@media(max-width: 600px) {
    .maintenance-form-grid,
    .maintenance-stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<div class="page-container">

    <?php require __DIR__ . "/includes/sidebar.php"; ?>

    <main class="main-content">
    <div class="maintenance-content">

        <h1 class="maintenance-heading">QUẢN LÝ BẢO TRÌ THIẾT BỊ</h1>

        <p class="maintenance-subtitle">
            Theo dõi quá trình sửa chữa, chi phí và tình trạng bảo trì thiết bị.
        </p>

        <?php if ($message !== ""): ?>
            <div class="maintenance-alert <?= maintenanceEscape($messageType) ?>">
                <?= maintenanceEscape($message) ?>
            </div>
        <?php endif; ?>

        <div class="maintenance-stats">

            <div class="maintenance-stat">
                <span>Tổng phiếu bảo trì</span>
                <strong><?= number_format($totalRecords) ?></strong>
            </div>

            <div class="maintenance-stat">
                <span>Đang bảo trì</span>
                <strong><?= number_format($totalOngoing) ?></strong>
            </div>

            <div class="maintenance-stat">
                <span>Đã hoàn thành</span>
                <strong><?= number_format($totalCompleted) ?></strong>
            </div>

            <div class="maintenance-stat">
                <span>Tổng chi phí bảo trì</span>
                <strong style="font-size:19px">
                    <?= number_format($totalCost, 0, ",", ".") ?> đ
                </strong>
            </div>

        </div>

        <?php if ($editRecord): ?>

            <section class="maintenance-panel">

                <h3>CẬP NHẬT PHIẾU BẢO TRÌ #<?= (int)$editRecord["id"] ?></h3>

                <form method="POST">

                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="maintenance_id"
                           value="<?= (int)$editRecord["id"] ?>">

                    <div class="maintenance-form-grid">

                        <div class="maintenance-field full">
                            <label>Nội dung bảo trì *</label>
                            <textarea name="problem_description" required><?= maintenanceEscape($editRecord["problem_description"]) ?></textarea>
                        </div>

                        <div class="maintenance-field">
                            <label>Ngày dự kiến hoàn thành</label>
                            <input type="date" name="expected_end_date"
                                   value="<?= maintenanceEscape($editRecord["expected_end_date"]) ?>">
                        </div>

                        <div class="maintenance-field">
                            <label>Chi phí bảo trì (VNĐ)</label>
                            <input type="number" name="maintenance_cost"
                                   min="0" step="1000"
                                   value="<?= maintenanceEscape($editRecord["maintenance_cost"]) ?>">
                        </div>

                        <div class="maintenance-field">
                            <label>Kỹ thuật viên</label>
                            <input type="text" name="technician"
                                   value="<?= maintenanceEscape($editRecord["technician"]) ?>">
                        </div>

                        <div class="maintenance-field">
                            <label>Ghi chú</label>
                            <input type="text" name="note"
                                   value="<?= maintenanceEscape($editRecord["note"]) ?>">
                        </div>

                    </div>

                    <div class="maintenance-actions">

                        <button type="submit"
                                class="maintenance-btn maintenance-primary">
                            LƯU THAY ĐỔI
                        </button>

                        <a href="/maintenance.php"
                           class="maintenance-btn maintenance-secondary">
                            HỦY
                        </a>

                    </div>

                </form>

            </section>

        <?php else: ?>

            <section class="maintenance-panel">

                <h3>TẠO PHIẾU BẢO TRÌ MỚI</h3>

                <form method="POST">

                    <input type="hidden" name="action" value="add">

                    <div class="maintenance-form-grid">

                        <div class="maintenance-field">
                            <label>Chọn thiết bị *</label>

                            <select name="device_id" required>
                                <option value="">-- Chọn thiết bị --</option>

                                <?php foreach ($devices as $device): ?>

                                    <option
                                        value="<?= (int)$device["id"] ?>"
                                        <?= $device["status"] !== "Đang sử dụng" ? "disabled" : "" ?>
                                    >
                                        <?= maintenanceEscape($device["device_code"]) ?>
                                        -
                                        <?= maintenanceEscape($device["device_name"]) ?>
                                        (<?= maintenanceEscape($device["status"]) ?>)
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="maintenance-field">
                            <label>Kỹ thuật viên</label>
                            <input type="text" name="technician"
                                   placeholder="Tên kỹ thuật viên phụ trách">
                        </div>

                        <div class="maintenance-field full">
                            <label>Nội dung / Sự cố cần bảo trì *</label>
                            <textarea name="problem_description" required
                                      placeholder="Mô tả tình trạng hoặc lỗi của thiết bị..."></textarea>
                        </div>

                        <div class="maintenance-field">
                            <label>Ngày bắt đầu bảo trì *</label>
                            <input type="date" name="maintenance_date"
                                   value="<?= date("Y-m-d") ?>" required>
                        </div>

                        <div class="maintenance-field">
                            <label>Ngày dự kiến hoàn thành</label>
                            <input type="date" name="expected_end_date">
                        </div>

                        <div class="maintenance-field">
                            <label>Chi phí bảo trì (VNĐ)</label>
                            <input type="number" name="maintenance_cost"
                                   min="0" step="1000" value="0">
                        </div>

                        <div class="maintenance-field">
                            <label>Ghi chú</label>
                            <input type="text" name="note"
                                   placeholder="Thông tin bổ sung...">
                        </div>

                    </div>

                    <div class="maintenance-actions">

                        <button type="submit"
                                class="maintenance-btn maintenance-primary">
                            TẠO PHIẾU BẢO TRÌ
                        </button>

                        <button type="reset"
                                class="maintenance-btn maintenance-secondary">
                            LÀM MỚI
                        </button>

                    </div>

                </form>

            </section>

        <?php endif; ?>

        <section class="maintenance-panel">

            <h3>DANH SÁCH PHIẾU BẢO TRÌ</h3>

            <form method="GET" class="maintenance-search">

                <input type="text" name="keyword"
                       value="<?= maintenanceEscape($keyword) ?>"
                       placeholder="Tìm mã thiết bị, tên thiết bị, nội dung hoặc kỹ thuật viên...">

                <select name="status">
                    <option value="">Tất cả trạng thái</option>

                    <option value="Đang bảo trì"
                        <?= $statusFilter === "Đang bảo trì" ? "selected" : "" ?>>
                        Đang bảo trì
                    </option>

                    <option value="Đã hoàn thành"
                        <?= $statusFilter === "Đã hoàn thành" ? "selected" : "" ?>>
                        Đã hoàn thành
                    </option>
                </select>

                <button type="submit"
                        class="maintenance-btn maintenance-primary">
                    TÌM KIẾM
                </button>

                <a href="/maintenance.php"
                   class="maintenance-btn maintenance-secondary">
                    LÀM MỚI
                </a>

            </form>

            <div class="maintenance-table-wrap">

                <table class="maintenance-table">

                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Mã phiếu</th>
                            <th>Thiết bị</th>
                            <th>Nội dung bảo trì</th>
                            <th>Kỹ thuật viên</th>
                            <th>Ngày bắt đầu</th>
                            <th>Dự kiến hoàn thành</th>
                            <th>Ngày hoàn thành</th>
                            <th>Chi phí</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($records): ?>

                        <?php foreach ($records as $index => $record): ?>

                            <?php
                            $isOngoing = $record["status"] === "Đang bảo trì";
                            ?>

                            <tr>

                                <td><?= $offset + $index + 1 ?></td>

                                <td>#<?= (int)$record["id"] ?></td>

                                <td>
                                    <?= maintenanceEscape($record["device_name"]) ?>
                                    <br>
                                    <small style="color:#91a6bd">
                                        <?= maintenanceEscape($record["device_code"]) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= maintenanceEscape($record["problem_description"]) ?>
                                </td>

                                <td>
                                    <?= maintenanceEscape($record["technician"] ?: "Chưa phân công") ?>
                                </td>

                                <td>
                                    <?= date("d/m/Y", strtotime($record["maintenance_date"])) ?>
                                </td>

                                <td>
                                    <?= $record["expected_end_date"]
                                        ? date("d/m/Y", strtotime($record["expected_end_date"]))
                                        : "Chưa xác định" ?>
                                </td>

                                <td>
                                    <?= $record["actual_end_date"]
                                        ? date("d/m/Y", strtotime($record["actual_end_date"]))
                                        : "Chưa hoàn thành" ?>
                                </td>

                                <td>
                                    <?= number_format((float)$record["maintenance_cost"], 0, ",", ".") ?> đ
                                </td>

                                <td>
                                    <span class="maintenance-status <?= $isOngoing ? "maintenance-ongoing" : "maintenance-done" ?>">
                                        <?= maintenanceEscape($record["status"]) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if ($isOngoing): ?>

                                        <div class="maintenance-actions">

                                            <a class="maintenance-btn maintenance-edit"
                                               href="?edit=<?= (int)$record["id"] ?>">
                                                SỬA
                                            </a>

                                            <form method="POST"
                                                  onsubmit="return confirm('Xác nhận thiết bị đã hoàn thành bảo trì?');">

                                                <input type="hidden" name="action"
                                                       value="complete">

                                                <input type="hidden" name="maintenance_id"
                                                       value="<?= (int)$record["id"] ?>">

                                                <input type="hidden" name="actual_end_date"
                                                       value="<?= date("Y-m-d") ?>">

                                                <button type="submit"
                                                        class="maintenance-btn maintenance-complete">
                                                    HOÀN THÀNH
                                                </button>

                                            </form>

                                        </div>

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
                            <td colspan="11" class="maintenance-empty">
                                Chưa có phiếu bảo trì phù hợp.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($totalPages > 1): ?>

                <div class="maintenance-pagination">

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                        <?php
                        $query = $_GET;
                        $query["page"] = $i;
                        unset($query["edit"]);
                        ?>

                        <a href="?<?= maintenanceEscape(http_build_query($query)) ?>"
                           class="<?= $page === $i ? "current" : "" ?>">
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>

                </div>

            <?php endif; ?>

            <p class="maintenance-note">
                Hiển thị <?= count($records) ?> / <?= $filteredTotal ?> phiếu bảo trì.
            </p>

        </section>

    </div>
    </main>

</div>

</body>
</html>
