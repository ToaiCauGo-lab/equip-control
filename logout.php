
<?php
// Khởi động session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Xóa toàn bộ dữ liệu session
$_SESSION = [];

// Xóa cookie session trên trình duyệt
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Hủy session
session_destroy();

// Xác định thư mục gốc của dự án
$scriptName = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
$baseUrl = rtrim(dirname($scriptName), "/");

if ($baseUrl === "/" || $baseUrl === ".") {
    $baseUrl = "";
}

// Chuyển về trang đăng nhập
header("Location: " . $baseUrl . "/login.php");
exit;
