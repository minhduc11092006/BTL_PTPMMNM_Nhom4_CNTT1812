-- database/database.sql: Script tạo cơ sở dữ liệu và bảng users
-- Khởi tạo Database nếu chưa tồn tại
CREATE DATABASE IF NOT EXISTS `transit_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `transit_db`;

-- 1. Tạo bảng users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL COMMENT 'Lưu trữ mật khẩu đã băm bằng password_hash',
    `fullname` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user' COMMENT 'user: hành khách, admin: quản trị',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Thêm tài khoản Admin mặc định
-- Tên đăng nhập: admin
-- Mật khẩu ban đầu: 'admin123'
-- Hash được tạo bởi hàm: password_hash('admin123', PASSWORD_BCRYPT)
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `role`) VALUES
('admin', '$2y$10$wKz0bB1bY0/cKqV5sK1pTuYf8Y4gO2.C3Jq3Kx7Uv8yqK8gM3O2pG', 'Quản Trị Viên', 'admin@transit.local', 'admin');

-- 3. Thêm tài khoản Khách hàng (User) mẫu để kiểm thử
-- Tên đăng nhập: hanhkhach1
-- Mật khẩu ban đầu: 'password123'
-- Hash: password_hash('password123', PASSWORD_BCRYPT)
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `role`) VALUES
('hanhkhach1', '$2y$10$QO0Bw/Z1j78.M4pG9e6/9evv2h2DkM9zS48.7zF/bB1gC1yA2eD9i', 'Nguyễn Văn A', 'hanhkhach1@example.com', 'user');
