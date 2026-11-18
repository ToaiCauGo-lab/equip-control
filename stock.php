
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

function stockEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";

// XỬ LÝ NHẬP / XUẤT KHO
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $type = $_POST["transaction_type"] ?? "";
    $componentId = (int)($_POST["component_id"] ?? 0);
    $quantity = (int)($_POST["quantity"] ?? 0);
    $note = trim($_POST["note"] ?? "");

    try {
        if (!in_array($type, ["Nhập kho", "Xuất kho"], true)) {
            throw new Exception("Loại giao dịch không hợp lệ.");
        }

        if ($componentId <= 0) {
            throw new Exception("Vui lòng chọn linh kiện.");
        }

        if ($quantity <= 0) {
            throw new Exception("Số lượng giao dịch phải lớn hơn 0.");
        }

        $conn->beginTransaction();

        // Khóa dòng linh kiện để tránh giao dịch đồng thời làm sai tồn kho
        $stmt = $conn->prepare("
            SELECT id, component_code, component_name, quantity, unit, status
            FROM components
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$componentId]);
        $component = $stmt->fetch();

        if (!$component) {
            throw new Exception("Không tìm thấy linh kiện.");
        }

        if ($component["status"] !== "Đang sử dụng") {
            throw new Exception("Linh kiện đã ngừng sử dụng, không thể giao dịch.");
        }

        $oldQuantity = (int)$component["quantity"];

        if ($type === "Xuất kho" && $quantity > $oldQuantity) {
            throw new Exception(
                "Không đủ hàng trong kho. Số lượng hiện tại chỉ còn "
                . $oldQuantity . " " . $component["unit"] . "."
            );
        }

        if ($type === "Nhập kho") {
            $newQuantity = $oldQuantity + $quantity;
        } else {
            $newQuantity = $oldQuantity - $quantity;
        }

        // Cập nhật tồn kho
        $update = $conn->prepare("
            UPDATE components
            SET quantity = ?
            WHERE id = ?
        ");
        $update->execute([$newQuantity, $componentId]);

        // Lưu lịch sử giao dịch
        $insert = $conn->prepare("
            INSERT INTO stock_transactions
            (component_id, transaction_type, quantity, note)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([
            $componentId,
            $type,
            $quantity,
            $note
        ]);

        $transactionId = (int)$conn->lastInsertId();

        $conn->commit();

        // Ghi nhật ký sau khi giao dịch kho đã thành công
        logActivity(
            $conn,
            $type,
            "{$type} linh kiện {$component['component_name']} "
            . "(Mã: {$component['component_code']}), số lượng {$quantity} "
            . "{$component['unit']}. Tồn kho: {$oldQuantity} → {$newQuantity}. "
            . "Mã giao dịch: {$transactionId}."
        );

        $message = $type . " thành công! Tồn kho hiện tại: "
            . $newQuantity . " " . $component["unit"] . ".";

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

// DANH SÁCH LINH KIỆN CHO FORM
$componentStmt = $conn->query("
    SELECT id, component_code, component_name, quantity, unit, status
    FROM components
    ORDER BY component_name ASC
");
$componentList = $componentStmt->fetchAll();

// THỐNG KÊ
$totalImported = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_transactions
    WHERE transaction_type = 'Nhập kho'
")->fetchColumn();

$totalExported = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_transactions
    WHERE transaction_type = 'Xuất kho'
")->fetchColumn();

$totalTransactions = (int)$conn->query("
    SELECT COUNT(*) FROM stock_transactions
")->fetchColumn();

$todayTransactions = (int)$conn->query("
    SELECT COUNT(*)
    FROM stock_transactions
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

// TÌM KIẾM VÀ LỌC LỊCH SỬ
$keyword = trim($_GET["keyword"] ?? "");
$typeFilter = $_GET["type"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(
        c.component_code LIKE ?
        OR c.component_name LIKE ?
        OR st.note LIKE ?
    )";

    $search = "%{$keyword}%";
    array_push($params, $search, $search, $search);
}

if (in_array($typeFilter, ["Nhập kho", "Xuất kho"], true)) {
    $where[] = "st.transaction_type = ?";
    $params[] = $typeFilter;
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM stock_transactions st
    INNER JOIN components c ON st.component_id = c.id
    $whereSQL
");
$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// LỊCH SỬ GIAO DỊCH
$stmt = $conn->prepare("
    SELECT
        st.id,
        st.transaction_type,
        st.quantity,
        st.note,
        st.created_at,
        c.component_code,
        c.component_name,
        c.unit
    FROM stock_transactions st
    INNER JOIN components c ON st.component_id = c.id
    $whereSQL
    ORDER BY st.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý nhập xuất kho - EQUIP CONTROL</title>

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

.stock-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
}

.stock-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.stock-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.stock-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.stock-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 19px;
    min-width: 0;
}

.stock-stat span {
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.stock-stat strong {
    color: #22d3ee;
    font-size: 25px;
}

.stock-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    width: 100%;
    min-width: 0;
}

.stock-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.stock-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.stock-field {
    min-width: 0;
}

.stock-field label {
    display: block;
    color: #a9bfd5;
    font-size: 12px;
    margin-bottom: 7px;
}

.stock-field input,
.stock-field select,
.stock-field textarea {
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

.stock-field input:focus,
.stock-field select:focus,
.stock-field textarea:focus {
    border-color: #22d3ee;
}

.stock-field textarea {
    min-height: 75px;
    resize: vertical;
}

.stock-field.full {
    grid-column: 1 / -1;
}

.stock-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 17px;
}

.stock-btn {
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

.stock-btn-primary {
    background: #0891b2;
    color: white;
}

.stock-btn-secondary {
    background: #30445c;
    color: white;
}

.stock-search {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.stock-search input,
.stock-search select {
    padding: 10px 12px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
}

.stock-search input {
    flex: 1;
    min-width: 200px;
}

.stock-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.stock-table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
    white-space: nowrap;
}

.stock-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c;
    color: #22d3ee;
    font-size: 12px;
}

.stock-table td {
    padding: 13px 11px;
    border-bottom: 1px solid #263b52;
    color: #d4e1ef;
    font-size: 12px;
}

.stock-table tbody tr:hover {
    background: rgba(34, 211, 238, .04);
}

.stock-type {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.stock-import {
    background: #123c32;
    color: #86efac;
}

.stock-export {
    background: #49371e;
    color: #fcd34d;
}

.stock-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
}

.stock-alert.success {
    color: #86efac;
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
}

.stock-alert.error {
    color: #fca5a5;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
}

.stock-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.stock-pagination a {
    padding: 8px 12px;
    border-radius: 6px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    font-size: 12px;
}

.stock-pagination .current {
    background: #0891b2;
    color: white;
}

.stock-empty {
    text-align: center;
    padding: 30px !important;
    color: #91a6bd !important;
}

.stock-note {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 14px;
}

@media(max-width: 950px) {
    .stock-stats {
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

    .stock-content {
        padding: 16px;
    }
}

@media(max-width: 600px) {
    .stock-form-grid,
    .stock-stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
<div class="stock-content">

    <h1 class="stock-heading">QUẢN LÝ NHẬP XUẤT KHO</h1>

    <p class="stock-subtitle">
        Theo dõi hoạt động nhập kho, xuất kho và số lượng linh kiện tồn.
    </p>

    <?php if ($message !== ""): ?>
        <div class="stock-alert <?= stockEscape($messageType) ?>">
            <?= stockEscape($message) ?>
        </div>
    <?php endif; ?>

    <div class="stock-stats">

        <div class="stock-stat">
            <span>Tổng số lượng đã nhập</span>
            <strong><?= number_format($totalImported) ?></strong>
        </div>

        <div class="stock-stat">
            <span>Tổng số lượng đã xuất</span>
            <strong><?= number_format($totalExported) ?></strong>
        </div>

        <div class="stock-stat">
            <span>Tổng giao dịch</span>
            <strong><?= number_format($totalTransactions) ?></strong>
        </div>

        <div class="stock-stat">
            <span>Giao dịch hôm nay</span>
            <strong><?= number_format($todayTransactions) ?></strong>
        </div>

    </div>

    <section class="stock-panel">
        <h3>THỰC HIỆN GIAO DỊCH KHO</h3>

        <form method="POST">

            <div class="stock-form-grid">

                <div class="stock-field">
                    <label>Loại giao dịch *</label>
                    <select name="transaction_type" id="transactionType" required>
                        <option value="Nhập kho">Nhập kho</option>
                        <option value="Xuất kho">Xuất kho</option>
                    </select>
                </div>

                <div class="stock-field">
                    <label>Chọn linh kiện *</label>
                    <select name="component_id" id="componentSelect" required>
                        <option value="">-- Chọn linh kiện --</option>

                        <?php foreach ($componentList as $component): ?>
                            <option
                                value="<?= (int)$component["id"] ?>"
                                data-quantity="<?= (int)$component["quantity"] ?>"
                                data-unit="<?= stockEscape($component["unit"]) ?>"
                                <?= $component["status"] !== "Đang sử dụng" ? "disabled" : "" ?>
                            >
                                <?= stockEscape($component["component_code"]) ?>
                                -
                                <?= stockEscape($component["component_name"]) ?>
                                (Tồn: <?= (int)$component["quantity"] ?>
                                <?= stockEscape($component["unit"]) ?>)
                                <?= $component["status"] !== "Đang sử dụng" ? "- Ngừng sử dụng" : "" ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="stock-field">
                    <label>Số lượng giao dịch *</label>
                    <input type="number" name="quantity" min="1" required
                           placeholder="Nhập số lượng">
                </div>

                <div class="stock-field">
                    <label>Ghi chú</label>
                    <input type="text" name="note"
                           placeholder="Nhập lý do hoặc thông tin giao dịch">
                </div>

            </div>

            <div id="stockInfo" class="stock-note">
                Chọn linh kiện để xem số lượng tồn hiện tại.
            </div>

            <div class="stock-actions">
                <button type="submit" class="stock-btn stock-btn-primary">
                    XÁC NHẬN GIAO DỊCH
                </button>

                <button type="reset" class="stock-btn stock-btn-secondary">
                    LÀM MỚI
                </button>
            </div>

        </form>
    </section>

    <section class="stock-panel">

        <h3>LỊCH SỬ NHẬP XUẤT KHO</h3>

        <form method="GET" class="stock-search">

            <input type="text" name="keyword"
                   value="<?= stockEscape($keyword) ?>"
                   placeholder="Tìm mã linh kiện, tên linh kiện hoặc ghi chú...">

            <select name="type">
                <option value="">Tất cả giao dịch</option>

                <option value="Nhập kho"
                    <?= $typeFilter === "Nhập kho" ? "selected" : "" ?>>
                    Nhập kho
                </option>

                <option value="Xuất kho"
                    <?= $typeFilter === "Xuất kho" ? "selected" : "" ?>>
                    Xuất kho
                </option>
            </select>

            <button type="submit" class="stock-btn stock-btn-primary">
                TÌM KIẾM
            </button>

            <a href="/equip-control/stock.php"
               class="stock-btn stock-btn-secondary">
                LÀM MỚI
            </a>

        </form>

        <div class="stock-table-wrap">

            <table class="stock-table">

                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã giao dịch</th>
                        <th>Mã linh kiện</th>
                        <th>Tên linh kiện</th>
                        <th>Loại giao dịch</th>
                        <th>Số lượng</th>
                        <th>Ghi chú</th>
                        <th>Thời gian</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($transactions): ?>

                    <?php foreach ($transactions as $index => $transaction): ?>

                        <?php
                        $isImport = $transaction["transaction_type"] === "Nhập kho";
                        ?>

                        <tr>

                            <td><?= $offset + $index + 1 ?></td>

                            <td>#<?= (int)$transaction["id"] ?></td>

                            <td>
                                <?= stockEscape($transaction["component_code"]) ?>
                            </td>

                            <td>
                                <?= stockEscape($transaction["component_name"]) ?>
                            </td>

                            <td>
                                <span class="stock-type <?= $isImport ? "stock-import" : "stock-export" ?>">
                                    <?= stockEscape($transaction["transaction_type"]) ?>
                                </span>
                            </td>

                            <td>
                                <?= $isImport ? "+" : "-" ?>
                                <?= number_format((int)$transaction["quantity"]) ?>
                                <?= stockEscape($transaction["unit"]) ?>
                            </td>

                            <td>
                                <?= stockEscape($transaction["note"] ?: "Không có ghi chú") ?>
                            </td>

                            <td>
                                <?= date("d/m/Y H:i", strtotime($transaction["created_at"])) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="stock-empty">
                            Chưa có giao dịch nhập xuất kho.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="stock-pagination">

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                    <?php
                    $query = $_GET;
                    $query["page"] = $i;
                    ?>

                    <a href="?<?= stockEscape(http_build_query($query)) ?>"
                       class="<?= $page === $i ? "current" : "" ?>">
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

            </div>

        <?php endif; ?>

        <p class="stock-note">
            Hiển thị <?= count($transactions) ?> / <?= $filteredTotal ?> giao dịch.
        </p>

    </section>

</div>
</main>

<script>
const componentSelect = document.getElementById("componentSelect");
const transactionType = document.getElementById("transactionType");
const stockInfo = document.getElementById("stockInfo");

function updateStockInfo() {
    const option = componentSelect.options[componentSelect.selectedIndex];

    if (!option || !option.value) {
        stockInfo.textContent = "Chọn linh kiện để xem số lượng tồn hiện tại.";
        return;
    }

    const quantity = option.dataset.quantity;
    const unit = option.dataset.unit;

    if (transactionType.value === "Xuất kho") {
        stockInfo.textContent =
            "Số lượng hiện có: " + quantity + " " + unit +
            ". Không thể xuất vượt quá số lượng này.";
    } else {
        stockInfo.textContent =
            "Số lượng hiện có: " + quantity + " " + unit +
            ". Số lượng sẽ được cộng thêm sau khi nhập kho.";
    }
}

componentSelect.addEventListener("change", updateStockInfo);
transactionType.addEventListener("change", updateStockInfo);
</script>

</body>
</html>
