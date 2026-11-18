
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireLogin();

$role = $_SESSION["role"] ?? "staff";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// 1. LINH KIỆN SẮP HẾT HÀNG
$lowStockItems = $conn->query("
    SELECT component_code, component_name, quantity, min_quantity
    FROM components
    WHERE quantity <= min_quantity
    ORDER BY quantity ASC
")->fetchAll();

// 2. THIẾT BỊ MƯỢN QUÁ HẠN
$overdueBorrows = $conn->query("
    SELECT b.borrower_name, b.expected_return_date,
           b.quantity, d.device_name, d.device_code
    FROM borrow_records b
    JOIN devices d ON b.device_id = d.id
    WHERE b.status = 'Đang mượn'
      AND b.expected_return_date < CURDATE()
    ORDER BY b.expected_return_date ASC
")->fetchAll();

// 3. BẢO TRÌ QUÁ HẠN
$overdueMaintenance = $conn->query("
    SELECT m.expected_end_date, m.technician,
           d.device_name, d.device_code
    FROM maintenance_records m
    JOIN devices d ON m.device_id = d.id
    WHERE m.status = 'Đang bảo trì'
      AND m.expected_end_date IS NOT NULL
      AND m.expected_end_date < CURDATE()
    ORDER BY m.expected_end_date ASC
")->fetchAll();

// 4. THIẾT BỊ ĐANG BẢO TRÌ
$maintenanceItems = $conn->query("
    SELECT m.maintenance_date, m.expected_end_date,
           m.technician, d.device_name, d.device_code
    FROM maintenance_records m
    JOIN devices d ON m.device_id = d.id
    WHERE m.status = 'Đang bảo trì'
    ORDER BY m.maintenance_date DESC
")->fetchAll();

$totalAlerts = count($lowStockItems)
             + count($overdueBorrows)
             + count($overdueMaintenance);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông báo hệ thống - EQUIP CONTROL</title>

    <link rel="stylesheet" href="/equip-control/assets/css/unified.css">

    <style>
        * {
            box-sizing: border-box;
        }

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

        .main-content.notification-page {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            min-height: 100vh;
            padding: 25px 30px;
        }

        .page-header {
            background: linear-gradient(120deg, #102c43, #12263a);
            border: 1px solid #29445c;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #22d3ee;
            font-size: 23px;
            margin: 0 0 10px;
        }

        .page-header p {
            color: #a8c3dd;
            font-size: 14px;
            margin: 0;
        }

        .summary-card {
            background: #111f30;
            border: 1px solid #263d55;
            border-radius: 13px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .summary-number {
            font-size: 32px;
            font-weight: bold;
            color: #fbbf24;
            margin-top: 7px;
        }

        .summary-label {
            color: #9bb5cf;
            font-size: 13px;
        }

        .section-title {
            font-size: 17px;
            margin: 25px 0 14px;
            color: #f8fafc;
        }

        .notification-card {
            background: #111f30;
            border: 1px solid #263d55;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 14px;
            border-left: 4px solid #22d3ee;
        }

        .notification-card.warning {
            border-left-color: #fbbf24;
        }

        .notification-card.danger {
            border-left-color: #f87171;
        }

        .notification-card.info {
            border-left-color: #22d3ee;
        }

        .notification-card h3 {
            font-size: 15px;
            margin: 0 0 12px;
        }

        .notification-item {
            padding: 12px 0;
            border-bottom: 1px solid #263d55;
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.7;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item strong {
            color: #f8fafc;
        }

        .empty-message {
            color: #4ade80;
            font-size: 13px;
            padding: 10px 0;
        }

        .status-count {
            display: inline-block;
            background: #263d55;
            color: #e2e8f0;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 12px;
            margin-left: 6px;
        }

        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: #22d3ee;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 210px !important;
            }

            .main-content.notification-page {
                margin-left: 210px !important;
                width: calc(100% - 210px) !important;
                padding: 20px 12px;
            }
        }
    </style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content notification-page">

    <div class="page-header">
        <h1>🔔 Thông báo hệ thống</h1>
        <p>Tổng hợp các vấn đề cần chú ý trong quá trình quản lý thiết bị và linh kiện.</p>
    </div>

    <div class="summary-card">
        <div>
            <div class="summary-label">Tổng số cảnh báo cần xử lý</div>
            <div class="summary-number"><?= $totalAlerts ?></div>
        </div>

        <div style="color:#94a3b8;font-size:13px;">
            Cập nhật theo dữ liệu hiện tại
        </div>
    </div>

    <h2 class="section-title">
        📦 Linh kiện sắp hết hàng
        <span class="status-count"><?= count($lowStockItems) ?></span>
    </h2>

    <div class="notification-card warning">
        <h3 style="color:#fbbf24;">Cảnh báo tồn kho thấp</h3>

        <?php if ($lowStockItems): ?>
            <?php foreach ($lowStockItems as $item): ?>
                <div class="notification-item">
                    <strong><?= h($item["component_name"]) ?></strong>
                    (<?= h($item["component_code"]) ?>)<br>
                    Số lượng còn: <?= (int)$item["quantity"] ?> |
                    Mức tối thiểu: <?= (int)$item["min_quantity"] ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-message">✓ Không có linh kiện nào sắp hết hàng.</div>
        <?php endif; ?>
    </div>

    <h2 class="section-title">
        ⏰ Thiết bị mượn quá hạn
        <span class="status-count"><?= count($overdueBorrows) ?></span>
    </h2>

    <div class="notification-card danger">
        <h3 style="color:#f87171;">Cảnh báo chưa trả thiết bị đúng hạn</h3>

        <?php if ($overdueBorrows): ?>
            <?php foreach ($overdueBorrows as $item): ?>
                <div class="notification-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Người mượn: <?= h($item["borrower_name"]) ?><br>
                    Số lượng: <?= (int)$item["quantity"] ?><br>
                    Hạn trả: <?= date("d/m/Y", strtotime($item["expected_return_date"])) ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-message">✓ Không có thiết bị mượn quá hạn.</div>
        <?php endif; ?>
    </div>

    <h2 class="section-title">
        🛠️ Công việc bảo trì quá hạn
        <span class="status-count"><?= count($overdueMaintenance) ?></span>
    </h2>

    <div class="notification-card danger">
        <h3 style="color:#f87171;">Cảnh báo quá ngày hoàn thành dự kiến</h3>

        <?php if ($overdueMaintenance): ?>
            <?php foreach ($overdueMaintenance as $item): ?>
                <div class="notification-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Kỹ thuật viên: <?= h($item["technician"] ?: "Chưa cập nhật") ?><br>
                    Hạn hoàn thành: <?= date("d/m/Y", strtotime($item["expected_end_date"])) ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-message">✓ Không có công việc bảo trì quá hạn.</div>
        <?php endif; ?>
    </div>

    <h2 class="section-title">
        🔧 Thiết bị đang bảo trì
        <span class="status-count"><?= count($maintenanceItems) ?></span>
    </h2>

    <div class="notification-card info">
        <h3 style="color:#22d3ee;">Danh sách thiết bị đang được bảo trì</h3>

        <?php if ($maintenanceItems): ?>
            <?php foreach ($maintenanceItems as $item): ?>
                <div class="notification-item">
                    <strong><?= h($item["device_name"]) ?></strong>
                    (<?= h($item["device_code"]) ?>)<br>
                    Kỹ thuật viên: <?= h($item["technician"] ?: "Chưa cập nhật") ?><br>
                    Ngày bắt đầu: <?= date("d/m/Y", strtotime($item["maintenance_date"])) ?><br>
                    Dự kiến hoàn thành:
                    <?= $item["expected_end_date"]
                        ? date("d/m/Y", strtotime($item["expected_end_date"]))
                        : "Chưa xác định" ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-message">✓ Hiện không có thiết bị đang bảo trì.</div>
        <?php endif; ?>
    </div>

    <a class="back-link" href="/equip-control/pages/dashboard.php">
        ← Quay lại trang Tổng quan
    </a>

</main>

</body>
</html>
