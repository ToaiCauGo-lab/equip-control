
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/activity_log.php";

requireAdmin();

function usersEscape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "success";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);

// XỬ LÝ THAO TÁC
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        // THÊM TÀI KHOẢN
        if ($action === "add") {

            $fullName = trim($_POST["full_name"] ?? "");
            $username = trim($_POST["username"] ?? "");
            $password = $_POST["password"] ?? "";
            $role = $_POST["role"] ?? "staff";

            if ($fullName === "" || $username === "" || $password === "") {
                throw new Exception("Vui lòng nhập đầy đủ thông tin tài khoản.");
            }

            if (strlen($password) < 6) {
                throw new Exception("Mật khẩu phải có ít nhất 6 ký tự.");
            }

            if (!in_array($role, ["admin", "staff"], true)) {
                throw new Exception("Vai trò không hợp lệ.");
            }

            $check = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $check->execute([$username]);

            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("Tên đăng nhập đã tồn tại.");
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users (full_name, username, password, role, status)
                VALUES (?, ?, ?, ?, 1)
            ");

            $stmt->execute([
                $fullName,
                $username,
                $hashedPassword,
                $role
            ]);

            logActivity(
                $conn,
                "Tạo tài khoản",
                "Tạo tài khoản mới: {$username}, họ tên: {$fullName}, vai trò: {$role}."
            );

            $message = "Tạo tài khoản thành công!";

        // SỬA TÀI KHOẢN
        } elseif ($action === "edit") {

            $userId = (int)($_POST["user_id"] ?? 0);
            $fullName = trim($_POST["full_name"] ?? "");
            $username = trim($_POST["username"] ?? "");
            $role = $_POST["role"] ?? "";

            if ($userId <= 0 || $fullName === "" || $username === "") {
                throw new Exception("Thông tin tài khoản không hợp lệ.");
            }

            if (!in_array($role, ["admin", "staff"], true)) {
                throw new Exception("Vai trò không hợp lệ.");
            }

            $stmt = $conn->prepare("
                SELECT id, username, full_name, role
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $oldUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldUser) {
                throw new Exception("Không tìm thấy tài khoản.");
            }

            $check = $conn->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE username = ? AND id != ?
            ");
            $check->execute([$username, $userId]);

            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("Tên đăng nhập đã được sử dụng.");
            }

            if ($userId === $currentUserId && $role !== "admin") {
                throw new Exception("Không thể tự thay đổi vai trò quản trị của tài khoản đang đăng nhập.");
            }

            $update = $conn->prepare("
                UPDATE users
                SET full_name = ?, username = ?, role = ?
                WHERE id = ?
            ");

            $update->execute([
                $fullName,
                $username,
                $role,
                $userId
            ]);

            if ($userId === $currentUserId) {
                $_SESSION["full_name"] = $fullName;
                $_SESSION["username"] = $username;
                $_SESSION["role"] = $role;
            }

            logActivity(
                $conn,
                "Cập nhật tài khoản",
                "Cập nhật tài khoản {$oldUser['username']}. "
                . "Tên mới: {$username}, họ tên: {$fullName}, vai trò: {$role}."
            );

            $message = "Cập nhật tài khoản thành công!";

        // KHÓA / MỞ KHÓA
        } elseif ($action === "toggle") {

            $userId = (int)($_POST["user_id"] ?? 0);

            if ($userId <= 0) {
                throw new Exception("Tài khoản không hợp lệ.");
            }

            if ($userId === $currentUserId) {
                throw new Exception("Không thể tự khóa tài khoản đang đăng nhập.");
            }

            $stmt = $conn->prepare("
                SELECT id, username, full_name, role, status
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("Không tìm thấy tài khoản.");
            }

            $newStatus = ((int)$user["status"] === 1) ? 0 : 1;

            if ($newStatus === 0 && $user["role"] === "admin") {

                $adminCount = (int)$conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin' AND status = 1
                ")->fetchColumn();

                if ($adminCount <= 1) {
                    throw new Exception("Không thể khóa quản trị viên hoạt động cuối cùng.");
                }
            }

            $update = $conn->prepare("
                UPDATE users SET status = ? WHERE id = ?
            ");
            $update->execute([$newStatus, $userId]);

            $actionName = $newStatus === 1
                ? "Mở khóa tài khoản"
                : "Khóa tài khoản";

            logActivity(
                $conn,
                $actionName,
                "{$actionName}: {$user['username']} ({$user['full_name']})."
            );

            $message = $newStatus === 1
                ? "Mở khóa tài khoản thành công!"
                : "Khóa tài khoản thành công!";

        // ĐẶT LẠI MẬT KHẨU
        } elseif ($action === "reset_password") {

            $userId = (int)($_POST["user_id"] ?? 0);
            $newPassword = $_POST["new_password"] ?? "";

            if ($userId <= 0) {
                throw new Exception("Tài khoản không hợp lệ.");
            }

            if (strlen($newPassword) < 6) {
                throw new Exception("Mật khẩu mới phải có ít nhất 6 ký tự.");
            }

            $stmt = $conn->prepare("
                SELECT id, username, full_name
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("Không tìm thấy tài khoản.");
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $update = $conn->prepare("
                UPDATE users SET password = ? WHERE id = ?
            ");
            $update->execute([$hashedPassword, $userId]);

            logActivity(
                $conn,
                "Đặt lại mật khẩu",
                "Đặt lại mật khẩu cho tài khoản {$user['username']} ({$user['full_name']})."
            );

            $message = "Đặt lại mật khẩu thành công!";

        // XÓA TÀI KHOẢN
        } elseif ($action === "delete") {

            $userId = (int)($_POST["user_id"] ?? 0);

            if ($userId <= 0) {
                throw new Exception("Tài khoản không hợp lệ.");
            }

            if ($userId === $currentUserId) {
                throw new Exception("Không thể xóa tài khoản đang đăng nhập.");
            }

            $stmt = $conn->prepare("
                SELECT id, username, full_name, role, status
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("Không tìm thấy tài khoản.");
            }

            if ($user["role"] === "admin" && (int)$user["status"] === 1) {

                $adminCount = (int)$conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin' AND status = 1
                ")->fetchColumn();

                if ($adminCount <= 1) {
                    throw new Exception("Không thể xóa quản trị viên hoạt động cuối cùng.");
                }
            }

            $delete = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete->execute([$userId]);

            logActivity(
                $conn,
                "Xóa tài khoản",
                "Đã xóa tài khoản {$user['username']} ({$user['full_name']}), vai trò: {$user['role']}."
            );

            $message = "Xóa tài khoản thành công!";

        } else {
            throw new Exception("Thao tác không hợp lệ.");
        }

    } catch (PDOException $e) {
        $message = "Có lỗi cơ sở dữ liệu. Vui lòng kiểm tra lại.";
        $messageType = "error";

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// THỐNG KÊ
$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

$activeUsers = (int)$conn->query("
    SELECT COUNT(*) FROM users WHERE status = 1
")->fetchColumn();

$lockedUsers = (int)$conn->query("
    SELECT COUNT(*) FROM users WHERE status = 0
")->fetchColumn();

$adminUsers = (int)$conn->query("
    SELECT COUNT(*) FROM users WHERE role = 'admin'
")->fetchColumn();

// TÌM KIẾM
$keyword = trim($_GET["keyword"] ?? "");
$roleFilter = $_GET["role"] ?? "";
$statusFilter = $_GET["status"] ?? "";

$where = [];
$params = [];

if ($keyword !== "") {
    $where[] = "(full_name LIKE ? OR username LIKE ?)";
    $search = "%" . $keyword . "%";
    array_push($params, $search, $search);
}

if (in_array($roleFilter, ["admin", "staff"], true)) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter === "1" || $statusFilter === "0") {
    $where[] = "status = ?";
    $params[] = (int)$statusFilter;
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// PHÂN TRANG
$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$countStmt = $conn->prepare("
    SELECT COUNT(*) FROM users $whereSQL
");
$countStmt->execute($params);

$filteredTotal = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// LẤY DANH SÁCH
$stmt = $conn->prepare("
    SELECT id, full_name, username, role, status
    FROM users
    $whereSQL
    ORDER BY id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Quản lý tài khoản - EQUIP CONTROL</title>

<link rel="stylesheet" href="/assets/css/style.css?v=15">

<style>
* {
    box-sizing: border-box;
}

html, body {
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100%;
    background: #0b1120 !important;
    color: #e5edf8;
}

body {
    display: block !important;
    overflow-x: hidden;
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
    min-width: 0 !important;
    min-height: 100vh;
    padding: 0 !important;
    background: #0b1120 !important;
}

.users-content {
    width: 100%;
    padding: 25px;
    min-width: 0;
}

.users-heading {
    color: #22d3ee;
    font-size: 24px;
    margin: 0 0 8px;
}

.users-subtitle {
    color: #91a6bd;
    font-size: 13px;
    margin-bottom: 24px;
}

.users-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.users-stat {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 11px;
    padding: 18px;
}

.users-stat span {
    display: block;
    color: #91a6bd;
    font-size: 12px;
    margin-bottom: 10px;
}

.users-stat strong {
    color: #22d3ee;
    font-size: 25px;
}

.users-panel {
    background: #111f31;
    border: 1px solid #263b52;
    border-radius: 12px;
    padding: 21px;
    margin-bottom: 22px;
    min-width: 0;
}

.users-panel h3 {
    color: #22d3ee;
    font-size: 16px;
    margin: 0 0 18px;
}

.users-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.users-field {
    min-width: 0;
}

.users-field label {
    display: block;
    color: #a9bfd5;
    font-size: 12px;
    margin-bottom: 7px;
}

.users-field input,
.users-field select {
    width: 100%;
    padding: 11px 12px;
    background: #0b1726;
    color: #e5edf8;
    border: 1px solid #304963;
    border-radius: 7px;
    outline: none;
    font-size: 13px;
}

.users-field input:focus,
.users-field select:focus {
    border-color: #22d3ee;
}

.users-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 17px;
}

.users-btn {
    border: 0;
    border-radius: 6px;
    padding: 9px 12px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.users-primary {
    background: #0891b2;
    color: white;
}

.users-secondary {
    background: #30445c;
    color: white;
}

.users-edit {
    background: #1d4ed8;
    color: white;
}

.users-lock {
    background: #854d0e;
    color: #fef3c7;
}

.users-unlock {
    background: #14532d;
    color: #bbf7d0;
}

.users-delete {
    background: #7f1d1d;
    color: #fecaca;
}

.users-password {
    background: #4c1d95;
    color: #e9d5ff;
}

.users-search {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.users-search input,
.users-search select {
    padding: 10px 12px;
    background: #0b1726;
    border: 1px solid #304963;
    color: #e5edf8;
    border-radius: 7px;
    font-size: 12px;
}

.users-search input {
    flex: 1;
    min-width: 180px;
}

.users-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.users-table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

.users-table th {
    padding: 13px 11px;
    text-align: left;
    background: #17283c;
    color: #22d3ee;
    font-size: 12px;
}

.users-table td {
    padding: 12px 11px;
    border-bottom: 1px solid #263b52;
    font-size: 12px;
    color: #d4e1ef;
    vertical-align: middle;
}

.users-table tbody tr:hover {
    background: rgba(34,211,238,.04);
}

.users-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.users-admin {
    background: #312e81;
    color: #c7d2fe;
}

.users-staff {
    background: #164e63;
    color: #a5f3fc;
}

.users-active {
    background: #123c32;
    color: #86efac;
}

.users-inactive {
    background: #49371e;
    color: #fcd34d;
}

.users-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
}

.users-alert.success {
    color: #86efac;
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
}

.users-alert.error {
    color: #fca5a5;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
}

.users-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.users-pagination a {
    padding: 8px 12px;
    border-radius: 6px;
    background: #1b3047;
    color: #d4e1ef;
    text-decoration: none;
    font-size: 12px;
}

.users-pagination .current {
    background: #0891b2;
    color: white;
}

.users-note {
    color: #91a6bd;
    font-size: 12px;
    margin-top: 14px;
}

.users-empty {
    text-align: center;
    padding: 30px !important;
    color: #91a6bd !important;
}

@media(max-width: 950px) {
    .users-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 768px) {
    .sidebar {
        width: 220px !important;
    }

    .main-content {
        margin-left: 220px !important;
        width: calc(100% - 220px) !important;
    }

    .users-content {
        padding: 16px;
    }
}

@media(max-width: 600px) {
    .users-form-grid,
    .users-stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
<div class="users-content">

    <h1 class="users-heading">QUẢN LÝ TÀI KHOẢN</h1>

    <p class="users-subtitle">
        Quản lý người dùng, phân quyền và trạng thái tài khoản hệ thống.
    </p>

    <?php if ($message !== ""): ?>
        <div class="users-alert <?= usersEscape($messageType) ?>">
            <?= usersEscape($message) ?>
        </div>
    <?php endif; ?>

    <div class="users-stats">
        <div class="users-stat">
            <span>Tổng tài khoản</span>
            <strong><?= number_format($totalUsers) ?></strong>
        </div>

        <div class="users-stat">
            <span>Đang hoạt động</span>
            <strong><?= number_format($activeUsers) ?></strong>
        </div>

        <div class="users-stat">
            <span>Đã khóa</span>
            <strong><?= number_format($lockedUsers) ?></strong>
        </div>

        <div class="users-stat">
            <span>Quản trị viên</span>
            <strong><?= number_format($adminUsers) ?></strong>
        </div>
    </div>

    <section class="users-panel">
        <h3>TẠO TÀI KHOẢN MỚI</h3>

        <form method="POST">
            <input type="hidden" name="action" value="add">

            <div class="users-form-grid">
                <div class="users-field">
                    <label>Họ và tên *</label>
                    <input type="text" name="full_name" required
                           placeholder="Nhập họ và tên">
                </div>

                <div class="users-field">
                    <label>Tên đăng nhập *</label>
                    <input type="text" name="username" required
                           placeholder="Nhập tên đăng nhập">
                </div>

                <div class="users-field">
                    <label>Mật khẩu *</label>
                    <input type="password" name="password" required
                           minlength="6" placeholder="Tối thiểu 6 ký tự">
                </div>

                <div class="users-field">
                    <label>Vai trò *</label>
                    <select name="role" required>
                        <option value="staff">Nhân viên</option>
                        <option value="admin">Quản trị viên</option>
                    </select>
                </div>
            </div>

            <div class="users-actions">
                <button type="submit" class="users-btn users-primary">
                    TẠO TÀI KHOẢN
                </button>
            </div>
        </form>
    </section>

    <section class="users-panel">
        <h3>DANH SÁCH TÀI KHOẢN</h3>

        <form method="GET" class="users-search">
            <input type="text" name="keyword"
                   value="<?= usersEscape($keyword) ?>"
                   placeholder="Tìm theo họ tên hoặc tên đăng nhập...">

            <select name="role">
                <option value="">Tất cả vai trò</option>
                <option value="admin" <?= $roleFilter === "admin" ? "selected" : "" ?>>
                    Quản trị viên
                </option>
                <option value="staff" <?= $roleFilter === "staff" ? "selected" : "" ?>>
                    Nhân viên
                </option>
            </select>

            <select name="status">
                <option value="">Tất cả trạng thái</option>
                <option value="1" <?= $statusFilter === "1" ? "selected" : "" ?>>
                    Hoạt động
                </option>
                <option value="0" <?= $statusFilter === "0" ? "selected" : "" ?>>
                    Đã khóa
                </option>
            </select>

            <button type="submit" class="users-btn users-primary">
                TÌM KIẾM
            </button>

            <a href="/users.php" class="users-btn users-secondary">
                LÀM MỚI
            </a>
        </form>

        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Họ và tên</th>
                        <th>Tên đăng nhập</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($users): ?>

                    <?php foreach ($users as $index => $user): ?>
                        <tr>
                            <td><?= $offset + $index + 1 ?></td>

                            <td><?= usersEscape($user["full_name"]) ?></td>

                            <td><?= usersEscape($user["username"]) ?></td>

                            <td>
                                <span class="users-badge <?= $user["role"] === "admin" ? "users-admin" : "users-staff" ?>">
                                    <?= $user["role"] === "admin" ? "Quản trị viên" : "Nhân viên" ?>
                                </span>
                            </td>

                            <td>
                                <span class="users-badge <?= (int)$user["status"] === 1 ? "users-active" : "users-inactive" ?>">
                                    <?= (int)$user["status"] === 1 ? "Hoạt động" : "Đã khóa" ?>
                                </span>
                            </td>

                            <td>
                                <div class="users-actions" style="margin:0">

                                    <button type="button"
                                        class="users-btn users-edit"
                                        data-id="<?= (int)$user['id'] ?>"
                                        data-name="<?= usersEscape($user['full_name']) ?>"
                                        data-username="<?= usersEscape($user['username']) ?>"
                                        data-role="<?= usersEscape($user['role']) ?>"
                                        onclick="editUser(this)">
                                        SỬA
                                    </button>

                                    <?php if ((int)$user["id"] !== $currentUserId): ?>

                                        <form method="POST"
                                              onsubmit="return confirm('Xác nhận thay đổi trạng thái tài khoản này?');">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?= (int)$user["id"] ?>">

                                            <button type="submit"
                                                class="users-btn <?= (int)$user["status"] === 1 ? "users-lock" : "users-unlock" ?>">
                                                <?= (int)$user["status"] === 1 ? "KHÓA" : "MỞ KHÓA" ?>
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                    <button type="button"
                                        class="users-btn users-password"
                                        data-id="<?= (int)$user['id'] ?>"
                                        data-username="<?= usersEscape($user['username']) ?>"
                                        onclick="resetPassword(this)">
                                        ĐẶT LẠI MK
                                    </button>

                                    <?php if ((int)$user["id"] !== $currentUserId): ?>

                                        <form method="POST"
                                              onsubmit="return confirm('Bạn chắc chắn muốn xóa tài khoản này? Thao tác không thể hoàn tác.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int)$user["id"] ?>">

                                            <button type="submit" class="users-btn users-delete">
                                                XÓA
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="6" class="users-empty">
                            Không tìm thấy tài khoản phù hợp.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="users-pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php
                    $query = $_GET;
                    $query["page"] = $i;
                    ?>

                    <a href="?<?= usersEscape(http_build_query($query)) ?>"
                       class="<?= $page === $i ? "current" : "" ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <p class="users-note">
            Hiển thị <?= count($users) ?> / <?= $filteredTotal ?> tài khoản.
        </p>
    </section>

</div>
</main>

<!-- MODAL SỬA TÀI KHOẢN -->
<div id="editModal" class="users-modal">
    <div class="users-modal-box">
        <h3>CHỈNH SỬA TÀI KHOẢN</h3>

        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="users-field">
                <label>Họ và tên</label>
                <input type="text" name="full_name" id="editFullName" required>
            </div>

            <div class="users-field">
                <label>Tên đăng nhập</label>
                <input type="text" name="username" id="editUsername" required>
            </div>

            <div class="users-field">
                <label>Vai trò</label>
                <select name="role" id="editRole">
                    <option value="staff">Nhân viên</option>
                    <option value="admin">Quản trị viên</option>
                </select>
            </div>

            <div class="users-actions">
                <button type="submit" class="users-btn users-primary">LƯU THAY ĐỔI</button>
                <button type="button" class="users-btn users-secondary" onclick="closeModal('editModal')">ĐÓNG</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ĐẶT LẠI MẬT KHẨU -->
<div id="passwordModal" class="users-modal">
    <div class="users-modal-box">
        <h3>ĐẶT LẠI MẬT KHẨU</h3>

        <p id="passwordUsername" style="color:#91a6bd;font-size:13px;"></p>

        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="passwordUserId">

            <div class="users-field">
                <label>Mật khẩu mới</label>
                <input type="password" name="new_password" required
                       minlength="6" placeholder="Nhập mật khẩu mới">
            </div>

            <div class="users-actions">
                <button type="submit" class="users-btn users-primary">XÁC NHẬN</button>
                <button type="button" class="users-btn users-secondary" onclick="closeModal('passwordModal')">ĐÓNG</button>
            </div>
        </form>
    </div>
</div>

<style>
.users-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.75);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 15px;
}

.users-modal-box {
    background: #111f31;
    border: 1px solid #304963;
    border-radius: 12px;
    padding: 24px;
    width: 100%;
    max-width: 450px;
}

.users-modal-box h3 {
    color: #22d3ee;
    margin-top: 0;
}

.users-modal-box .users-field {
    margin-bottom: 14px;
}
</style>

<script>
function editUser(button) {
    document.getElementById("editUserId").value = button.dataset.id;
    document.getElementById("editFullName").value = button.dataset.name;
    document.getElementById("editUsername").value = button.dataset.username;
    document.getElementById("editRole").value = button.dataset.role;

    document.getElementById("editModal").style.display = "flex";
}

function resetPassword(button) {
    document.getElementById("passwordUserId").value = button.dataset.id;
    document.getElementById("passwordUsername").textContent =
        "Tài khoản: " + button.dataset.username;

    document.getElementById("passwordModal").style.display = "flex";
}

function closeModal(id) {
    document.getElementById(id).style.display = "none";
}

window.addEventListener("click", function(event) {
    if (event.target.id === "editModal") {
        closeModal("editModal");
    }

    if (event.target.id === "passwordModal") {
        closeModal("passwordModal");
    }
});
</script>

</body>
</html>
