
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireLogin();

// ================== THỐNG KÊ THIẾT BỊ ==================
$totalDevices = (int)$conn->query(
    "SELECT COUNT(*) FROM devices"
)->fetchColumn();

$usingDevices = (int)$conn->query(
    "SELECT COUNT(*) FROM devices WHERE status = 'Đang sử dụng'"
)->fetchColumn();

$repairDevices = (int)$conn->query(
    "SELECT COUNT(*) FROM devices WHERE status = 'Đang bảo trì'"
)->fetchColumn();

$stoppedDevices = (int)$conn->query(
    "SELECT COUNT(*) FROM devices WHERE status = 'Ngừng sử dụng'"
)->fetchColumn();

// ================== THỐNG KÊ LINH KIỆN ==================
$totalComponents = (int)$conn->query(
    "SELECT COUNT(*) FROM components"
)->fetchColumn();

$totalStock = (int)$conn->query(
    "SELECT COALESCE(SUM(quantity), 0) FROM components"
)->fetchColumn();

$lowStock = (int)$conn->query(
    "SELECT COUNT(*) FROM components
     WHERE quantity <= min_quantity"
)->fetchColumn();

$totalImport = (int)$conn->query(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM stock_transactions
     WHERE transaction_type = 'Nhập kho'"
)->fetchColumn();

$totalExport = (int)$conn->query(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM stock_transactions
     WHERE transaction_type = 'Xuất kho'"
)->fetchColumn();

// ================== THỐNG KÊ MƯỢN TRẢ ==================
$activeBorrow = (int)$conn->query(
    "SELECT COUNT(*) FROM borrow_records
     WHERE status = 'Đang mượn'"
)->fetchColumn();

$returnedBorrow = (int)$conn->query(
    "SELECT COUNT(*) FROM borrow_records
     WHERE status = 'Đã trả'"
)->fetchColumn();

$overdueBorrow = (int)$conn->query(
    "SELECT COUNT(*) FROM borrow_records
     WHERE status = 'Đang mượn'
     AND expected_return_date < CURDATE()"
)->fetchColumn();

// ================== THỐNG KÊ BẢO TRÌ ==================
$activeMaintenance = (int)$conn->query(
    "SELECT COUNT(*) FROM maintenance_records
     WHERE status = 'Đang bảo trì'"
)->fetchColumn();

$completedMaintenance = (int)$conn->query(
    "SELECT COUNT(*) FROM maintenance_records
     WHERE status = 'Đã hoàn thành'"
)->fetchColumn();

$totalMaintenanceCost = (float)$conn->query(
    "SELECT COALESCE(SUM(maintenance_cost), 0)
     FROM maintenance_records"
)->fetchColumn();

// ================== DỮ LIỆU BIỂU ĐỒ LINH KIỆN ==================
$componentChartQuery = $conn->query(
    "SELECT component_name, quantity
     FROM components
     ORDER BY quantity DESC
     LIMIT 8"
);

$componentLabels = [];
$componentValues = [];

foreach ($componentChartQuery->fetchAll() as $item) {
    $componentLabels[] = $item["component_name"];
    $componentValues[] = (int)$item["quantity"];
}

// ================== LỊCH SỬ MƯỢN TRẢ ==================
$recentBorrows = $conn->query(
    "SELECT b.*, d.device_name, d.device_code
     FROM borrow_records b
     LEFT JOIN devices d ON b.device_id = d.id
     ORDER BY b.created_at DESC
     LIMIT 10"
)->fetchAll();

// ================== LỊCH SỬ BẢO TRÌ ==================
$recentMaintenance = $conn->query(
    "SELECT m.*, d.device_name, d.device_code
     FROM maintenance_records m
     LEFT JOIN devices d ON m.device_id = d.id
     ORDER BY m.created_at DESC
     LIMIT 10"
)->fetchAll();

