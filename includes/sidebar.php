
<?php
$currentPage = basename($_SERVER["PHP_SELF"] ?? "");

// Xác định đường dẫn gốc của dự án
$scriptName = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
$scriptDir = rtrim(dirname($scriptName), "/");

// Nếu trang hiện tại nằm trong thư mục pages thì lùi về thư mục gốc
if (basename($scriptDir) === "pages") {
    $scriptDir = dirname($scriptDir);
}

$baseUrl = ($scriptDir === "/" || $scriptDir === ".") ? "" : $scriptDir;

// Hàm tạo đường dẫn
$projectUrl = static function ($path) use ($baseUrl) {
    return $baseUrl . "/" . ltrim($path, "/");
};

// Danh sách menu chính
$menuItems = [
    [
        "title" => "Tổng quan",
        "icon" => "📊",
        "url" => "pages/dashboard.php",
        "file" => "dashboard.php"
    ],
    [
        "title" => "Quản lý thiết bị",
        "icon" => "🖥️",
        "url" => "devices.php",
        "file" => "devices.php"
    ],
    [
        "title" => "Quản lý linh kiện",
        "icon" => "🔧",
        "url" => "components.php",
        "file" => "components.php"
    ],
    [
        "title" => "Nhập xuất kho",
        "icon" => "📦",
        "url" => "stock.php",
        "file" => "stock.php"
    ],
    [
        "title" => "Mượn trả",
        "icon" => "🔄",
        "url" => "borrow.php",
        "file" => "borrow.php"
    ],
    [
        "title" => "Bảo trì thiết bị",
        "icon" => "🛠️",
        "url" => "maintenance.php",
        "file" => "maintenance.php"
    ],
    [
        "title" => "Báo cáo thống kê",
        "icon" => "📈",
        "url" => "reports.php",
        "file" => "reports.php"
    ],
    [
        "title" => "Nhật ký hoạt động",
        "icon" => "📋",
        "url" => "activity_logs.php",
        "file" => "activity_logs.php"
    ],
    [
        "title" => "Quản lý tài khoản",
        "icon" => "👥",
        "url" => "users.php",
        "file" => "users.php"
    ]
];

// Danh sách menu tài khoản
$accountItems = [
    [
        "title" => "Đổi mật khẩu",
        "icon" => "🔐",
        "url" => "change_password.php",
        "file" => "change_password.php"
    ]
];
?>

<style>
/* SIDEBAR EQUIP CONTROL */
.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    padding: 0 !important;
    margin: 0 !important;
    background: #0d1929 !important;
    border-right: 1px solid #263b52;
    display: flex !important;
    flex-direction: column !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    z-index: 1000 !important;
    color: #e5edf8;
    box-sizing: border-box !important;
}

.sidebar * {
    box-sizing: border-box;
}

/* LOGO */
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 25px 19px 23px;
    border-bottom: 1px solid #263b52;
    text-decoration: none;
}

.sidebar-logo {
    width: 45px;
    height: 45px;
    flex-shrink: 0;
    border-radius: 11px;
    background: linear-gradient(145deg, #0e7490, #164e63);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #67e8f9;
    font-size: 23px;
    font-weight: 800;
    box-shadow: 0 4px 15px rgba(8,145,178,.18);
}

.sidebar-brand-text {
    min-width: 0;
}

.sidebar-brand-name {
    display: block;
    color: #f1f5f9;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: .5px;
    line-height: 1.3;
}

.sidebar-brand-sub {
    display: block;
    color: #7890aa;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: .8px;
    margin-top: 5px;
    white-space: nowrap;
}

/* MENU */
.sidebar-menu {
    padding: 22px 12px 10px;
}

.sidebar-section-title {
    color: #647d98;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 0 10px;
    margin: 0 0 12px;
}

.sidebar-link {
    display: flex !important;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 43px;
    padding: 10px 12px !important;
    margin-bottom: 4px;
    border-radius: 8px;
    text-decoration: none !important;
    color: #a9bfd5 !important;
    font-size: 12.5px;
    font-weight: 500;
    border: 1px solid transparent;
    transition: background .2s, color .2s, border-color .2s;
}

.sidebar-link:hover {
    background: #14283d !important;
    color: #e5edf8 !important;
    border-color: #263b52;
}

.sidebar-link.active {
    background: #123449 !important;
    color: #22d3ee !important;
    border-color: rgba(34,211,238,.16);
    font-weight: 700;
    position: relative;
}

.sidebar-link.active::before {
    content: "";
    position: absolute;
    left: -1px;
    top: 9px;
    bottom: 9px;
    width: 3px;
    border-radius: 0 4px 4px 0;
    background: #22d3ee;
}

.sidebar-icon {
    width: 22px;
    flex-shrink: 0;
    text-align: center;
    font-size: 16px;
    line-height: 1;
}

.sidebar-link-text {
    flex: 1;
}

/* TÀI KHOẢN */
.sidebar-account {
    padding: 19px 12px 10px;
    margin-top: 7px;
    border-top: 1px solid #263b52;
}

.sidebar-account .sidebar-section-title {
    margin-bottom: 12px;
}

.sidebar-logout {
    color: #fca5a5 !important;
}

.sidebar-logout:hover {
    background: rgba(127,29,29,.2) !important;
    color: #fecaca !important;
    border-color: rgba(248,113,113,.15);
}

/* CHÂN SIDEBAR */
.sidebar-footer {
    margin-top: auto;
    padding: 15px 16px 19px;
    border-top: 1px solid #263b52;
}

.sidebar-system-status {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #86efac;
    font-size: 11px;
    font-weight: 600;
}

.sidebar-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    box-shadow: 0 0 8px rgba(34,197,94,.4);
}

