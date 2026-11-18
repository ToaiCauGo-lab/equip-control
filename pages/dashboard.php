
<?php
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../config/database.php";

requireLogin();

$fullName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Người dùng";
$role = $_SESSION["role"] ?? "staff";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// THỐNG KÊ
$totalDevices = (int)$conn->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$totalComponents = (int)$conn->query("SELECT COUNT(*) FROM components")->fetchColumn();

$totalBorrowing = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM borrow_records
    WHERE status = 'Đang mượn'
")->fetchColumn();

$totalMaintenance = (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_records
    WHERE status = 'Đang bảo trì'
")->fetchColumn();

$lowStock = (int)$conn->query("
    SELECT COUNT(*)
    FROM components
    WHERE quantity <= min_quantity
")->fetchColumn();

$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

// CẢNH BÁO LINH KIỆN SẮP HẾT
$lowStockItems = $conn->query("
    SELECT component_code, component_name, quantity, min_quantity
    FROM components
    WHERE quantity <= min_quantity
    ORDER BY quantity ASC
    LIMIT 10
")->fetchAll();

// CẢNH BÁO MƯỢN QUÁ HẠN
$overdueBorrows = $conn->query("
    SELECT b.borrower_name, b.expected_return_date,
           b.quantity, d.device_name, d.device_code
    FROM borrow_records b
    JOIN devices d ON b.device_id = d.id
    WHERE b.status = 'Đang mượn'
      AND b.expected_return_date < CURDATE()
    ORDER BY b.expected_return_date ASC
    LIMIT 10
")->fetchAll();

// CẢNH BÁO BẢO TRÌ QUÁ HẠN
$overdueMaintenance = $conn->query("
    SELECT m.expected_end_date, m.device_id,
           d.device_name, d.device_code, m.technician
    FROM maintenance_records m
    JOIN devices d ON m.device_id = d.id
    WHERE m.status = 'Đang bảo trì'
      AND m.expected_end_date IS NOT NULL
      AND m.expected_end_date < CURDATE()
    ORDER BY m.expected_end_date ASC
    LIMIT 10
")->fetchAll();

// DANH SÁCH THIẾT BỊ ĐANG BẢO TRÌ
$maintenanceItems = $conn->query("
    SELECT d.device_code, d.device_name,
           m.maintenance_date, m.expected_end_date
    FROM maintenance_records m
    JOIN devices d ON m.device_id = d.id
    WHERE m.status = 'Đang bảo trì'
    ORDER BY m.maintenance_date DESC
    LIMIT 10
")->fetchAll();

// BIỂU ĐỒ THIẾT BỊ
$deviceLabels = [];
$deviceValues = [];

$stmt = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM devices
    GROUP BY status
");

foreach ($stmt->fetchAll() as $row) {
    $deviceLabels[] = $row["status"];
    $deviceValues[] = (int)$row["total"];
}

// BIỂU ĐỒ MƯỢN TRẢ
$borrowLabels = [];
$borrowValues = [];

$stmt = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM borrow_records
    GROUP BY status
");

foreach ($stmt->fetchAll() as $row) {
    $borrowLabels[] = $row["status"];
    $borrowValues[] = (int)$row["total"];
}

// BIỂU ĐỒ NHẬP XUẤT KHO
$stockLabels = [];
$importValues = [];
$exportValues = [];

$stmt = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%m/%Y') AS month_name,
        SUM(CASE WHEN transaction_type = 'Nhập kho' THEN quantity ELSE 0 END) AS imported,
        SUM(CASE WHEN transaction_type = 'Xuất kho' THEN quantity ELSE 0 END) AS exported
    FROM stock_transactions
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%m/%Y')
    ORDER BY YEAR(created_at), MONTH(created_at)
");

foreach ($stmt->fetchAll() as $row) {
    $stockLabels[] = $row["month_name"];
    $importValues[] = (int)$row["imported"];
    $exportValues[] = (int)$row["exported"];
}

// GIAO DỊCH KHO GẦN ĐÂY
$recentTransactions = $conn->query("
    SELECT st.transaction_type, st.quantity, st.created_at,
           c.component_code, c.component_name
    FROM stock_transactions st
    LEFT JOIN components c ON st.component_id = c.id
    ORDER BY st.created_at DESC
    LIMIT 5
")->fetchAll();

// TỔNG SỐ CẢNH BÁO
$totalAlerts = count($lowStockItems)
             + count($overdueBorrows)
             + count($overdueMaintenance);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tổng quan - EQUIP CONTROL</title>

<link rel="stylesheet" href="/equip-control/assets/css/unified.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #0b1220;
    color: #f1f5f9;
    font-family: Arial, sans-serif;
}

.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    overflow-y: auto !important;
    z-index: 1000 !important;
}

.main-content.dashboard-page {
    margin-left: 250px !important;
    width: calc(100% - 250px) !important;
    min-height: 100vh;
    padding: 25px 30px;
}

.welcome-box {
    background: linear-gradient(120deg, #102c43, #12263a);
    border: 1px solid #29445c;
    border-radius: 14px;
    padding: 25px;
    margin-bottom: 28px;
}

.welcome-box h2 {
    color: #22d3ee;
    font-size: 23px;
    margin: 0 0 10px;
}

.welcome-box p {
    color: #a8c3dd;
    margin: 0;
    font-size: 14px;
}

.section-title {
    font-size: 19px;
    font-weight: bold;
    margin: 27px 0 18px;
    color: #f8fafc;
}

.stats-grid, .quick-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.stat-card, .quick-card, .chart-card, .table-card {
    background: #111f30;
    border: 1px solid #263d55;
    border-radius: 13px;
}

.stat-card {
    padding: 23px;
    min-height: 115px;
}

.stat-label {
    color: #9bb5cf;
    font-size: 13px;
    margin-bottom: 13px;
}

.stat-number {
    font-size: 30px;
    font-weight: bold;
    color: #22d3ee;
}

.stat-card.warning .stat-number { color: #fbbf24; }

.quick-card {
    display: block;
    text-decoration: none;
    padding: 20px;
    transition: .2s;
}

.quick-card:hover {
    border-color: #22d3ee;
    transform: translateY(-3px);
    background: #15283d;
}

.quick-card h3 {
    color: #22d3ee;
    font-size: 15px;
    margin: 0 0 10px;
}

.quick-card p {
    color: #9bb5cf;
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

.alert-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: #182638;
    border: 1px solid #344b62;
    border-radius: 12px;
    padding: 17px 20px;
    margin-bottom: 16px;
}

.alert-summary strong {
    color: #fbbf24;
    font-size: 24px;
}

.alert-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.alert-card {
    background: #111f30;
    border: 1px solid #344b62;
    border-radius: 12px;
    padding: 18px;
    min-width: 0;
}

.alert-card h3 {
    font-size: 15px;
    margin: 0 0 14px;
}

.alert-card.danger { border-left: 4px solid #f87171; }
.alert-card.warning { border-left: 4px solid #fbbf24; }
.alert-card.info { border-left: 4px solid #22d3ee; }

.alert-item {
    padding: 11px 0;
    border-bottom: 1px solid #263d55;
    color: #cbd5e1;
    font-size: 13px;
    line-height: 1.6;
}

.alert-item:last-child { border-bottom: none; }

.alert-item strong { color: #f8fafc; }

.alert-empty {
    color: #4ade80;
    font-size: 13px;
    padding: 10px 0;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.chart-card {
    padding: 20px;
    min-width: 0;
}

.chart-card h3 {
    color: #e2e8f0;
    font-size: 15px;
    margin: 0 0 18px;
}

.chart-container {
    position: relative;
    height: 260px;
}

.table-card {
    padding: 20px;
    overflow-x: auto;
}

.dashboard-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 550px;
}

.dashboard-table th, .dashboard-table td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid #263d55;
    font-size: 13px;
}

.dashboard-table th {
    color: #22d3ee;
}

.dashboard-table td { color: #cbd5e1; }

.type-import {
    color: #4ade80 !important;
    font-weight: bold;
}

.type-export {
    color: #fb923c !important;
    font-weight: bold;
}

.empty-data {
    text-align: center !important;
    color: #94a3b8 !important;
    padding: 25px !important;
}

@media (max-width: 1000px) {
    .stats-grid, .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .sidebar { width: 210px !important; }

    .main-content.dashboard-page {
        margin-left: 210px !important;
        width: calc(100% - 210px) !important;
        padding: 20px 12px;
    }

    .stats-grid, .quick-grid, .charts-grid, .alert-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content dashboard-page">

<div class="welcome-box">
    <h2>Xin chào, <?= h($role === "admin" ? "Quản trị viên" : $fullName) ?>!</h2>
    <p>Chào mừng mày quay lại hệ thống quản lý thiết bị và linh kiện điện tử.</p>
</div>

<h2 class="section-title">TỔNG QUAN HỆ THỐNG</h2>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Tổng số thiết bị</div>
        <div class="stat-number"><?= $totalDevices ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Tổng số linh kiện</div>
        <div class="stat-number"><?= $totalComponents ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Thiết bị đang được mượn</div>
        <div class="stat-number"><?= $totalBorrowing ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Thiết bị đang bảo trì</div>
        <div class="stat-number"><?= $totalMaintenance ?></div>
    </div>

    <div class="stat-card warning">
        <div class="stat-label">Linh kiện sắp hết hàng</div>
        <div class="stat-number"><?= $lowStock ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Tổng tài khoản</div>
        <div class="stat-number"><?= $totalUsers ?></div>
    </div>
</div>

<!-- KHU VỰC CẢNH BÁO -->
<h2 class="section-title">🚨 CẢNH BÁO HỆ THỐNG</h2>

<div class="alert-summary">
    <div>
        <strong><?= $totalAlerts ?></strong>
        <div style="color:#a8bfd5;font-size:13px;margin-top:5px;">
            Tổng số cảnh báo cần chú ý
        </div>
    </div>

    <div style="color:#94a3b8;font-size:13px;">
        Dữ liệu được cập nhật khi tải lại trang
    </div>
</div>

<div class="alert-grid">

    <div class="alert-card warning">
        <h3 style="color:#fbbf24;">📦 Linh kiện sắp hết hàng (<?= count($lowStockItems) ?>)</h3>

        <?php if ($lowStockItems): ?>
            <?php foreach ($lowStockItems as $item): ?>
                <div class="alert-item">
                    <strong><?= h($item["component_name"]) ?></strong>
                    (<?= h($item["component_code"]) ?>)<br>
                    Tồn kho: <?= (int)$item["quantity"] ?> -
                    Mức tối thiểu: <?= (int)$item["min_quantity"] ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert-empty">✓ Không có linh kiện nào sắp hết hàng.</div>
        <?php endif; ?>
    </div>

    <div class="alert-card danger">
        <h3 style="color:#f87171;">⏰ Thiết bị mượn quá hạn (<?= count($overdueBorrows) ?>)</h3>

        <?php if ($overdueBorrows): ?>
            <?php foreach ($overdueBorrows as $item): ?>
                <div class="alert-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Người mượn: <?= h($item["borrower_name"]) ?><br>
                    Hạn trả: <?= date("d/m/Y", strtotime($item["expected_return_date"])) ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert-empty">✓ Không có thiết bị mượn quá hạn.</div>
        <?php endif; ?>
    </div>

    <div class="alert-card danger">
        <h3 style="color:#f87171;">🛠️ Bảo trì quá hạn (<?= count($overdueMaintenance) ?>)</h3>

        <?php if ($overdueMaintenance): ?>
            <?php foreach ($overdueMaintenance as $item): ?>
                <div class="alert-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Kỹ thuật viên: <?= h($item["technician"] ?: "Chưa cập nhật") ?><br>
                    Hạn hoàn thành: <?= date("d/m/Y", strtotime($item["expected_end_date"])) ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert-empty">✓ Không có công việc bảo trì quá hạn.</div>
        <?php endif; ?>
    </div>

    <div class="alert-card info">
        <h3 style="color:#22d3ee;">🔧 Thiết bị đang bảo trì (<?= count($maintenanceItems) ?>)</h3>

        <?php if ($maintenanceItems): ?>
            <?php foreach ($maintenanceItems as $item): ?>
                <div class="alert-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Ngày bắt đầu: <?= date("d/m/Y", strtotime($item["maintenance_date"])) ?><br>
                    Dự kiến hoàn thành:
                    <?= $item["expected_end_date"] ? date("d/m/Y", strtotime($item["expected_end_date"])) : "Chưa xác định" ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert-empty">✓ Hiện không có thiết bị đang bảo trì.</div>
        <?php endif; ?>
    </div>

</div>

<!-- BIỂU ĐỒ -->
<h2 class="section-title">BIỂU ĐỒ THỐNG KÊ</h2>

<div class="charts-grid">
    <div class="chart-card">
        <h3>Tình trạng thiết bị</h3>
        <div class="chart-container"><canvas id="deviceChart"></canvas></div>
    </div>

    <div class="chart-card">
        <h3>Tình trạng mượn trả</h3>
        <div class="chart-container"><canvas id="borrowChart"></canvas></div>
    </div>

    <div class="chart-card">
        <h3>Thống kê nhập xuất kho</h3>
        <div class="chart-container"><canvas id="stockChart"></canvas></div>
    </div>

    <div class="chart-card">
        <h3>Tóm tắt hoạt động hệ thống</h3>
        <div class="chart-container"><canvas id="summaryChart"></canvas></div>
    </div>
</div>

<!-- TRUY CẬP NHANH -->
<h2 class="section-title">TRUY CẬP NHANH</h2>

<div class="quick-grid">
    <a href="/equip-control/devices.php" class="quick-card">
        <h3>💻 Quản lý thiết bị</h3>
        <p>Thêm, sửa, xóa và theo dõi thiết bị.</p>
    </a>

    <a href="/equip-control/components.php" class="quick-card">
        <h3>🔧 Quản lý linh kiện</h3>
        <p>Quản lý thông tin và số lượng linh kiện.</p>
    </a>

    <a href="/equip-control/stock.php" class="quick-card">
        <h3>📦 Nhập - xuất kho</h3>
        <p>Quản lý lịch sử nhập và xuất linh kiện.</p>
    </a>

    <a href="/equip-control/borrow.php" class="quick-card">
        <h3>🔄 Mượn - trả</h3>
        <p>Theo dõi thiết bị đang được mượn.</p>
    </a>

    <a href="/equip-control/maintenance.php" class="quick-card">
        <h3>🛠️ Bảo trì thiết bị</h3>
        <p>Quản lý tình trạng và chi phí bảo trì.</p>
    </a>

    <a href="/equip-control/reports.php" class="quick-card">
        <h3>📈 Báo cáo - thống kê</h3>
        <p>Xem số liệu hoạt động của hệ thống.</p>
    </a>

    <?php if ($role === "admin"): ?>
    <a href="/equip-control/users.php" class="quick-card">
        <h3>👥 Quản lý tài khoản</h3>
        <p>Tạo, chỉnh sửa, khóa và mở khóa tài khoản.</p>
    </a>
    <?php endif; ?>
</div>

<!-- GIAO DỊCH GẦN ĐÂY -->
<h2 class="section-title">GIAO DỊCH KHO GẦN ĐÂY</h2>

<div class="table-card">
<table class="dashboard-table">
    <thead>
        <tr>
            <th>Mã linh kiện</th>
            <th>Tên linh kiện</th>
            <th>Loại giao dịch</th>
            <th>Số lượng</th>
            <th>Thời gian</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($recentTransactions): ?>
        <?php foreach ($recentTransactions as $item): ?>
        <tr>
            <td><?= h($item["component_code"] ?? "—") ?></td>
            <td><?= h($item["component_name"] ?? "—") ?></td>
            <td class="<?= $item["transaction_type"] === "Nhập kho" ? "type-import" : "type-export" ?>">
                <?= h($item["transaction_type"]) ?>
            </td>
            <td><?= (int)$item["quantity"] ?></td>
            <td><?= date("d/m/Y H:i", strtotime($item["created_at"])) ?></td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5" class="empty-data">Chưa có giao dịch kho nào.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

</main>

<script>
Chart.defaults.color = "#a8bfd5";
Chart.defaults.font.family = "Arial";

const deviceLabels = <?= json_encode($deviceLabels, JSON_UNESCAPED_UNICODE) ?>;
const deviceValues = <?= json_encode($deviceValues) ?>;
const borrowLabels = <?= json_encode($borrowLabels, JSON_UNESCAPED_UNICODE) ?>;
const borrowValues = <?= json_encode($borrowValues) ?>;
const stockLabels = <?= json_encode($stockLabels, JSON_UNESCAPED_UNICODE) ?>;
const importValues = <?= json_encode($importValues) ?>;
const exportValues = <?= json_encode($exportValues) ?>;

new Chart(document.getElementById("deviceChart"), {
    type: "doughnut",
    data: {
        labels: deviceLabels,
        datasets: [{
            data: deviceValues,
            backgroundColor: ["#22d3ee", "#fbbf24", "#f87171"],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } }
    }
});

new Chart(document.getElementById("borrowChart"), {
    type: "pie",
    data: {
        labels: borrowLabels,
        datasets: [{
            data: borrowValues,
            backgroundColor: ["#22d3ee", "#4ade80"],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } }
    }
});

new Chart(document.getElementById("stockChart"), {
    type: "bar",
    data: {
        labels: stockLabels,
        datasets: [
            {
                label: "Nhập kho",
                data: importValues,
                backgroundColor: "#22d3ee",
                borderRadius: 5
            },
            {
                label: "Xuất kho",
                data: exportValues,
                backgroundColor: "#fb923c",
                borderRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { grid: { color: "rgba(148,163,184,.12)" } },
            y: { beginAtZero: true, grid: { color: "rgba(148,163,184,.12)" } }
        },
        plugins: { legend: { position: "bottom" } }
    }
});

new Chart(document.getElementById("summaryChart"), {
    type: "bar",
    data: {
        labels: ["Thiết bị", "Linh kiện", "Đang mượn", "Bảo trì", "Sắp hết hàng"],
        datasets: [{
            label: "Số lượng",
            data: [
                <?= $totalDevices ?>,
                <?= $totalComponents ?>,
                <?= $totalBorrowing ?>,
                <?= $totalMaintenance ?>,
                <?= $lowStock ?>
            ],
            backgroundColor: ["#22d3ee", "#38bdf8", "#4ade80", "#fbbf24", "#fb923c"],
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: "rgba(148,163,184,.12)" } }
        },
        plugins: { legend: { display: false } }
    }
});
</script>

</body>
</html>