// ================== LINH KIỆN SẮP HẾT ==================
$lowStockItems = $conn->query(
    "SELECT *
     FROM components
     WHERE quantity <= min_quantity
     ORDER BY quantity ASC
     LIMIT 10"
)->fetchAll();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function moneyFormat($value) {
    return number_format((float)$value, 0, ",", ".") . " đ";
}

$deviceChart = [
    $usingDevices,
    $repairDevices,
    $stoppedDevices
];

$borrowChart = [
    $activeBorrow,
    $returnedBorrow
];

$maintenanceChart = [
    $activeMaintenance,
    $completedMaintenance
];

$stockChart = [
    $totalImport,
    $totalExport
];

echo '<link rel="stylesheet" href="/equip-control/assets/css/unified.css">';

require __DIR__ . "/includes/sidebar.php";
?>

<main class="main-content report-page">

    <div class="report-header">
        <div>
            <h1>BÁO CÁO THỐNG KÊ</h1>
            <p>Tổng hợp tình hình hoạt động của hệ thống EQUIP CONTROL.</p>
        </div>

        <button class="print-button" onclick="window.print()">
            <span>IN / LƯU PDF</span>
        </button>
    </div>

    <!-- THỐNG KÊ THIẾT BỊ -->
    <section class="report-section">
        <h2>1. THỐNG KÊ THIẾT BỊ</h2>

        <div class="report-stats">
            <div class="report-stat">
                <span>Tổng thiết bị</span>
                <strong><?= $totalDevices ?></strong>
            </div>

            <div class="report-stat">
                <span>Đang sử dụng</span>
                <strong><?= $usingDevices ?></strong>
            </div>

            <div class="report-stat">
                <span>Đang bảo trì</span>
                <strong><?= $repairDevices ?></strong>
            </div>

            <div class="report-stat">
                <span>Ngừng sử dụng</span>
                <strong><?= $stoppedDevices ?></strong>
            </div>
        </div>

        <div class="report-chart-card">
            <h3>Biểu đồ tình trạng thiết bị</h3>
            <div class="chart-box chart-small">
                <canvas id="deviceChart"></canvas>
            </div>
        </div>
    </section>

    <!-- THỐNG KÊ LINH KIỆN -->
    <section class="report-section">
        <h2>2. THỐNG KÊ LINH KIỆN</h2>

        <div class="report-stats">
            <div class="report-stat">
                <span>Loại linh kiện</span>
                <strong><?= $totalComponents ?></strong>
            </div>

            <div class="report-stat">
                <span>Tổng số lượng tồn</span>
                <strong><?= number_format($totalStock) ?></strong>
            </div>

            <div class="report-stat">
                <span>Linh kiện sắp hết</span>
                <strong><?= $lowStock ?></strong>
            </div>

            <div class="report-stat">
                <span>Tổng số lượng nhập</span>
                <strong><?= number_format($totalImport) ?></strong>
            </div>

            <div class="report-stat">
                <span>Tổng số lượng xuất</span>
                <strong><?= number_format($totalExport) ?></strong>
            </div>
        </div>

        <div class="report-chart-card">
            <h3>Biểu đồ số lượng linh kiện tồn kho</h3>
            <div class="chart-box chart-large">
                <canvas id="componentChart"></canvas>
            </div>
        </div>

        <div class="report-chart-card">
            <h3>Biểu đồ nhập kho và xuất kho</h3>
            <div class="chart-box chart-medium">
                <canvas id="stockChart"></canvas>
            </div>
        </div>

        <div class="report-table-card">
            <h3>DANH SÁCH LINH KIỆN SẮP HẾT</h3>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Mã linh kiện</th>
                            <th>Tên linh kiện</th>
                            <th>Số lượng tồn</th>
                            <th>Mức tối thiểu</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (count($lowStockItems) > 0): ?>
                        <?php foreach ($lowStockItems as $item): ?>
                            <tr>
                                <td><?= e($item["component_code"]) ?></td>
                                <td><?= e($item["component_name"]) ?></td>
                                <td><?= (int)$item["quantity"] ?></td>
                                <td><?= (int)$item["min_quantity"] ?></td>
                                <td>
                                    <span class="status-warning">Sắp hết hàng</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-data">
                                Không có linh kiện nào sắp hết.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- THỐNG KÊ MƯỢN TRẢ -->
    <section class="report-section">
        <h2>3. THỐNG KÊ MƯỢN TRẢ</h2>

        <div class="report-stats">
            <div class="report-stat">
                <span>Đang mượn</span>
                <strong><?= $activeBorrow ?></strong>
            </div>

            <div class="report-stat">
                <span>Đã trả</span>
                <strong><?= $returnedBorrow ?></strong>
            </div>

            <div class="report-stat">
                <span>Quá hạn</span>
                <strong><?= $overdueBorrow ?></strong>
            </div>
        </div>

        <div class="report-chart-card">
            <h3>Biểu đồ tình trạng mượn trả</h3>
            <div class="chart-box chart-small">
                <canvas id="borrowChart"></canvas>
            </div>
        </div>

        <div class="report-table-card">
            <h3>LỊCH SỬ MƯỢN TRẢ GẦN ĐÂY</h3>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Thiết bị</th>
                            <th>Người mượn</th>
                            <th>Số lượng</th>
                            <th>Ngày mượn</th>
                            <th>Hạn trả</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (count($recentBorrows) > 0): ?>
                        <?php foreach ($recentBorrows as $borrow): ?>
                            <tr>
                                <td>
                                    <?= e($borrow["device_name"] ?? "Thiết bị đã xóa") ?>
                                </td>
                                <td><?= e($borrow["borrower_name"]) ?></td>
                                <td><?= (int)$borrow["quantity"] ?></td>
                                <td><?= e($borrow["borrow_date"]) ?></td>
                                <td><?= e($borrow["expected_return_date"]) ?></td>
                                <td>
                                    <?php if ($borrow["status"] === "Đã trả"): ?>
                                        <span class="status-success">Đã trả</span>
                                    <?php elseif ($borrow["expected_return_date"] < date("Y-m-d")): ?>
                                        <span class="status-warning">Quá hạn</span>
                                    <?php else: ?>
                                        <span class="status-info">Đang mượn</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-data">
                                Chưa có dữ liệu mượn trả.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- THỐNG KÊ BẢO TRÌ -->
    <section class="report-section">
        <h2>4. THỐNG KÊ BẢO TRÌ</h2>

        <div class="report-stats">
            <div class="report-stat">
                <span>Đang bảo trì</span>
                <strong><?= $activeMaintenance ?></strong>
            </div>

            <div class="report-stat">
                <span>Đã hoàn thành</span>
                <strong><?= $completedMaintenance ?></strong>
            </div>

            <div class="report-stat">
                <span>Tổng chi phí bảo trì</span>
                <strong><?= moneyFormat($totalMaintenanceCost) ?></strong>
            </div>
        </div>

        <div class="report-chart-card">
            <h3>Biểu đồ tình trạng bảo trì</h3>
            <div class="chart-box chart-small">
                <canvas id="maintenanceChart"></canvas>
            </div>
        </div>

        <div class="report-table-card">
            <h3>LỊCH SỬ BẢO TRÌ GẦN ĐÂY</h3>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Thiết bị</th>
                            <th>Nội dung sự cố</th>
                            <th>Ngày bảo trì</th>
                            <th>Kỹ thuật viên</th>
                            <th>Chi phí</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (count($recentMaintenance) > 0): ?>
                        <?php foreach ($recentMaintenance as $maintenance): ?>
                            <tr>
                                <td>
                                    <?= e($maintenance["device_name"] ?? "Thiết bị đã xóa") ?>
                                </td>
                                <td><?= e($maintenance["problem_description"]) ?></td>
                                <td><?= e($maintenance["maintenance_date"]) ?></td>
                                <td><?= e($maintenance["technician"] ?: "Chưa cập nhật") ?></td>
                                <td><?= moneyFormat($maintenance["maintenance_cost"]) ?></td>
                                <td>
                                    <?php if ($maintenance["status"] === "Đã hoàn thành"): ?>
                                        <span class="status-success">Đã hoàn thành</span>
                                    <?php else: ?>
                                        <span class="status-info">Đang bảo trì</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-data">
                                Chưa có dữ liệu bảo trì.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const chartTextColor = "#a9c1dc";
