
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireLogin();

$userId = (int)($_SESSION["user_id"] ?? 0);
$message = "";
$messageType = "success";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    try {

        if ($userId <= 0) {
            throw new Exception("Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.");
        }

        if (
            $currentPassword === "" ||
            $newPassword === "" ||
            $confirmPassword === ""
        ) {
            throw new Exception("Vui lòng nhập đầy đủ thông tin.");
        }

        if (strlen($newPassword) < 6) {
            throw new Exception("Mật khẩu mới phải có ít nhất 6 ký tự.");
        }

        if ($newPassword !== $confirmPassword) {
            throw new Exception("Mật khẩu xác nhận không khớp.");
        }

        if ($currentPassword === $newPassword) {
            throw new Exception("Mật khẩu mới phải khác mật khẩu hiện tại.");
        }

        // LẤY THÔNG TIN TÀI KHOẢN
        $stmt = $conn->prepare("
            SELECT id, username, full_name, password
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("Không tìm thấy tài khoản.");
        }

        // KIỂM TRA MẬT KHẨU HIỆN TẠI
        if (!password_verify($currentPassword, $user["password"])) {
            throw new Exception("Mật khẩu hiện tại không chính xác.");
        }

        // MÃ HÓA MẬT KHẨU MỚI
        $hashedPassword = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        if ($hashedPassword === false) {
            throw new Exception("Không thể mã hóa mật khẩu mới.");
        }

        // CẬP NHẬT DATABASE
        $update = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");

        $update->execute([
            $hashedPassword,
            $userId
        ]);

        // GHI NHẬT KÝ HOẠT ĐỘNG
        logActivity(
            $conn,
            "Đổi mật khẩu",
            "Người dùng {$user['username']} ({$user['full_name']}) đã đổi mật khẩu tài khoản."
        );

        $message = "Đổi mật khẩu thành công!";

    } catch (PDOException $e) {

        error_log("Change password database error: " . $e->getMessage());

        $message = "Có lỗi xảy ra khi cập nhật mật khẩu. Vui lòng thử lại.";
        $messageType = "error";

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Đổi mật khẩu - EQUIP CONTROL</title>

    <link rel="stylesheet" href="/assets/css/style.css?v=16">

    <style>
        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background: #0b1120 !important;
            color: #e5edf8;
        }

        body {
            display: block !important;
        }

        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            bottom: 0 !important;
            width: 250px !important;
            height: 100vh !important;
            overflow-y: auto !important;
            z-index: 1000 !important;
            margin: 0 !important;
        }

        .main-content {
            position: relative !important;
            display: block !important;
            margin-left: 250px !important;
            margin-top: 0 !important;
            width: calc(100% - 250px) !important;
            min-height: 100vh;
            padding: 0 !important;
            background: #0b1120 !important;
        }

        .password-content {
            width: 100%;
            padding: 28px;
        }

        .password-heading {
            color: #22d3ee;
            font-size: 24px;
            margin: 0 0 8px;
        }

        .password-subtitle {
            color: #91a6bd;
            font-size: 13px;
            margin: 0 0 25px;
        }

        .password-card {
            background: #111f31;
            border: 1px solid #263b52;
            border-radius: 12px;
            padding: 25px;
            max-width: 650px;
        }

        .password-card h3 {
            color: #22d3ee;
            font-size: 16px;
            margin: 0 0 22px;
        }

        .password-field {
            margin-bottom: 19px;
        }

        .password-field label {
            display: block;
            color: #a9bfd5;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .password-field input {
            width: 100%;
            padding: 12px;
            border: 1px solid #304963;
            background: #0b1726;
            color: #e5edf8;
            border-radius: 7px;
            font-size: 13px;
            outline: none;
        }

        .password-field input:focus {
            border-color: #22d3ee;
        }

        .password-button {
            background: #0891b2;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }

        .password-button:hover {
            background: #0e7490;
        }

        .password-alert {
            max-width: 650px;
            padding: 13px 15px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .password-alert.success {
            color: #86efac;
            background: rgba(34,197,94,.12);
            border: 1px solid rgba(34,197,94,.3);
        }

        .password-alert.error {
            color: #fca5a5;
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.3);
        }

        .password-note {
            margin-top: 20px;
            padding: 14px;
            background: #0b1726;
            border: 1px solid #263b52;
            border-radius: 8px;
            color: #91a6bd;
            font-size: 12px;
            line-height: 1.7;
        }

        .password-note ul {
            margin-bottom: 0;
            padding-left: 20px;
        }

        @media(max-width: 768px) {
            .sidebar {
                width: 220px !important;
            }

            .main-content {
                margin-left: 220px !important;
                width: calc(100% - 220px) !important;
            }

            .password-content {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">

    <div class="password-content">

        <h1 class="password-heading">ĐỔI MẬT KHẨU</h1>

        <p class="password-subtitle">
            Cập nhật mật khẩu để bảo vệ tài khoản EQUIP CONTROL.
        </p>

        <?php if ($message !== ""): ?>
            <div class="password-alert <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="password-card">

            <h3>THÔNG TIN MẬT KHẨU</h3>

            <form method="POST" autocomplete="off">

                <div class="password-field">
                    <label for="current_password">Mật khẩu hiện tại *</label>

                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                        placeholder="Nhập mật khẩu hiện tại"
                    >
                </div>

                <div class="password-field">
                    <label for="new_password">Mật khẩu mới *</label>

                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        required
                        minlength="6"
                        placeholder="Nhập mật khẩu mới"
                    >
                </div>

                <div class="password-field">
                    <label for="confirm_password">Xác nhận mật khẩu mới *</label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                        minlength="6"
                        placeholder="Nhập lại mật khẩu mới"
                    >
                </div>

                <button type="submit" class="password-button">
                    CẬP NHẬT MẬT KHẨU
                </button>

            </form>

            <div class="password-note">
                <strong>Lưu ý:</strong>
                <ul>
                    <li>Mật khẩu mới phải có ít nhất 6 ký tự.</li>
                    <li>Mật khẩu xác nhận phải trùng với mật khẩu mới.</li>
                    <li>Mật khẩu được mã hóa trước khi lưu vào hệ thống.</li>
                    <li>Thao tác đổi mật khẩu thành công sẽ được ghi vào nhật ký hoạt động.</li>
                </ul>
            </div>

        </section>

    </div>

</main>

</body>
</html>
