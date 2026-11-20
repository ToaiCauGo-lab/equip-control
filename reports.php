
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireLogin();

// ================= THỐNG KÊ THIẾT BỊ =================
$totalDevices = (int)$conn->query("SELECT COUNT(*) FROM devices")->fetchColumn();

$usingDevices = (int)$conn->query("
    SELECT COUNT(*) FROM devices WHERE status = 'Đang sử dụng'
")->fetchColumn();

$repairDevices = (int)$conn->query("
    SELECT COUNT(*) FROM devices WHERE status = 'Đang bảo trì'
")->fetchColumn();

$stoppedDevices = (int)$conn->query("
    SELECT COUNT(*) FROM devices WHERE status = 'Ngừng sử dụng'
")->fetchColumn();

// ================= THỐNG KÊ LINH KIỆN =================
$totalComponents = (int)$conn->query("SELECT COUNT(*) FROM components")->fetchColumn();

$totalStock = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0) FROM components
")->fetchColumn();

$lowStock = (int)$conn->query("
    SELECT COUNT(*) FROM components WHERE quantity <= min_quantity
")->fetchColumn();

$totalImport = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_transactions
    WHERE transaction_type = 'Nhập kho'
")->fetchColumn();

$totalExport = (int)$conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_transactions
    WHERE transaction_type = 'Xuất kho'
")->fetchColumn();

// ================= THỐNG KÊ MƯỢN TRẢ =================
$activeBorrow = (int)$conn->query("
    SELECT COUNT(*) FROM borrow_records WHERE status = 'Đang mượn'
")->fetchColumn();

$returnedBorrow = (int)$conn->query("
    SELECT COUNT(*) FROM borrow_records WHERE status = 'Đã trả'
")->fetchColumn();

$overdueBorrow = (int)$conn->query("
    SELECT COUNT(*)
    FROM borrow_records
    WHERE status = 'Đang mượn'
    AND expected_return_date < CURDATE()
")->fetchColumn();

// ================= THỐNG KÊ BẢO TRÌ =================
$activeMaintenance = (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_records
    WHERE status = 'Đang bảo trì'
")->fetchColumn();

$completedMaintenance = (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_records
    WHERE status = 'Đã hoàn thành'
")->fetchColumn();

$totalMaintenanceCost = (float)$conn->query("
    SELECT COALESCE(SUM(maintenance_cost), 0)
    FROM maintenance_records
")->fetchColumn();

// ================= DỮ LIỆU BIỂU ĐỒ =================
$componentChartQuery = $conn->query("
    SELECT component_name, quantity
    FROM components
    ORDER BY quantity DESC
    LIMIT 8
");

$componentLabels = [];
$componentValues = [];

foreach ($componentChartQuery->fetchAll() as $item) {
    $componentLabels[] = $item["component_name"];
    $componentValues[] = (int)$item["quantity"];
}

// ================= DỮ LIỆU BẢNG =================
$recentBorrows = $conn->query("
    SELECT b.*, d.device_name, d.device_code
    FROM borrow_records b
    LEFT JOIN devices d ON b.device_id = d.id
    ORDER BY b.created_at DESC
    LIMIT 10
")->fetchAll();

$recentMaintenance = $conn->query("
    SELECT m.*, d.device_name, d.device_code
    FROM maintenance_records m
    LEFT JOIN devices d ON m.device_id = d.id
    ORDER BY m.created_at DESC
    LIMIT 10
")->fetchAll();

$lowStockItems = $conn->query("
    SELECT *
    FROM components
    WHERE quantity <= min_quantity
    ORDER BY quantity ASC
    LIMIT 10
")->fetchAll();

// ================= HÀM HỖ TRỢ =================
function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function moneyFormat($value) {
    return number_format((float)$value, 0, ",", ".") . " đ";
}

$deviceChart = [$usingDevices, $repairDevices, $stoppedDevices];
$borrowChart = [$activeBorrow, $returnedBorrow];
$maintenanceChart = [$activeMaintenance, $completedMaintenance];
$stockChart = [$totalImport, $totalExport];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Báo cáo thống kê - EQUIP CONTROL</title>

<link rel="stylesheet" href="/assets/css/style.css?v=14">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    background: #0b1120 !important;
    color: #d4e1ef;
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

.report-content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 25px;
    margin: 0;
}

/* TIÊU ĐỀ */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.report-header h1 {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.report-header p {
    color: #91a6bd;
    font-size: 13px;
    margin: 0;
}

/* NÚT IN */
.print-button {
    border: 0;
    border-radius: 7px;
    padding: 10px 15px;
    background: #0891b2;
    color: white;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.print-button:hover {
    background: #0e7490;
}

/* KHU VỰC THỐNG KÊ */
.report-section {
    margin-bottom: 28px;
    width: 100%;
    min-width: 0;
}

.section-title {
    color: #22d3ee;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 16px;
    padding-left: 12px;
    border-left: 4px solid #22d3ee;
}

/* CÁC Ô THỐNG KÊ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 18px;
}

.stats-grid.five {
    grid-template-columns: repeat(5, minmax(0, 1fr));
}

.stats-grid.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.stat-card {
    background: #111f31 !important;
    border: 1px solid #263b52 !important;
    border-radius: 11px;
    padding: 19px;
    min-width: 0;
    color: #d4e1ef !important;
}

.stat-card .stat-label {
    display: block;
    color: #91a6bd !important;
    font-size: 12px;
    margin-bottom: 10px;
}

.stat-card .stat-value {
    display: block;
    color: #22d3ee !important;
    font-size: 25px;
    font-weight: 700;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.stat-card .stat-note {
    color: #91a6bd !important;
    font-size: 12px;
    margin-top: 7px;
}

/* BIỂU ĐỒ */
.chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 18px;
}

.chart-card,
.table-card {
    background: #111f31 !important;
    border: 1px solid #263b52 !important;
    border-radius: 12px;
    padding: 20px;
    min-width: 0;
    color: #d4e1ef !important;
}

.chart-card h3,
.table-card h3 {
    color: #22d3ee !important;
    font-size: 15px;
    margin: 0 0 18px;
    font-weight: 700;
}

.chart-container {
    position: relative;
    height: 280px;
    width: 100%;
}

/* BẢNG */
.table-card {
    overflow: hidden;
    margin-bottom: 18px;
}

.table-responsive {
    overflow-x: auto;
    width: 100%;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 600px;
    background: transparent !important;
}

.report-table thead,
.report-table thead tr {
    background: #17283c !important;
}

.report-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c !important;
    color: #22d3ee !important;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    border-bottom: 1px solid #263b52;
}

.report-table td {
    padding: 13px 11px;
    text-align: left;
    color: #d4e1ef !important;
    background: transparent !important;
    border-bottom: 1px solid #263b52;
}

.report-table tbody tr {
    background: #111f31 !important;
}

.report-table tbody tr:hover {
    background: rgba(34, 211, 238, .05) !important;
}

.report-table tr:last-child td {
    border-bottom: none;
}

.empty-data {
    text-align: center !important;
    color: #91a6bd !important;
    padding: 25px !important;
}

/* TRẠNG THÁI */
.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.status-warning {
    background: #49371e !important;
    color: #fcd34d !important;
}

.status-success {
    background: #123c32 !important;
    color: #86efac !important;
}

.status-danger {
    background: #49252b !important;
    color: #fca5a5 !important;
}

.status-info {
    background: #173653 !important;
    color: #7dd3fc !important;
}

/* RESPONSIVE */
@media(max-width: 1100px) {
    .stats-grid,
    .stats-grid.five {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 950px) {
    .sidebar {
        width: 220px !important;
    }

    .main-content {
        margin-left: 220px !important;
        width: calc(100% - 220px) !important;
    }
}

@media(max-width: 768px) {
    .report-content {
        padding: 16px;
    }
}

@media(max-width: 700px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid,
    .stats-grid.five,
    .stats-grid.three {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .report-header h1 {
        font-size: 22px;
    }

    .stat-card .stat-value {
        font-size: 22px;
    }
}

@media(max-width: 600px) {
    .stats-grid,
    .stats-grid.five,
    .stats-grid.three {
        grid-template-columns: 1fr;
    }
}

/* IN BÁO CÁO */
@media print {
    .sidebar,
    .print-button,
    .menu-toggle {
        display: none !important;
    }

    html,
    body {
        background: white !important;
        color: #111827 !important;
    }

    .main-content {
        margin: 0 !important;
        width: 100% !important;
        padding: 0 !important;
        background: white !important;
    }

    .report-content {
        padding: 10px !important;
    }

    .stat-card,
    .chart-card,
    .table-card {
        break-inside: avoid;
        box-shadow: none !important;
    }
}
</style>
</head>

<body>

<div class="page-container">

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
<div class="report-content">

    <div class="report-header">
        <div>
            <h1>BÁO CÁO THỐNG KÊ</h1>
            <p>Tổng hợp tình hình quản lý thiết bị, linh kiện và hoạt động hệ thống</p>
        </div>

        <button class="print-button" onclick="window.print()">
            IN BÁO CÁO
        </button>
    </div>

    <!-- THỐNG KÊ THIẾT BỊ -->
    <section class="report-section">

        <h2 class="section-title">THỐNG KÊ THIẾT BỊ</h2>

        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-label">Tổng số thiết bị</div>
                <div class="stat-value"><?= number_format($totalDevices) ?></div>
                <div class="stat-note">Tất cả thiết bị trong hệ thống</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đang sử dụng</div>
                <div class="stat-value"><?= number_format($usingDevices) ?></div>
                <div class="stat-note">Thiết bị đang hoạt động</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đang bảo trì</div>
                <div class="stat-value"><?= number_format($repairDevices) ?></div>
                <div class="stat-note">Thiết bị cần kiểm tra, sửa chữa</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Ngừng sử dụng</div>
                <div class="stat-value"><?= number_format($stoppedDevices) ?></div>
                <div class="stat-note">Thiết bị không còn hoạt động</div>
            </div>

        </div>

        <div class="chart-grid">

            <div class="chart-card">
                <h3>Tỷ lệ trạng thái thiết bị</h3>
                <div class="chart-container">
                    <canvas id="deviceChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h3>Tổng quan số lượng thiết bị</h3>
                <div class="chart-container">
                    <canvas id="deviceBarChart"></canvas>
                </div>
            </div>

        </div>

    </section>

    <!-- THỐNG KÊ LINH KIỆN -->
    <section class="report-section">

        <h2 class="section-title">THỐNG KÊ LINH KIỆN</h2>

        <div class="stats-grid five">

            <div class="stat-card">
                <div class="stat-label">Tổng loại linh kiện</div>
                <div class="stat-value"><?= number_format($totalComponents) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Tổng tồn kho</div>
                <div class="stat-value"><?= number_format($totalStock) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Sắp hết hàng</div>
                <div class="stat-value"><?= number_format($lowStock) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Tổng nhập kho</div>
                <div class="stat-value"><?= number_format($totalImport) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Tổng xuất kho</div>
                <div class="stat-value"><?= number_format($totalExport) ?></div>
            </div>

        </div>

        <div class="chart-grid">

            <div class="chart-card">
                <h3>Top linh kiện có số lượng tồn kho cao</h3>
                <div class="chart-container">
                    <canvas id="componentChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h3>So sánh nhập kho và xuất kho</h3>
                <div class="chart-container">
                    <canvas id="stockChart"></canvas>
                </div>
            </div>

        </div>

        <div class="table-card">

            <h3>Danh sách linh kiện sắp hết hàng</h3>

            <div class="table-responsive">
                <table class="report-table">

                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Tên linh kiện</th>
                            <th>Số lượng tồn</th>
                            <th>Mức tồn tối thiểu</th>
                            <th>Tình trạng</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($lowStockItems)): ?>

                        <tr>
                            <td colspan="5" class="empty-data">
                                Không có linh kiện nào sắp hết hàng
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($lowStockItems as $index => $item): ?>

                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= e($item["component_name"] ?? "") ?></td>
                                <td><?= number_format((int)($item["quantity"] ?? 0)) ?></td>
                                <td><?= number_format((int)($item["min_quantity"] ?? 0)) ?></td>
                                <td>
                                    <span class="status-badge status-danger">
                                        Cần nhập thêm
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

    </section>

    <!-- THỐNG KÊ MƯỢN TRẢ -->
    <section class="report-section">

        <h2 class="section-title">THỐNG KÊ MƯỢN TRẢ THIẾT BỊ</h2>

        <div class="stats-grid three">

            <div class="stat-card">
                <div class="stat-label">Đang mượn</div>
                <div class="stat-value"><?= number_format($activeBorrow) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đã trả</div>
                <div class="stat-value"><?= number_format($returnedBorrow) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Quá hạn trả</div>
                <div class="stat-value"><?= number_format($overdueBorrow) ?></div>
            </div>

        </div>

        <div class="chart-card" style="margin-bottom:18px">

            <h3>Tình trạng mượn trả thiết bị</h3>

            <div class="chart-container">
                <canvas id="borrowChart"></canvas>
            </div>

        </div>

        <div class="table-card">

            <h3>Hoạt động mượn trả gần đây</h3>

            <div class="table-responsive">
                <table class="report-table">

                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Mã thiết bị</th>
                            <th>Tên thiết bị</th>
                            <th>Người mượn</th>
                            <th>Ngày mượn</th>
                            <th>Ngày trả dự kiến</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($recentBorrows)): ?>

                        <tr>
                            <td colspan="7" class="empty-data">
                                Chưa có dữ liệu mượn trả
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentBorrows as $index => $borrow): ?>

                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= e($borrow["device_code"] ?? "") ?></td>
                                <td><?= e($borrow["device_name"] ?? "") ?></td>
                                <td><?= e($borrow["borrower_name"] ?? $borrow["user_name"] ?? "") ?></td>
                                <td><?= e($borrow["borrow_date"] ?? "") ?></td>
                                <td><?= e($borrow["expected_return_date"] ?? "") ?></td>

                                <td>
                                    <?php
                                    $borrowStatus = $borrow["status"] ?? "";
                                    $borrowClass = "status-info";

                                    if ($borrowStatus === "Đã trả") {
                                        $borrowClass = "status-success";
                                    } elseif ($borrowStatus === "Đang mượn") {
                                        $borrowClass = "status-warning";
                                    }
                                    ?>

                                    <span class="status-badge <?= $borrowClass ?>">
                                        <?= e($borrowStatus) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

    </section>

    <!-- THỐNG KÊ BẢO TRÌ -->
    <section class="report-section">

        <h2 class="section-title">THỐNG KÊ BẢO TRÌ</h2>

        <div class="stats-grid three">

            <div class="stat-card">
                <div class="stat-label">Đang bảo trì</div>
                <div class="stat-value"><?= number_format($activeMaintenance) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đã hoàn thành</div>
                <div class="stat-value"><?= number_format($completedMaintenance) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Tổng chi phí bảo trì</div>
                <div class="stat-value" style="font-size:22px">
                    <?= moneyFormat($totalMaintenanceCost) ?>
                </div>
            </div>

        </div>

        <div class="chart-card" style="margin-bottom:18px">

            <h3>Tình trạng bảo trì thiết bị</h3>

            <div class="chart-container">
                <canvas id="maintenanceChart"></canvas>
            </div>

        </div>

        <div class="table-card">

            <h3>Lịch sử bảo trì gần đây</h3>

            <div class="table-responsive">
                <table class="report-table">

                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Mã thiết bị</th>
                            <th>Tên thiết bị</th>
                            <th>Nội dung bảo trì</th>
                            <th>Chi phí</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($recentMaintenance)): ?>

                        <tr>
                            <td colspan="6" class="empty-data">
                                Chưa có dữ liệu bảo trì
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentMaintenance as $index => $maintenance): ?>

                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= e($maintenance["device_code"] ?? "") ?></td>
                                <td><?= e($maintenance["device_name"] ?? "") ?></td>

                                <td>
                                    <?= e($maintenance["description"] ?? $maintenance["maintenance_description"] ?? "") ?>
                                </td>

                                <td>
                                    <?= moneyFormat($maintenance["maintenance_cost"] ?? 0) ?>
                                </td>

                                <td>
                                    <?php
                                    $maintenanceStatus = $maintenance["status"] ?? "";

                                    $maintenanceClass = $maintenanceStatus === "Đã hoàn thành"
                                        ? "status-success"
                                        : "status-warning";
                                    ?>

                                    <span class="status-badge <?= $maintenanceClass ?>">
                                        <?= e($maintenanceStatus) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

    </section>

</div>
</main>

</div>

<script>
// ================= CẤU HÌNH BIỂU ĐỒ =================
Chart.defaults.color = "#91a6bd";
Chart.defaults.font.family = "Arial, sans-serif";

const chartTextColor = "#91a6bd";
const chartGridColor = "rgba(130, 160, 190, 0.12)";

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,

    plugins: {
        legend: {
            labels: {
                color: chartTextColor,
                font: {
                    family: "Arial, sans-serif",
                    size: 12
                }
            }
        }
    }
};

// ================= DỮ LIỆU =================
const deviceData = <?= json_encode($deviceChart, JSON_UNESCAPED_UNICODE) ?>;
const borrowData = <?= json_encode($borrowChart, JSON_UNESCAPED_UNICODE) ?>;
const maintenanceData = <?= json_encode($maintenanceChart, JSON_UNESCAPED_UNICODE) ?>;
const stockData = <?= json_encode($stockChart, JSON_UNESCAPED_UNICODE) ?>;
const componentLabels = <?= json_encode($componentLabels, JSON_UNESCAPED_UNICODE) ?>;
const componentValues = <?= json_encode($componentValues, JSON_UNESCAPED_UNICODE) ?>;

// ================= BIỂU ĐỒ TRẠNG THÁI THIẾT BỊ =================
new Chart(document.getElementById("deviceChart"), {
    type: "doughnut",

    data: {
        labels: ["Đang sử dụng", "Đang bảo trì", "Ngừng sử dụng"],

        datasets: [{
            data: deviceData,
            backgroundColor: ["#22c55e", "#f59e0b", "#ef4444"],
            borderWidth: 0
        }]
    },

    options: {
        ...commonOptions,
        cutout: "65%"
    }
});

// ================= BIỂU ĐỒ SỐ LƯỢNG THIẾT BỊ =================
new Chart(document.getElementById("deviceBarChart"), {
    type: "bar",

    data: {
        labels: ["Đang sử dụng", "Đang bảo trì", "Ngừng sử dụng"],

        datasets: [{
            label: "Số lượng thiết bị",
            data: deviceData,
            backgroundColor: ["#22c55e", "#f59e0b", "#ef4444"],
            borderRadius: 7
        }]
    },

    options: {
        ...commonOptions,

        scales: {
            x: {
                ticks: {
                    color: chartTextColor
                },
                grid: {
                    display: false
                }
            },

            y: {
                beginAtZero: true,

                ticks: {
                    color: chartTextColor,
                    precision: 0
                },

                grid: {
                    color: chartGridColor
                }
            }
        }
    }
});

// ================= BIỂU ĐỒ LINH KIỆN =================
new Chart(document.getElementById("componentChart"), {
    type: "bar",

    data: {
        labels: componentLabels,

        datasets: [{
            label: "Số lượng tồn kho",
            data: componentValues,
            backgroundColor: "#0891b2",
            borderRadius: 7
        }]
    },

    options: {
        ...commonOptions,
        indexAxis: "y",

        scales: {
            x: {
                beginAtZero: true,

                ticks: {
                    color: chartTextColor,
                    precision: 0
                },

                grid: {
                    color: chartGridColor
                }
            },

            y: {
                ticks: {
                    color: chartTextColor
                },

                grid: {
                    display: false
                }
            }
        }
    }
});

// ================= BIỂU ĐỒ NHẬP XUẤT KHO =================
new Chart(document.getElementById("stockChart"), {
    type: "bar",

    data: {
        labels: ["Nhập kho", "Xuất kho"],

        datasets: [{
            label: "Số lượng",
            data: stockData,
            backgroundColor: ["#22c55e", "#f59e0b"],
            borderRadius: 7
        }]
    },

    options: {
        ...commonOptions,

        scales: {
            x: {
                ticks: {
                    color: chartTextColor
                },

                grid: {
                    display: false
                }
            },

            y: {
                beginAtZero: true,

                ticks: {
                    color: chartTextColor,
                    precision: 0
                },

                grid: {
                    color: chartGridColor
                }
            }
        }
    }
});

// ================= BIỂU ĐỒ MƯỢN TRẢ =================
new Chart(document.getElementById("borrowChart"), {
    type: "pie",

    data: {
        labels: ["Đang mượn", "Đã trả"],

        datasets: [{
            data: borrowData,
            backgroundColor: ["#f59e0b", "#22c55e"],
            borderWidth: 0
        }]
    },

    options: commonOptions
});

// ================= BIỂU ĐỒ BẢO TRÌ =================
new Chart(document.getElementById("maintenanceChart"), {
    type: "doughnut",

    data: {
        labels: ["Đang bảo trì", "Đã hoàn thành"],

        datasets: [{
            data: maintenanceData,
            backgroundColor: ["#f59e0b", "#22c55e"],
            borderWidth: 0
        }]
    },

    options: {
        ...commonOptions,
        cutout: "65%"
    }
});
</script>

</body>
</html>