const chartGridColor = "rgba(130, 160, 190, 0.12)";

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: {
                color: chartTextColor,
                padding: 18
            }
        }
    }
};

// Biểu đồ thiết bị
new Chart(document.getElementById("deviceChart"), {
    type: "doughnut",
    data: {
        labels: ["Đang sử dụng", "Đang bảo trì", "Ngừng sử dụng"],
        datasets: [{
            data: <?= json_encode($deviceChart) ?>,
            backgroundColor: ["#13c9e8", "#f6ad55", "#ef6464"],
            borderWidth: 0
        }]
    },
    options: commonOptions
});

// Biểu đồ linh kiện
new Chart(document.getElementById("componentChart"), {
    type: "bar",
    data: {
        labels: <?= json_encode($componentLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: "Số lượng tồn",
            data: <?= json_encode($componentValues) ?>,
            backgroundColor: "#13c9e8",
            borderRadius: 6
        }]
    },
    options: {
        ...commonOptions,
        scales: {
            x: {
                ticks: { color: chartTextColor },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: { color: chartTextColor, precision: 0 },
                grid: { color: chartGridColor }
            }
        }
    }
});

// Biểu đồ nhập xuất kho
new Chart(document.getElementById("stockChart"), {
    type: "bar",
    data: {
        labels: ["Nhập kho", "Xuất kho"],
        datasets: [{
            label: "Số lượng linh kiện",
            data: <?= json_encode($stockChart) ?>,
            backgroundColor: ["#13c9e8", "#f6ad55"],
            borderRadius: 6
        }]
    },
    options: {
        ...commonOptions,
        scales: {
            x: {
                ticks: { color: chartTextColor },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: { color: chartTextColor, precision: 0 },
                grid: { color: chartGridColor }
            }
        }
    }
});

