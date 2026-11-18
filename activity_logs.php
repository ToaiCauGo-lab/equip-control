
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireAdmin();

function activityEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

// NHẬN BỘ LỌC
$keyword = trim($_GET["keyword"] ?? "");
$actionFilter = trim($_GET["action"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");

// KIỂM TRA ĐỊNH DẠNG NGÀY
if ($dateFrom !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = "";
}

if ($dateTo !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = "";
}

// TẠO ĐIỀU KIỆN TÌM KIẾM
$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(
        username LIKE ?
        OR full_name LIKE ?
        OR action LIKE ?
        OR description LIKE ?
        OR ip_address LIKE ?
    )";

    $search = "%" . $keyword . "%";

    array_push(
        $params,
        $search,
        $search,
        $search,
        $search,
        $search
    );
}

if ($actionFilter !== "") {
    $where[] = "action = ?";
    $params[] = $actionFilter;
}

if ($dateFrom !== "") {
    $where[] = "created_at >= ?";
    $params[] = $dateFrom . " 00:00:00";
}

if ($dateTo !== "") {
    $where[] = "created_at <= ?";
    $params[] = $dateTo . " 23:59:59";
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// THỐNG KÊ TỔNG QUAN
$totalLogs = (int)$conn->query("
    SELECT COUNT(*) FROM activity_logs
")->fetchColumn();

$todayLogs = (int)$conn->query("
    SELECT COUNT(*)
    FROM activity_logs
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

$totalUsers = (int)$conn->query("
    SELECT COUNT(DISTINCT user_id)
    FROM activity_logs
    WHERE user_id IS NOT NULL
")->fetchColumn();

$todayUsers = (int)$conn->query("
    SELECT COUNT(DISTINCT user_id)
    FROM activity_logs
    WHERE user_id IS NOT NULL
    AND DATE(created_at) = CURDATE()
")->fetchColumn();

// LẤY DANH SÁCH LOẠI HOẠT ĐỘNG
$actionStmt = $conn->query("
    SELECT DISTINCT action
    FROM activity_logs
    WHERE action IS NOT NULL
    AND action != ''
    ORDER BY action ASC
");

$actionList = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

// PHÂN TRANG
$perPage = 15;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM activity_logs
    $whereSQL
");

$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// LẤY NHẬT KÝ
$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        username,
        full_name,
        action,
        description,
        ip_address,
        created_at
    FROM activity_logs
    $whereSQL
    ORDER BY created_at DESC, id DESC
    LIMIT $perPage OFFSET $offset
");

$stmt->execute($params);

$logs = $stmt->fetchAll();

// HÀM MÀU CHO LOẠI HOẠT ĐỘNG
function activityColor($action) {

    $action = mb_strtolower((string)$action, "UTF-8");

    if (str_contains($action, "xóa") || str_contains($action, "hủy")) {
        return "danger";
    }

    if (str_contains($action, "khóa") || str_contains($action, "xuất")) {
        return "warning";
    }

    if (str_contains($action, "thêm") ||
        str_contains($action, "tạo") ||
        str_contains($action, "nhập")) {
        return "success";
    }

    if (str_contains($action, "đổi mật khẩu") ||
        str_contains($action, "đặt lại mật khẩu")) {
        return "purple";
    }

    return "normal";
}

?>

<!DOCTYPE html>
<html lang="vi">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Nhật ký hoạt động - EQUIP CONTROL</title>

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

.main-content {
    position: relative !important;
    display: block !important;
    margin-left: 250px !important;
    margin-top: 0 !important;
    width: calc(100% - 250px) !important;
    min-height: 100vh;
    padding: 0 !important;
}

.logs-content {
    width: 100%;
    padding: 25px;
    min-width: 0;
}

.logs-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.logs-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.logs-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 23px;
}

.logs-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 18px;
}

.logs-stat span {
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.logs-stat strong {
    display: block;
    color: #22d3ee;
    font-size: 25px;
}

.logs-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    min-width: 0;
}

.logs-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.logs-filter {
    display: grid;
    grid-template-columns: 2fr 1.2fr 1fr 1fr auto auto;
    gap: 9px;
    margin-bottom: 20px;
    align-items: end;
}

.logs-field {
    min-width: 0;
}

.logs-field label {
    display: block;
    color: #a9bfd5;
    font-size: 11px;
    margin-bottom: 7px;
}

.logs-field input,
.logs-field select {
    width: 100%;
    padding: 10px 11px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
    outline: none;
}

.logs-field input:focus,
.logs-field select:focus {
    border-color: #22d3ee;
}

.logs-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 10px 13px;
    border: 0;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.logs-primary {
    background: #0891b2;
    color: white;
}

.logs-secondary {
    background: #30445c;
    color: white;
}

.logs-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.logs-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

.logs-table th {
    background: #17283c;
    color: #22d3ee;
    text-align: left;
    padding: 13px 11px;
    font-size: 11px;
    white-space: nowrap;
}

.logs-table td {
    padding: 13px 11px;
    border-bottom: 1px solid #263b52;
    color: #d4e1ef;
    font-size: 12px;
    vertical-align: top;
}

.logs-table tbody tr:hover {
    background: rgba(34,211,238,.04);
}

.logs-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    white-space: nowrap;
}

.logs-badge.success {
    background: #123c32;
    color: #86efac;
}

.logs-badge.danger {
    background: #501f27;
    color: #fca5a5;
}

.logs-badge.warning {
    background: #49371e;
    color: #fcd34d;
}

.logs-badge.purple {
    background: #39245e;
    color: #d8b4fe;
}

.logs-badge.normal {
    background: #164e63;
    color: #a5f3fc;
}

.logs-user {
    color: #e5edf8;
    font-weight: 600;
}

.logs-username {
    color: #91a6bd;
    font-size: 11px;
    margin-top: 5px;
}

.logs-description {
    max-width: 300px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.logs-date {
    white-space: nowrap;
    color: #a9bfd5;
}

.logs-ip {
    color: #91a6bd;
    white-space: nowrap;
}

.logs-empty {
    text-align: center;
    padding: 35px !important;
    color: #91a6bd !important;
}

.logs-pagination {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 22px;
}

.logs-pagination a {
    padding: 8px 12px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    border-radius: 6px;
    font-size: 12px;
}

.logs-pagination a.current {
    background: #0891b2;
    color: white;
}

.logs-footer {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 15px;
}

.logs-tip {
    color: #91a6bd;
    font-size: 12px;
    line-height: 1.7;
    margin-top: 15px;
}

@media(max-width: 1200px) {
    .logs-filter {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media(max-width: 850px) {
    .logs-stats {
        grid-template-columns: 1fr;
    }

    .logs-filter {
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

    .logs-content {
        padding: 16px;
    }
}

</style>

</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">

<div class="logs-content">

    <h1 class="logs-heading">NHẬT KÝ HOẠT ĐỘNG</h1>

    <p class="logs-subtitle">
        Theo dõi lịch sử thao tác và hoạt động của người dùng trong hệ thống.
    </p>

    <!-- THỐNG KÊ -->

    <div class="logs-stats">

        <div class="logs-stat">
            <span>Tổng số hoạt động</span>
            <strong><?= number_format($totalLogs) ?></strong>
        </div>

        <div class="logs-stat">
            <span>Hoạt động hôm nay</span>
            <strong><?= number_format($todayLogs) ?></strong>
        </div>

        <div class="logs-stat">
            <span>Người dùng đã thao tác hôm nay</span>
            <strong><?= number_format($todayUsers) ?></strong>
        </div>

    </div>

    <section class="logs-panel">

        <h3>LỊCH SỬ THAO TÁC</h3>

        <!-- BỘ LỌC -->

        <form method="GET" class="logs-filter">

            <div class="logs-field">
                <label>Tìm kiếm</label>
                <input
                    type="text"
                    name="keyword"
                    value="<?= activityEscape($keyword) ?>"
                    placeholder="Tên người dùng, nội dung, IP..."
                >
            </div>

            <div class="logs-field">
                <label>Loại hoạt động</label>
                <select name="action">

                    <option value="">Tất cả hoạt động</option>

                    <?php foreach ($actionList as $item): ?>

                        <option
                            value="<?= activityEscape($item) ?>"
                            <?= $actionFilter === $item ? "selected" : "" ?>
                        >
                            <?= activityEscape($item) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="logs-field">
                <label>Từ ngày</label>
                <input
                    type="date"
                    name="date_from"
                    value="<?= activityEscape($dateFrom) ?>"
                >
            </div>

            <div class="logs-field">
                <label>Đến ngày</label>
                <input
                    type="date"
                    name="date_to"
                    value="<?= activityEscape($dateTo) ?>"
                >
            </div>

            <button type="submit" class="logs-btn logs-primary">
                TÌM KIẾM
            </button>

            <a href="/equip-control/activity_logs.php"
               class="logs-btn logs-secondary">
                LÀM MỚI
            </a>

        </form>

        <!-- BẢNG NHẬT KÝ -->

        <div class="logs-table-wrap">

            <table class="logs-table">

                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Người thực hiện</th>
                        <th>Hoạt động</th>
                        <th>Nội dung chi tiết</th>
                        <th>Địa chỉ IP</th>
                        <th>Thời gian</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($logs): ?>

                    <?php foreach ($logs as $index => $log): ?>

                        <tr>

                            <td>
                                <?= $offset + $index + 1 ?>
                            </td>

                            <td>
                                <div class="logs-user">
                                    <?= activityEscape($log["full_name"] ?: "Không xác định") ?>
                                </div>

                                <div class="logs-username">
                                    @<?= activityEscape($log["username"] ?: "unknown") ?>
                                </div>
                            </td>

                            <td>
                                <span class="logs-badge <?= activityColor($log["action"]) ?>">
                                    <?= activityEscape($log["action"]) ?>
                                </span>
                            </td>

                            <td>
                                <div class="logs-description">
                                    <?= activityEscape($log["description"] ?: "Không có nội dung chi tiết") ?>
                                </div>
                            </td>

                            <td>
                                <span class="logs-ip">
                                    <?= activityEscape($log["ip_address"] ?: "Không xác định") ?>
                                </span>
                            </td>

                            <td>
                                <span class="logs-date">
                                    <?= $log["created_at"]
                                        ? date("d/m/Y H:i:s", strtotime($log["created_at"]))
                                        : "Không xác định" ?>
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="logs-empty">
                            Không tìm thấy nhật ký hoạt động phù hợp.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- PHÂN TRANG -->

        <?php if ($totalPages > 1): ?>

            <div class="logs-pagination">

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                    <?php
                    $query = $_GET;
                    $query["page"] = $i;
                    ?>

                    <a
                        href="?<?= activityEscape(http_build_query($query)) ?>"
                        class="<?= $page === $i ? "current" : "" ?>"
                    >
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

            </div>

        <?php endif; ?>

        <div class="logs-footer">
            Đang hiển thị <?= count($logs) ?> / <?= number_format($filteredTotal) ?> bản ghi phù hợp.
        </div>

        <div class="logs-tip">
            Lưu ý: Nhật ký ghi nhận các thao tác được lập trình gọi hàm
            <strong>logActivity()</strong>. Những thao tác chưa được tích hợp
            chức năng ghi nhật ký sẽ không tự động xuất hiện tại đây.
        </div>

    </section>

</div>

</main>

</body>
</html>
