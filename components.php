
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function componentEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";

$validStatuses = ["Đang sử dụng", "Ngừng sử dụng"];

// XỬ LÝ THÊM, SỬA, XÓA LINH KIỆN
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        // THÊM LINH KIỆN
        if ($action === "add") {
            $code = trim($_POST["component_code"] ?? "");
            $name = trim($_POST["component_name"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $quantity = (int)($_POST["quantity"] ?? 0);
            $minQuantity = (int)($_POST["min_quantity"] ?? 0);
            $unit = trim($_POST["unit"] ?? "");
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
                throw new Exception("Trạng thái linh kiện không hợp lệ.");
            }

            $stmt = $conn->prepare("
                INSERT INTO components
                (
                    component_code,
                    component_name,
                    category,
                    quantity,
                    min_quantity,
                    unit,
                    location,
                    status,
                    description
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $code,
                $name,
                $category,
                $quantity,
                $minQuantity,
                $unit,
                $location,
                $status,
                $description
            ]);

            $componentId = (int)$conn->lastInsertId();

            logActivity(
                $conn,
                "Thêm linh kiện",
                "Thêm linh kiện {$name} (Mã: {$code}), số lượng: {$quantity} {$unit}."
            );

            $message = "Thêm linh kiện thành công!";

        // CẬP NHẬT LINH KIỆN
        } elseif ($action === "update") {
            $id = (int)($_POST["id"] ?? 0);
            $code = trim($_POST["component_code"] ?? "");
            $name = trim($_POST["component_name"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $quantity = (int)($_POST["quantity"] ?? 0);
            $minQuantity = (int)($_POST["min_quantity"] ?? 0);
            $unit = trim($_POST["unit"] ?? "");
            $location = trim($_POST["location"] ?? "");
            $status = $_POST["status"] ?? "";
            $description = trim($_POST["description"] ?? "");

            if ($id <= 0) {
                throw new Exception("Linh kiện cần sửa không hợp lệ.");
            }

            if ($code === "" || $name === "") {
                throw new Exception("Vui lòng nhập mã và tên linh kiện.");
            }

            if ($quantity < 0 || $minQuantity < 0) {
                throw new Exception("Số lượng không được nhỏ hơn 0.");
            }

            if (!in_array($status, $validStatuses, true)) {
                throw new Exception("Trạng thái linh kiện không hợp lệ.");
            }

            $stmt = $conn->prepare("
                UPDATE components
                SET
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
                $code,
                $name,
                $category,
                $quantity,
                $minQuantity,
                $unit,
                $location,
                $status,
                $description,
                $id
            ]);

            logActivity(
                $conn,
                "Cập nhật linh kiện",
                "Cập nhật linh kiện {$name} (Mã: {$code}), số lượng: {$quantity} {$unit}."
            );

            $message = "Cập nhật linh kiện thành công!";

        // XÓA LINH KIỆN
        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            if ($id <= 0) {
                throw new Exception("Linh kiện cần xóa không hợp lệ.");
            }

            $stmt = $conn->prepare("
                SELECT component_code, component_name
                FROM components
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $component = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$component) {
                throw new Exception("Không tìm thấy linh kiện cần xóa.");
            }

            $stmt = $conn->prepare("
                DELETE FROM components
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            logActivity(
                $conn,
                "Xóa linh kiện",
                "Đã xóa linh kiện {$component['component_name']} (Mã: {$component['component_code']})."
            );

            $message = "Xóa linh kiện thành công!";

        } else {
            throw new Exception("Thao tác không hợp lệ.");
        }

    } catch (PDOException $e) {
        if ($e->getCode() === "23000") {
            $message = "Không thể thực hiện thao tác. Mã linh kiện có thể đã tồn tại hoặc linh kiện đang được sử dụng trong dữ liệu khác.";
        } else {
            $message = "Có lỗi cơ sở dữ liệu. Vui lòng kiểm tra lại.";
        }

        $messageType = "error";

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// LẤY LINH KIỆN CẦN CHỈNH SỬA
$editComponent = null;
$editId = (int)($_GET["edit"] ?? 0);

if ($editId > 0) {
    $editStmt = $conn->prepare("
        SELECT *
        FROM components
        WHERE id = ?
    ");
    $editStmt->execute([$editId]);
    $editComponent = $editStmt->fetch(PDO::FETCH_ASSOC);
}

// THỐNG KÊ
$totalComponents = (int)$conn->query("
    SELECT COUNT(*) FROM components
")->fetchColumn();

$totalQuantity = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM components
")->fetchColumn();

$lowStock = (int)$conn->query("
    SELECT COUNT(*)
    FROM components
    WHERE quantity <= min_quantity
")->fetchColumn();

$activeComponents = (int)$conn->query("
    SELECT COUNT(*)
    FROM components
    WHERE status = 'Đang sử dụng'
")->fetchColumn();

// TÌM KIẾM VÀ LỌC
$keyword = trim($_GET["keyword"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$stockFilter = $_GET["stock"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(
        component_code LIKE ?
        OR component_name LIKE ?
        OR category LIKE ?
        OR location LIKE ?
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

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM components
    $whereSQL
");
$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// DANH SÁCH LINH KIỆN
$stmt = $conn->prepare("
    SELECT *
    FROM components
    $whereSQL
    ORDER BY id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isEditing = $editComponent !== false && $editComponent !== null;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý linh kiện - EQUIP CONTROL</title>

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

/* BỐ CỤC CHUNG */
.page-container {
    display: block !important;
    width: 100%;
    min-height: 100vh;
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

/* KHU VỰC NỘI DUNG */
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
    box-sizing: border-box !important;
}

.component-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
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
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.component-stat strong {
    color: #22d3ee;
    font-size: 25px;
}

.component-stat.warning strong {
    color: #fbbf24;
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

.component-btn-primary {
    background: #0891b2;
    color: white;
}

.component-btn-secondary {
    background: #30445c;
    color: white;
}

.component-btn-edit {
    background: #1d4ed8;
    color: white;
}

.component-btn-delete {
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
    min-width: 1050px;
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

.component-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.component-active {
    background: #123c32;
    color: #86efac;
}

.component-inactive {
    background: #49371e;
    color: #fcd34d;
}

.component-low {
    color: #fbbf24 !important;
    font-weight: 600;
}

.component-normal {
    color: #86efac !important;
    font-weight: 600;
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

.component-note {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 14px;
}

.component-small {
    color: #91a6bd;
    font-size: 11px;
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

<div class="page-container">

    <?php require __DIR__ . "/includes/sidebar.php"; ?>

    <main class="main-content">
    <div class="component-content">

        <h1 class="component-heading">QUẢN LÝ LINH KIỆN</h1>

        <p class="component-subtitle">
            Quản lý thông tin, số lượng, vị trí lưu trữ và tình trạng tồn kho linh kiện.
        </p>

        <?php if ($message !== ""): ?>
            <div class="component-alert <?= componentEscape($messageType) ?>">
                <?= componentEscape($message) ?>
            </div>
        <?php endif; ?>

        <div class="component-stats">

            <div class="component-stat">
                <span>Tổng số linh kiện</span>
                <strong><?= number_format($totalComponents) ?></strong>
            </div>

            <div class="component-stat">
                <span>Tổng số lượng tồn</span>
                <strong><?= number_format($totalQuantity) ?></strong>
            </div>

            <div class="component-stat warning">
                <span>Linh kiện sắp hết</span>
                <strong><?= number_format($lowStock) ?></strong>
            </div>

            <div class="component-stat">
                <span>Linh kiện đang sử dụng</span>
                <strong><?= number_format($activeComponents) ?></strong>
            </div>

        </div>

        <section class="component-panel">

            <h3>
                <?= $isEditing ? "CHỈNH SỬA THÔNG TIN LINH KIỆN" : "THÊM LINH KIỆN MỚI" ?>
            </h3>

            <form method="POST">

                <input type="hidden"
                       name="action"
                       value="<?= $isEditing ? "update" : "add" ?>">

                <?php if ($isEditing): ?>
                    <input type="hidden"
                           name="id"
                           value="<?= (int)$editComponent["id"] ?>">
                <?php endif; ?>

                <div class="component-form-grid">

                    <div class="component-field">
                        <label>Mã linh kiện *</label>
                        <input type="text"
                               name="component_code"
                               required
                               placeholder="Nhập mã linh kiện"
                               value="<?= $isEditing ? componentEscape($editComponent["component_code"]) : "" ?>">
                    </div>

                    <div class="component-field">
                        <label>Tên linh kiện *</label>
                        <input type="text"
                               name="component_name"
                               required
                               placeholder="Nhập tên linh kiện"
                               value="<?= $isEditing ? componentEscape($editComponent["component_name"]) : "" ?>">
                    </div>

                    <div class="component-field">
                        <label>Danh mục</label>
                        <input type="text"
                               name="category"
                               placeholder="Nhập danh mục linh kiện"
                               value="<?= $isEditing ? componentEscape($editComponent["category"]) : "" ?>">
                    </div>

                    <div class="component-field">
                        <label>Số lượng hiện có *</label>
                        <input type="number"
                               name="quantity"
                               min="0"
                               required
                               value="<?= $isEditing ? (int)$editComponent["quantity"] : "0" ?>">
                    </div>

                    <div class="component-field">
                        <label>Số lượng tối thiểu *</label>
                        <input type="number"
                               name="min_quantity"
                               min="0"
                               required
                               value="<?= $isEditing ? (int)$editComponent["min_quantity"] : "0" ?>">
                    </div>

                    <div class="component-field">
                        <label>Đơn vị tính</label>
                        <input type="text"
                               name="unit"
                               placeholder="Ví dụ: Cái, chiếc, bộ..."
                               value="<?= $isEditing ? componentEscape($editComponent["unit"]) : "" ?>">
                    </div>

                    <div class="component-field">
                        <label>Vị trí lưu trữ</label>
                        <input type="text"
                               name="location"
                               placeholder="Nhập vị trí lưu trữ"
                               value="<?= $isEditing ? componentEscape($editComponent["location"]) : "" ?>">
                    </div>

                    <div class="component-field">
                        <label>Trạng thái</label>
                        <select name="status">
                            <option value="Đang sử dụng"
                                <?= !$isEditing || $editComponent["status"] === "Đang sử dụng" ? "selected" : "" ?>>
                                Đang sử dụng
                            </option>

                            <option value="Ngừng sử dụng"
                                <?= $isEditing && $editComponent["status"] === "Ngừng sử dụng" ? "selected" : "" ?>>
                                Ngừng sử dụng
                            </option>
                        </select>
                    </div>

                    <div class="component-field full">
                        <label>Mô tả</label>
                        <textarea name="description"
                                  placeholder="Nhập thông tin mô tả linh kiện..."><?= $isEditing ? componentEscape($editComponent["description"]) : "" ?></textarea>
                    </div>

                </div>

                <div class="component-actions">

                    <button type="submit" class="component-btn component-btn-primary">
                        <?= $isEditing ? "CẬP NHẬT LINH KIỆN" : "THÊM LINH KIỆN" ?>
                    </button>

                    <?php if ($isEditing): ?>
                        <a href="/components.php"
                           class="component-btn component-btn-secondary">
                            HỦY CHỈNH SỬA
                        </a>
                    <?php endif; ?>

                </div>

            </form>

        </section>

        <section class="component-panel">

            <h3>DANH SÁCH LINH KIỆN</h3>

            <form method="GET" class="component-search">

                <input type="text"
                       name="keyword"
                       value="<?= componentEscape($keyword) ?>"
                       placeholder="Tìm mã, tên, danh mục hoặc vị trí linh kiện...">

                <select name="status">
                    <option value="">Tất cả trạng thái</option>

                    <option value="Đang sử dụng"
                        <?= $statusFilter === "Đang sử dụng" ? "selected" : "" ?>>
                        Đang sử dụng
                    </option>

                    <option value="Ngừng sử dụng"
                        <?= $statusFilter === "Ngừng sử dụng" ? "selected" : "" ?>>
                        Ngừng sử dụng
                    </option>
                </select>

                <select name="stock">
                    <option value="">Tất cả tồn kho</option>

                    <option value="low"
                        <?= $stockFilter === "low" ? "selected" : "" ?>>
                        Sắp hết hàng
                    </option>

                    <option value="normal"
                        <?= $stockFilter === "normal" ? "selected" : "" ?>>
                        Tồn kho bình thường
                    </option>
                </select>

                <button type="submit" class="component-btn component-btn-primary">
                    TÌM KIẾM
                </button>

                <a href="/components.php"
                   class="component-btn component-btn-secondary">
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
                            <th>Tối thiểu</th>
                            <th>Vị trí</th>
                            <th>Tồn kho</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($components): ?>

                        <?php foreach ($components as $index => $component): ?>

                            <?php
                            $isLowStock = (int)$component["quantity"]
                                <= (int)$component["min_quantity"];

                            $isActive = $component["status"] === "Đang sử dụng";
                            ?>

                            <tr>

                                <td><?= $offset + $index + 1 ?></td>

                                <td>
                                    <?= componentEscape($component["component_code"]) ?>
                                </td>

                                <td>
                                    <?= componentEscape($component["component_name"]) ?>
                                </td>

                                <td>
                                    <?= componentEscape($component["category"]) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$component["quantity"]) ?>
                                    <?= componentEscape($component["unit"]) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$component["min_quantity"]) ?>
                                    <?= componentEscape($component["unit"]) ?>
                                </td>

                                <td>
                                    <?= componentEscape($component["location"]) ?>
                                </td>

                                <td>
                                    <span class="<?= $isLowStock ? "component-low" : "component-normal" ?>">
                                        <?= $isLowStock ? "Sắp hết" : "Bình thường" ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="component-status <?= $isActive ? "component-active" : "component-inactive" ?>">
                                        <?= componentEscape($component["status"]) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="component-actions" style="margin:0;flex-wrap:nowrap;">

                                        <a href="?edit=<?= (int)$component["id"] ?>"
                                           class="component-btn component-btn-edit">
                                            SỬA
                                        </a>

                                        <form method="POST"
                                              onsubmit="return confirm('Bạn có chắc chắn muốn xóa linh kiện này?');">

                                            <input type="hidden"
                                                   name="action"
                                                   value="delete">

                                            <input type="hidden"
                                                   name="id"
                                                   value="<?= (int)$component["id"] ?>">

                                            <button type="submit"
                                                    class="component-btn component-btn-delete">
                                                XÓA
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="component-empty">
                                Chưa có linh kiện phù hợp.
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
                        unset($query["edit"]);
                        $query["page"] = $i;
                        ?>

                        <a href="?<?= componentEscape(http_build_query($query)) ?>"
                           class="<?= $page === $i ? "current" : "" ?>">
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>

                </div>

            <?php endif; ?>

            <p class="component-note">
                Hiển thị <?= count($components) ?> / <?= $filteredTotal ?> linh kiện.
            </p>

        </section>

    </div>
    </main>

</div>

</body>
</html>
