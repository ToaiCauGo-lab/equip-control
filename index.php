
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nếu đã đăng nhập thì chuyển đến Dashboard
if (isset($_SESSION["user_id"])) {
    header("Location: /equip-control/pages/dashboard.php");
    exit;
}

// Nếu chưa đăng nhập thì chuyển đến trang Login
header("Location: /equip-control/login.php");
exit;

?>
