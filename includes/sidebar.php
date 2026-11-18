
<?php
$currentPage = basename($_SERVER["PHP_SELF"]);
$role = $_SESSION["role"] ?? "";

// Đếm số cảnh báo hiện tại
$notificationCount = 0;

if (isset($conn) && $conn instanceof PDO) {
    try {
        $lowStockCount = (int)$conn->query("
            SELECT COUNT(*) FROM components
            WHERE quantity <= min_quantity
        ")->fetchColumn();

        $overdueBorrowCount = (int)$conn->query("
            SELECT COUNT(*) FROM borrow_records
            WHERE status = 'Đang mượn'
            AND expected_return_date < CURDATE()
        ")->fetchColumn();

        $overdueMaintenanceCount = (int)$conn->query("
            SELECT COUNT(*) FROM maintenance_records
            WHERE status = 'Đang bảo trì'
            AND expected_end_date IS NOT NULL
            AND expected_end_date < CURDATE()
        ")->fetchColumn();

        $notificationCount = $lowStockCount
                           + $overdueBorrowCount
                           + $overdueMaintenanceCount;

    } catch (PDOException $e) {
        $notificationCount = 0;
    }
}
?>

<aside class="sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-logo">EC</div>
        <div>
            <strong>EQUIP CONTROL</strong>
            <small>QUẢN LÝ THIẾT BỊ</small>
        </div>
    </div>

    <div class="menu-heading">MENU CHÍNH</div>

    <nav class="sidebar-menu">

        <a href="/equip-control/pages/dashboard.php"
           class="menu-item <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">
            📊 Tổng quan
        </a>

        <a href="/equip-control/devices.php"
           class="menu-item <?= $currentPage == 'devices.php' ? 'active' : '' ?>">
            💻 Quản lý thiết bị
        </a>

        <a href="/equip-control/components.php"
           class="menu-item <?= $currentPage == 'components.php' ? 'active' : '' ?>">
            🔧 Quản lý linh kiện
        </a>

        <a href="/equip-control/stock.php"
           class="menu-item <?= $currentPage == 'stock.php' ? 'active' : '' ?>">
            📦 Nhập xuất kho
        </a>

        <a href="/equip-control/borrow.php"
           class="menu-item <?= $currentPage == 'borrow.php' ? 'active' : '' ?>">
            🔄 Mượn trả
        </a>

        <a href="/equip-control/maintenance.php"
           class="menu-item <?= $currentPage == 'maintenance.php' ? 'active' : '' ?>">
            🛠️ Bảo trì thiết bị
        </a>

        <a href="/equip-control/reports.php"
           class="menu-item <?= $currentPage == 'reports.php' ? 'active' : '' ?>">
            📈 Báo cáo thống kê
        </a>

        <a href="/equip-control/activity_logs.php"
           class="menu-item <?= $currentPage == 'activity_logs.php' ? 'active' : '' ?>">
            📋 Nhật ký hoạt động
        </a>

        <a href="/equip-control/notifications.php"
           class="menu-item notification-menu <?= $currentPage == 'notifications.php' ? 'active' : '' ?>">
            <span>🔔 Thông báo</span>

            <?php if ($notificationCount > 0): ?>
                <span class="notification-badge">
                    <?= $notificationCount > 99 ? '99+' : $notificationCount ?>
                </span>
            <?php endif; ?>
        </a>

    </nav>

    <div class="menu-heading">TÀI KHOẢN</div>

    <nav class="sidebar-menu">

        <?php if ($role === "admin"): ?>
            <a href="/equip-control/users.php"
               class="menu-item <?= $currentPage == 'users.php' ? 'active' : '' ?>">
                👥 Quản lý tài khoản
            </a>
        <?php endif; ?>

        <a href="/equip-control/change_password.php"
           class="menu-item <?= $currentPage == 'change_password.php' ? 'active' : '' ?>">
            🔐 Đổi mật khẩu
        </a>

        <a href="/equip-control/logout.php"
           class="menu-item"
           onclick="return confirmLogout();">
            🚪 Đăng xuất
        </a>

    </nav>

</aside>

<style>
.notification-menu {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px;
}

.notification-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 21px;
    height: 21px;
    padding: 0 6px;
    border-radius: 20px;
    background: #ef4444;
    color: white;
    font-size: 11px;
    font-weight: bold;
    line-height: 1;
}
</style>

<script>
function confirmLogout() {
    return confirm(
        "Bạn có chắc chắn muốn đăng xuất khỏi hệ thống EQUIP CONTROL không?"
    );
}
</script>