.sidebar-version {
    display: flex;
    justify-content: space-between;
    color: #647d98;
    font-size: 10px;
    margin-top: 10px;
}

/* THANH CUỘN */
.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-track {
    background: #0d1929;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #304963;
    border-radius: 10px;
}

/* RESPONSIVE */
@media(max-width: 768px) {
    .sidebar {
        width: 220px !important;
    }

    .sidebar-brand {
        padding: 20px 14px;
    }

    .sidebar-menu,
    .sidebar-account {
        padding-left: 9px;
        padding-right: 9px;
    }

    .sidebar-link {
        font-size: 12px;
        gap: 9px;
    }
}
</style>

<aside class="sidebar">

    <!-- LOGO VÀ TÊN PHẦN MỀM -->
    <a href="<?= htmlspecialchars($projectUrl('pages/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>"
       class="sidebar-brand">

        <div class="sidebar-logo">EC</div>

        <div class="sidebar-brand-text">
            <span class="sidebar-brand-name">EQUIP CONTROL</span>
            <span class="sidebar-brand-sub">EQUIPMENT MANAGEMENT</span>
        </div>
    </a>

    <!-- MENU CHÍNH -->
    <div class="sidebar-menu">

        <div class="sidebar-section-title">
            MENU CHÍNH
        </div>

        <?php foreach ($menuItems as $item): ?>

            <?php $isActive = $currentPage === $item["file"]; ?>

            <a href="<?= htmlspecialchars($projectUrl($item["url"]), ENT_QUOTES, "UTF-8") ?>"
               class="sidebar-link <?= $isActive ? "active" : "" ?>">

                <span class="sidebar-icon">
                    <?= htmlspecialchars($item["icon"], ENT_QUOTES, "UTF-8") ?>
                </span>

                <span class="sidebar-link-text">
                    <?= htmlspecialchars($item["title"], ENT_QUOTES, "UTF-8") ?>
                </span>

            </a>

        <?php endforeach; ?>

    </div>

    <!-- TÀI KHOẢN -->
    <div class="sidebar-account">

        <div class="sidebar-section-title">
            TÀI KHOẢN
        </div>

        <?php foreach ($accountItems as $item): ?>

            <?php $isActive = $currentPage === $item["file"]; ?>

            <a href="<?= htmlspecialchars($projectUrl($item["url"]), ENT_QUOTES, "UTF-8") ?>"
               class="sidebar-link <?= $isActive ? "active" : "" ?>">

                <span class="sidebar-icon">
                    <?= htmlspecialchars($item["icon"], ENT_QUOTES, "UTF-8") ?>
                </span>

                <span class="sidebar-link-text">
                    <?= htmlspecialchars($item["title"], ENT_QUOTES, "UTF-8") ?>
                </span>

            </a>

        <?php endforeach; ?>

        <!-- ĐĂNG XUẤT -->
        <a href="<?= htmlspecialchars($projectUrl('logout.php'), ENT_QUOTES, 'UTF-8') ?>"
           class="sidebar-link sidebar-logout">

            <span class="sidebar-icon">🚪</span>

            <span class="sidebar-link-text">
                Đăng xuất
            </span>

        </a>

    </div>

    <!-- TRẠNG THÁI HỆ THỐNG -->
    <div class="sidebar-footer">

        <div class="sidebar-system-status">
            <span class="sidebar-status-dot"></span>
            <span>Hệ thống đang hoạt động</span>
        </div>

        <div class="sidebar-version">
            <span>EQUIP CONTROL</span>
            <span>VERSION 1.0</span>
        </div>

    </div>

</aside>
