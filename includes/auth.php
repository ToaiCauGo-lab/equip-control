
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra người dùng đã đăng nhập chưa
function requireLogin() {
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }
}

// Chỉ cho phép quản trị viên truy cập
function requireAdmin() {
    requireLogin();

    if ($_SESSION["role"] !== "admin") {
        http_response_code(403);
        exit("Ban khong co quyen truy cap chuc nang nay.");
    }
}
?>