// Biểu đồ mượn trả
new Chart(document.getElementById("borrowChart"), {
    type: "pie",
    data: {
        labels: ["Đang mượn", "Đã trả"],
        datasets: [{
            data: <?= json_encode($borrowChart) ?>,
            backgroundColor: ["#f6ad55", "#13c9e8"],
            borderWidth: 0
        }]
    },
    options: commonOptions
});

// Biểu đồ bảo trì
new Chart(document.getElementById("maintenanceChart"), {
    type: "doughnut",
    data: {
        labels: ["Đang bảo trì", "Đã hoàn thành"],
        datasets: [{
            data: <?= json_encode($maintenanceChart) ?>,
            backgroundColor: ["#f6ad55", "#13c9e8"],
            borderWidth: 0
        }]
    },
    options: commonOptions
});
</script>

<style>
.report-page {
    padding: 34px 42px;
    min-width: 0;
    color: #dce8f5;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 48px;
}

.report-header h1 {
    color: #16d4ef;
    font-size: 25px;
    margin: 0 0 14px;
    font-weight: 700;
}

.report-header p {
    color: #8ca9c7;
    font-size: 14px;
    margin: 0;
}

.print-button {
    background: #079bb9;
    color: white;
    border: none;
    border-radius: 7px;
    padding: 11px 18px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.print-button:hover {
    background: #0bb9d8;
}

.report-section {
    margin-bottom: 38px;
}

.report-section h2 {
    font-size: 17px;
    color: #14d6f1;
    margin: 0 0 17px;
    font-weight: 700;
}

.report-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 18px;
}

