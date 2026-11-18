
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";

if (isset($_SESSION["user_id"])) {
    header("Location: /equip-control/pages/dashboard.php");
    exit;
}

$error = "";
$username = "";

// Thông báo đăng xuất thành công
$logoutSuccess = (
    isset($_GET["logout"]) &&
    $_GET["logout"] === "success"
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error = "Vui lòng nhập đầy đủ tài khoản và mật khẩu.";
    } else {
        try {
            $stmt = $conn->prepare(
                "SELECT * FROM users WHERE username = :username LIMIT 1"
            );
            $stmt->execute([":username" => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && (int)$user["status"] !== 1) {
                $error = "Tài khoản của bạn hiện đang bị khóa.";
            } elseif (
                $user &&
                password_verify($password, $user["password"])
            ) {
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $user["role"];

                header("Location: /equip-control/pages/dashboard.php");
                exit;
            } else {
                $error = "Tài khoản hoặc mật khẩu không chính xác.";
            }
        } catch (PDOException $e) {
            $error = "Không thể đăng nhập. Vui lòng thử lại.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập | EQUIP CONTROL</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --background: #080f1e;
            --panel: #101c30;
            --cyan: #22d3ee;
            --blue: #0891b2;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --border: rgba(148, 163, 184, 0.16);
        }

        body {
            min-height: 100vh;
            font-family: "Inter", "Segoe UI", Arial, sans-serif;
            background: var(--background);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            width: 600px;
            height: 600px;
            background: rgba(8, 145, 178, 0.13);
            filter: blur(120px);
            border-radius: 50%;
            top: -250px;
            left: -150px;
            pointer-events: none;
        }

        body::after {
            content: "";
            position: fixed;
            width: 500px;
            height: 500px;
            background: rgba(37, 99, 235, 0.10);
            filter: blur(120px);
            border-radius: 50%;
            bottom: -250px;
            right: -100px;
            pointer-events: none;
        }

        .login-container {
            width: 100%;
            max-width: 1080px;
            min-height: 620px;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            background: rgba(15, 27, 46, 0.88);
            border: 1px solid var(--border);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 30px 100px rgba(0, 0, 0, 0.45);
            position: relative;
            z-index: 1;
        }

        .intro-panel {
            padding: 55px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                radial-gradient(circle at 15% 15%, rgba(34, 211, 238, 0.14), transparent 35%),
                linear-gradient(145deg, #12253d, #0b172a 75%);
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .intro-panel::after {
            content: "";
            position: absolute;
            width: 350px;
            height: 350px;
            border: 1px solid rgba(34, 211, 238, 0.12);
            border-radius: 50%;
            right: -180px;
            bottom: -100px;
            box-shadow:
                0 0 0 35px rgba(34, 211, 238, 0.025),
                0 0 0 70px rgba(34, 211, 238, 0.02);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #06b6d4, #0e7490);
            color: white;
            font-size: 19px;
            font-weight: 800;
            box-shadow: 0 8px 25px rgba(6, 182, 212, 0.25);
        }

        .brand-name {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand-caption {
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 2px;
            margin-top: 5px;
        }

        .intro-content {
            position: relative;
            z-index: 1;
            margin: 50px 0;
        }

        .intro-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 13px;
            border-radius: 30px;
            background: rgba(34, 211, 238, 0.09);
            border: 1px solid rgba(34, 211, 238, 0.2);
            color: var(--cyan);
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 25px;
        }

        .intro-tag span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 10px var(--cyan);
        }

        .intro-content h1 {
            font-size: 37px;
            line-height: 1.3;
            font-weight: 800;
            letter-spacing: -1.2px;
            margin-bottom: 20px;
        }

        .intro-content h1 strong {
            color: var(--cyan);
        }

        .intro-content p {
            max-width: 390px;
            color: #a8b7ca;
            font-size: 14px;
            line-height: 1.9;
        }

        .feature-list {
            display: grid;
            gap: 16px;
            margin-top: 35px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 13px;
            color: #d6e2ef;
            font-size: 12px;
        }

        .feature-icon {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: rgba(34, 211, 238, 0.09);
            color: var(--cyan);
            font-size: 16px;
        }

        .intro-footer {
            color: #64748b;
            font-size: 11px;
            position: relative;
            z-index: 1;
        }

        .form-panel {
            padding: 55px 52px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-heading {
            margin-bottom: 34px;
        }

        .form-heading .small-title {
            color: var(--cyan);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            margin-bottom: 13px;
        }

        .form-heading h2 {
            font-size: 29px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .form-heading p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .error-message,
        .success-message {
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.10);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .success-message {
            background: rgba(34, 197, 94, 0.10);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-icon {
            width: 27px;
            height: 27px;
            flex-shrink: 0;
            border-radius: 50%;
            background: rgba(34, 197, 94, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .form-group {
            margin-bottom: 21px;
        }

        .form-group label {
            display: block;
            color: #dbeafe;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 17px;
            pointer-events: none;
        }

        .input-wrapper input {
            width: 100%;
            height: 49px;
            padding: 0 45px !important;
            background: #0b1527 !important;
            border: 1px solid #293a52 !important;
            border-radius: 9px !important;
            color: white !important;
            font-family: inherit;
            font-size: 13px !important;
            outline: none;
            transition: 0.2s;
        }

        .input-wrapper input::placeholder {
            color: #64748b;
        }

        .input-wrapper input:focus {
            border-color: var(--cyan) !important;
            box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.10);
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            font-size: 17px;
            padding: 5px;
        }

        .toggle-password:hover {
            color: var(--cyan);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 4px 0 25px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
        }

        .remember input {
            accent-color: var(--cyan);
            width: 14px;
            height: 14px;
        }

        .login-button {
            width: 100%;
            height: 49px;
            border: none;
            border-radius: 9px;
            background: linear-gradient(110deg, #0891b2, #06b6d4);
            color: white;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(8, 145, 178, 0.2);
            transition: 0.2s;
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(8, 145, 178, 0.32);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: #64748b;
            font-size: 11px;
            margin-top: 24px;
        }

        .security-note span {
            color: #22c55e;
        }

        .form-bottom {
            border-top: 1px solid var(--border);
            padding-top: 20px;
            margin-top: 35px;
            text-align: center;
            color: #64748b;
            font-size: 11px;
        }

        @media (max-width: 850px) {
            .login-container {
                max-width: 520px;
                grid-template-columns: 1fr;
            }

            .intro-panel {
                display: none;
            }

            .form-panel {
                padding: 45px 35px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 15px;
            }

            .login-container {
                border-radius: 15px;
            }

            .form-panel {
                padding: 35px 23px;
            }

            .form-heading h2 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

<div class="login-container">

    <section class="intro-panel">

        <div class="brand">
            <div class="brand-logo">EC</div>

            <div>
                <div class="brand-name">EQUIP CONTROL</div>
                <div class="brand-caption">EQUIPMENT MANAGEMENT SYSTEM</div>
            </div>
        </div>

        <div class="intro-content">

            <div class="intro-tag">
                <span></span>
                HỆ THỐNG QUẢN LÝ THIẾT BỊ
            </div>

            <h1>
                Quản lý thông minh.<br>
                Vận hành <strong>hiệu quả.</strong>
            </h1>

            <p>
                Nền tảng quản lý thiết bị và linh kiện điện tử tập trung,
                hỗ trợ theo dõi kho, mượn trả, bảo trì và thống kê
                hoạt động một cách nhanh chóng, chính xác.
            </p>

            <div class="feature-list">

                <div class="feature">
                    <div class="feature-icon">▦</div>
                    <span>Quản lý thiết bị và linh kiện tập trung</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">⇄</div>
                    <span>Theo dõi nhập xuất kho và mượn trả</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">▤</div>
                    <span>Báo cáo và thống kê trực quan</span>
                </div>

            </div>

        </div>

        <div class="intro-footer">
            © <?php echo date("Y"); ?> EQUIP CONTROL. All rights reserved.
        </div>

    </section>

    <section class="form-panel">

        <div class="form-heading">
            <div class="small-title">WELCOME BACK</div>
            <h2>Đăng nhập</h2>
            <p>Vui lòng nhập thông tin tài khoản để truy cập hệ thống.</p>
        </div>

        <?php if ($logoutSuccess): ?>
            <div class="success-message">
                <div class="success-icon">✓</div>
                <div>
                    <strong>Đăng xuất thành công!</strong><br>
                    Bạn đã đăng xuất khỏi hệ thống an toàn.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="error-message">
                ⚠ <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="on">

            <div class="form-group">
                <label for="username">Tài khoản</label>

                <div class="input-wrapper">
                    <span class="input-icon">♙</span>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Nhập tên tài khoản"
                        value="<?php echo htmlspecialchars($username, ENT_QUOTES, "UTF-8"); ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">Mật khẩu</label>

                <div class="input-wrapper">
                    <span class="input-icon">♙</span>

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
                        aria-label="Hiện hoặc ẩn mật khẩu"
                    >◉</button>
                </div>
            </div>

            <div class="form-options">
                <label class="remember">
                    <input type="checkbox" id="rememberUsername">
                    Ghi nhớ tài khoản
                </label>
            </div>

            <button type="submit" class="login-button">
                ĐĂNG NHẬP HỆ THỐNG &nbsp; →
            </button>

        </form>

        <div class="security-note">
            <span>●</span>
            Kết nối bảo mật trong hệ thống
        </div>

        <div class="form-bottom">
            EQUIP CONTROL · Hệ thống quản lý thiết bị và linh kiện
        </div>

    </section>

</div>

<script>
    const passwordInput = document.getElementById("password");
    const togglePassword = document.getElementById("togglePassword");

    togglePassword.addEventListener("click", function () {
        if (passwordInput.type === "password") {
            passwordInput.type = "text";
            togglePassword.textContent = "◎";
        } else {
            passwordInput.type = "password";
            togglePassword.textContent = "◉";
        }
    });

    const usernameInput = document.getElementById("username");
    const rememberCheckbox = document.getElementById("rememberUsername");

    try {
        const savedUsername = localStorage.getItem("equip_control_username");

        if (savedUsername) {
            usernameInput.value = savedUsername;
            rememberCheckbox.checked = true;
        }
    } catch (e) {}

    rememberCheckbox.addEventListener("change", function () {
        try {
            if (rememberCheckbox.checked) {
                localStorage.setItem(
                    "equip_control_username",
                    usernameInput.value.trim()
                );
            } else {
                localStorage.removeItem("equip_control_username");
            }
        } catch (e) {}
    });

    usernameInput.addEventListener("input", function () {
        if (rememberCheckbox.checked) {
            try {
                localStorage.setItem(
                    "equip_control_username",
                    usernameInput.value.trim()
                );
            } catch (e) {}
        }
    });
</script>

</body>
</html>
