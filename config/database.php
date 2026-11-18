
<?php
// Thông tin kết nối MySQL
$host = "localhost";
$dbname = "equip_control";
$username = "root";
$password = ""; // Mặc định XAMPP thường để trống

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    // Bật chế độ báo lỗi khi truy vấn thất bại
    $conn->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // Lấy dữ liệu dưới dạng mảng kết hợp
    $conn->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {
    // Không hiển thị thông tin kết nối nhạy cảm ra trình duyệt
    die("Không thể kết nối cơ sở dữ liệu. Hãy kiểm tra cấu hình MySQL.");
}
?>
