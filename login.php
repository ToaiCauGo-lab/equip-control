
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";

// Xác định đường dẫn gốc dự án
$baseUrl = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");

if ($baseUrl === "/" || $baseUrl === ".") {
    $baseUrl = "";
}

// Nếu đã đăng nhập thì chuyển đến tổng quan
if (isset($_SESSION["user_id"])) {
    header("Location: " . $baseUrl . "/pages/dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.";

    } else {

        try {
            $sql = "SELECT id, full_name, username, password, role, status
                    FROM users
                    WHERE username = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user["password"])) {

                if ((int)$user["status"] !== 1) {

                    $error = "Tài khoản của bạn hiện đã bị khóa.";

                } else {

                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["username"] = $user["username"];
                    $_SESSION["role"] = $user["role"];

                    header("Location: " . $baseUrl . "/pages/dashboard.php");
                    exit;
                }

            } else {

                $error = "Tên đăng nhập hoặc mật khẩu không chính xác.";

            }

        } catch (PDOException $e) {

            $error = "Không thể đăng nhập lúc này. Vui lòng thử lại.";

        }
    }
}

function loginEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Đăng nhập - EQUIP CONTROL</title>

    <link rel="stylesheet"
          href="<?= loginEscape($baseUrl) ?>/assets/css/login.css?v=2">
</head>

<body>

<div class="login-page">

    <!-- HIỆU ỨNG NỀN -->
    <div class="background-glow glow-one"></div>
    <div class="background-glow glow-two"></div>

    <div class="login-container">

        <!-- CỘT GIỚI THIỆU -->
        <section class="brand-section">

            <div class="brand-logo">

                <div class="logo-icon">
                    EC
                </div>

                <div class="logo-name">
                    EQUIP <span>CONTROL</span>
                </div>

            </div>

            <div class="brand-content">

                <div class="eyebrow">
                    <span class="status-dot"></span>
                    ELECTRONIC EQUIPMENT MANAGEMENT SYSTEM
                </div>

                <h1>
                    Quản lý thiết bị
                    <span style="color:#00c5e8;">thông minh</span>
                    và hiệu quả.
                </h1>

                <p>
                    EQUIP CONTROL là hệ thống hỗ trợ quản lý thiết bị
                    và linh kiện điện tử, giúp theo dõi tài sản,
                    kiểm soát kho và quản lý hoạt động mượn trả
                    một cách thuận tiện.
                </p>

                <div class="feature-list">

                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        Quản lý thiết bị và linh kiện điện tử
                    </div>

                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        Theo dõi nhập xuất kho và mượn trả
                    </div>

                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        Quản lý bảo trì và cảnh báo thiết bị
                    </div>

                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        Báo cáo và thống kê hoạt động
                    </div>

                </div>

            </div>

            <div class="brand-footer">
                EQUIP CONTROL <span>•</span> EQUIPMENT MANAGEMENT
            </div>

        </section>

        <!-- CỘT ĐĂNG NHẬP -->
        <section class="form-section">

            <div class="form-top">

                <div class="form-label">
                    SYSTEM LOGIN
                </div>

                <div class="secure-label">
                    🔒 SECURE ACCESS
                </div>

            </div>

            <div class="form-heading">

                <h2>Đăng nhập hệ thống</h2>

                <p>
                    Chào mừng bạn quay trở lại EQUIP CONTROL.
                </p>

            </div>

            <!-- THÔNG BÁO LỖI -->
            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <span>!</span>
                    <?= loginEscape($error) ?>
                </div>

            <?php endif; ?>

            <!-- FORM ĐĂNG NHẬP -->
            <form method="POST" action="">

                <div class="input-group">

                    <label for="username">
                        TÊN ĐĂNG NHẬP
                    </label>

                    <div class="input-wrapper">

                        <span class="input-icon">👤</span>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Nhập tên đăng nhập"
                            value="<?= loginEscape($_POST["username"] ?? "") ?>"
                            autocomplete="username"
                            required
                            autofocus
                        >

                    </div>

                </div>

                <div class="input-group">

                    <label for="password">
                        MẬT KHẨU
                    </label>

                    <div class="input-wrapper">

                        <span class="input-icon">🔑</span>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Nhập mật khẩu"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            id="togglePassword"
                        >
                            HIỆN
                        </button>

                    </div>

                </div>

                <button type="submit" class="login-button">
                    ĐĂNG NHẬP
                    <span>→</span>
                </button>

            </form>

            <div class="form-bottom">
                <span class="bottom-line"></span>
                SECURE MANAGEMENT SYSTEM
                <span class="bottom-line"></span>
            </div>

            <div class="copyright">
                © <?= date("Y") ?> EQUIP CONTROL
                <br><br>
                PHẦN MỀM QUẢN LÝ THIẾT BỊ VÀ LINH KIỆN ĐIỆN TỬ
            </div>

        </section>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const passwordInput = document.getElementById("password");
    const toggleButton = document.getElementById("togglePassword");

    toggleButton.addEventListener("click", function () {

        if (passwordInput.type === "password") {
            passwordInput.type = "text";
            toggleButton.textContent = "ẨN";
        } else {
            passwordInput.type = "password";
            toggleButton.textContent = "HIỆN";
        }

    });

});
</script>

</body>
</html>
