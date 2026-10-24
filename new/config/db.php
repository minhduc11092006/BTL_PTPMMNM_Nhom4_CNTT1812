<?php
// config/db.php - Cấu hình và kết nối cơ sở dữ liệu MySQL bằng PDO

$db_host = 'localhost';
$db_port = '3306';
$db_name = 'transit_db';
$db_user = 'root';
$db_pass = ''; // Mặc định XAMPP không có mật khẩu

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // Thông báo lỗi nếu chưa tạo CSDL hoặc MySQL chưa khởi động
    die("
    <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 50px auto; padding: 25px; border-radius: 10px; background: #fff3cd; border: 1px solid #ffeeba; color: #856404;'>
        <h3 style='margin-top: 0;'>⚠️ Lỗi kết nối Cơ sở dữ liệu (MySQL)</h3>
        <p>Không thể kết nối đến cơ sở dữ liệu <code>{$db_name}</code>.</p>
        <p><strong>Nguyên nhân thường gặp:</strong></p>
        <ol>
            <li>Dịch vụ MySQL trong XAMPP chưa được bật (Hãy ấn Start MySQL trong XAMPP Control Panel).</li>
            <li>Chưa import file <code>database/database.sql</code> để tạo database và bảng <code>users</code>.</li>
        </ol>
        <p><strong>Chi tiết lỗi:</strong> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>
    </div>");
}
