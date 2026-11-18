
<?php

if (!function_exists("logActivity")) {
    function logActivity(PDO $conn, string $action, string $description = ""): bool
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $userId = $_SESSION["user_id"] ?? null;
            $username = $_SESSION["username"] ?? null;
            $fullName = $_SESSION["full_name"] ?? $username ?? "Không xác định";
            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? null;

            $sql = "INSERT INTO activity_logs
                    (user_id, username, full_name, action, description, ip_address)
                    VALUES
                    (:user_id, :username, :full_name, :action, :description, :ip_address)";

            $stmt = $conn->prepare($sql);

            return $stmt->execute([
                ":user_id" => $userId,
                ":username" => $username,
                ":full_name" => $fullName,
                ":action" => $action,
                ":description" => $description,
                ":ip_address" => $ipAddress
            ]);
        } catch (Throwable $e) {
            // Không để lỗi ghi nhật ký làm gián đoạn chức năng chính.
            return false;
        }
    }
}