.report-stat {
    background: #111f31;
    border: 1px solid #203a55;
    border-radius: 12px;
    padding: 19px;
    min-height: 96px;
}

.report-stat span {
    display: block;
    color: #91b2d2;
    font-size: 12px;
    margin-bottom: 13px;
}

.report-stat strong {
    display: block;
    color: #16d8f2;
    font-size: 24px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.report-chart-card,
.report-table-card {
    background: #111f31;
    border: 1px solid #203a55;
    border-radius: 12px;
    padding: 22px;
    margin-top: 17px;
}

.report-chart-card h3,
.report-table-card h3 {
    color: #dce8f5;
    font-size: 14px;
    margin: 0 0 20px;
    font-weight: 600;
}

.chart-box {
    position: relative;
    width: 100%;
}

.chart-small {
    height: 270px;
    max-width: 520px;
    margin: 0 auto;
}

.chart-medium {
    height: 300px;
}

.chart-large {
    height: 320px;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

.report-table th {
    text-align: left;
    color: #8eb2d4;
    font-size: 12px;
    font-weight: 600;
    padding: 13px 12px;
    border-bottom: 1px solid #29415b;
}

.report-table td {
    color: #d2deeb;
    font-size: 12px;
    padding: 14px 12px;
    border-bottom: 1px solid rgba(45, 70, 96, 0.55);
}

.report-table tbody tr:hover {
    background: rgba(25, 190, 220, 0.05);
}

.status-warning,
.status-success,
.status-info {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 5px;
    font-size: 11px;
    white-space: nowrap;
}

.status-warning {
    color: #ffc36c;
    background: rgba(246, 173, 85, 0.13);
}

.status-success {
    color: #55dba3;
    background: rgba(85, 219, 163, 0.12);
}

.status-info {
    color: #51d7f0;
    background: rgba(19, 201, 232, 0.12);
}

.empty-data {
    text-align: center;
    color: #8298af !important;
    padding: 24px !important;
}

@media (max-width: 1000px) {
    .report-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .report-page {
        padding: 25px 20px;
    }
}

@media (max-width: 550px) {
    .report-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .report-stats {
        grid-template-columns: 1fr 1fr;
    }

    .report-stat {
        padding: 14px;
    }

    .report-stat strong {
        font-size: 20px;
    }
}

@media print {
    body {
        background: white !important;
        color: black !important;
    }

    .sidebar,
    .print-button {
        display: none !important;
    }

    .main-content,
    .report-page {
        margin: 0 !important;
        padding: 10px !important;
        width: 100% !important;
        background: white !important;
        color: black !important;
    }

    .report-section {
        break-inside: avoid;
    }

    .report-stat,
    .report-chart-card,
    .report-table-card {
        background: white !important;
        border: 1px solid #ccc !important;
        color: black !important;
        break-inside: avoid;
    }

    .report-stat span,
    .report-stat strong,
    .report-chart-card h3,
    .report-table-card h3,
    .report-table td,
    .report-table th {
        color: black !important;
    }

    .report-table th,
    .report-table td {
        border-color: #ccc !important;
    }
}

/* SỬA LỖI MENU VÀ NỘI DUNG BỊ NHẢY XUỐNG */

.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    overflow-y: auto !important;
    z-index: 1000 !important;
    box-sizing: border-box !important;
}

.main-content.report-page {
    margin-left: 250px !important;
    width: calc(100% - 250px) !important;
    min-height: 100vh !important;
    box-sizing: border-box !important;
}

@media (max-width: 768px) {
    .sidebar {
        width: 210px !important;
    }

    .main-content.report-page {
        margin-left: 210px !important;
        width: calc(100% - 210px) !important;
        padding: 20px 12px !important;
    }
}

</style>
