
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';

$menuItems = [
    [
        'title' => 'Tổng quan',
        'url' => '/equip-control/pages/dashboard.php',
        'file' => 'dashboard.php',
        'icon' => '📊'
    ],
    [
        'title' => 'Quản lý thiết bị',
        'url' => '/equip-control/devices.php',
        'file' => 'devices.php',
        'icon' => '🖥️'
    ],
    [
        'title' => 'Quản lý linh kiện',
        'url' => '/equip-control/components.php',
        'file' => 'components.php',
        'icon' => '🔧'
    ],
    [
        'title' => 'Nhập xuất kho',
        'url' => '/equip-control/stock.php',
        'file' => 'stock.php',
        'icon' => '📦'
    ],
    [
        'title' => 'Mượn trả',
        'url' => '/equip-control/borrow.php',
        'file' => 'borrow.php',
        'icon' => '🔄'
    ],
    [
        'title' => 'Bảo trì thiết bị',
        'url' => '/equip-control/maintenance.php',
        'file' => 'maintenance.php',
        'icon' => '🛠️'
    ],
    [
        'title' => 'Báo cáo thống kê',
        'url' => '/equip-control/reports.php',
        'file' => 'reports.php',
        'icon' => '📈'
    ],
];
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
        <?php foreach ($menuItems as $item): ?>
            <a
                href="<?= htmlspecialchars($item['url']) ?>"
                class="menu-item <?= $currentPage === $item['file'] ? 'active' : '' ?>"
            >
                <span><?= $item['icon'] ?></span>
                <?= htmlspecialchars($item['title']) ?>
            </a>
        <?php endforeach; ?>

        <?php if ($role === 'admin'): ?>
            <a
                href="/equip-control/users.php"
                class="menu-item <?= $currentPage === 'users.php' ? 'active' : '' ?>"
            >
                <span>👥</span>
                Quản lý tài khoản
            </a>
        <?php endif; ?>
    </nav>

    <div class="menu-heading">TÀI KHOẢN</div>

    <nav class="sidebar-menu">
        <a
            href="/equip-control/change_password.php"
            class="menu-item <?= $currentPage === 'change_password.php' ? 'active' : '' ?>"
        >
            <span>🔐</span>
            Đổi mật khẩu
        </a>

        <a href="/equip-control/logout.php" class="menu-item">
            <span>🚪</span>
            Đăng xuất
        </a>
    </nav>
</aside>
