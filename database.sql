-- database.sql: Script tạo cơ sở dữ liệu, bảng users và bảng GTFS
-- Khởi tạo Database nếu chưa tồn tại
CREATE DATABASE IF NOT EXISTS `transit_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `transit_db`;

-- =============================================
-- 1. Bảng users (giữ nguyên)
-- =============================================
DROP TABLE IF EXISTS `saved_queries`;
DROP TABLE IF EXISTS `gtfs_validation_errors`;
DROP TABLE IF EXISTS `gtfs_stop_times`;
DROP TABLE IF EXISTS `gtfs_trips`;
DROP TABLE IF EXISTS `gtfs_calendar_dates`;
DROP TABLE IF EXISTS `gtfs_calendar`;
DROP TABLE IF EXISTS `gtfs_stops`;
DROP TABLE IF EXISTS `gtfs_routes`;
DROP TABLE IF EXISTS `gtfs_agency`;
DROP TABLE IF EXISTS `gtfs_feed_versions`;
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
-- Mật khẩu ban đầu: 'admin123'
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `role`) VALUES
('admin', '$2y$12$WoxhFEoU6ZwUT0JHVVRVgeCJHLgwOViUq7ZQHbcRIys5/NuoSrh2e', 'Quản Trị Viên', 'admin@transit.local', 'admin');

-- 3. Thêm tài khoản Khách hàng (User) mẫu để kiểm thử
-- Mật khẩu ban đầu: 'password123'
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `role`) VALUES
('hanhkhach1', '$2y$12$ti9uZ6WVETVVdnHxJ2uJvefVYygYTK/WpXp3WtWLQU4YVr1Lhttzu', 'Nguyễn Văn A', 'hanhkhach1@example.com', 'user');

-- =============================================
-- 4. Bảng quản lý phiên bản GTFS
-- =============================================
CREATE TABLE `gtfs_feed_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version_name` VARCHAR(100) NOT NULL COMMENT 'Tên phiên bản do admin đặt',
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `status` ENUM('pending','valid','invalid','active') NOT NULL DEFAULT 'pending'
        COMMENT 'pending=chưa validate, valid=hợp lệ, invalid=có lỗi, active=đang sử dụng',
    `notes` TEXT COMMENT 'Ghi chú của admin',
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Bảng lỗi validate GTFS
-- =============================================
CREATE TABLE `gtfs_validation_errors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `error_type` VARCHAR(50) NOT NULL COMMENT 'missing_file, missing_column, invalid_ref, invalid_format',
    `file_name` VARCHAR(50) COMMENT 'Tên file GTFS gây lỗi',
    `column_name` VARCHAR(50),
    `row_number` INT UNSIGNED DEFAULT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 6. Bảng GTFS: agency
-- =============================================
CREATE TABLE `gtfs_agency` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `agency_id` VARCHAR(100) NOT NULL,
    `agency_name` VARCHAR(255) NOT NULL,
    `agency_url` VARCHAR(500) DEFAULT NULL,
    `agency_timezone` VARCHAR(100) DEFAULT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_agency_version` (`feed_version_id`, `agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 7. Bảng GTFS: routes (tuyến)
-- =============================================
CREATE TABLE `gtfs_routes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `route_id` VARCHAR(100) NOT NULL,
    `agency_id` VARCHAR(100) DEFAULT NULL,
    `route_short_name` VARCHAR(50) DEFAULT NULL,
    `route_long_name` VARCHAR(255) DEFAULT NULL,
    `route_type` TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT '0=Tram, 1=Metro, 2=Rail, 3=Bus, 4=Ferry, 5=CableTram, 6=Aerial, 7=Funicular',
    `route_color` VARCHAR(6) DEFAULT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_routes_version` (`feed_version_id`, `route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 8. Bảng GTFS: stops (trạm dừng)
-- =============================================
CREATE TABLE `gtfs_stops` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `stop_id` VARCHAR(100) NOT NULL,
    `stop_name` VARCHAR(255) NOT NULL,
    `stop_lat` DECIMAL(10,7) DEFAULT NULL,
    `stop_lon` DECIMAL(10,7) DEFAULT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_stops_version` (`feed_version_id`, `stop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 9. Bảng GTFS: calendar (lịch hoạt động)
-- =============================================
CREATE TABLE `gtfs_calendar` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `service_id` VARCHAR(100) NOT NULL,
    `monday` TINYINT(1) NOT NULL DEFAULT 0,
    `tuesday` TINYINT(1) NOT NULL DEFAULT 0,
    `wednesday` TINYINT(1) NOT NULL DEFAULT 0,
    `thursday` TINYINT(1) NOT NULL DEFAULT 0,
    `friday` TINYINT(1) NOT NULL DEFAULT 0,
    `saturday` TINYINT(1) NOT NULL DEFAULT 0,
    `sunday` TINYINT(1) NOT NULL DEFAULT 0,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_calendar_version` (`feed_version_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 10. Bảng GTFS: calendar_dates (ngày ngoại lệ)
-- =============================================
CREATE TABLE `gtfs_calendar_dates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `service_id` VARCHAR(100) NOT NULL,
    `date` DATE NOT NULL,
    `exception_type` TINYINT(1) NOT NULL COMMENT '1=thêm dịch vụ, 2=hủy dịch vụ',
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_caldates_version` (`feed_version_id`, `service_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 11. Bảng GTFS: trips (chuyến)
-- =============================================
CREATE TABLE `gtfs_trips` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `trip_id` VARCHAR(100) NOT NULL,
    `route_id` VARCHAR(100) NOT NULL,
    `service_id` VARCHAR(100) NOT NULL,
    `trip_headsign` VARCHAR(255) DEFAULT NULL,
    `direction_id` TINYINT(1) DEFAULT NULL COMMENT '0=chiều đi, 1=chiều về',
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_trips_version` (`feed_version_id`, `trip_id`),
    INDEX `idx_trips_route` (`feed_version_id`, `route_id`),
    INDEX `idx_trips_service` (`feed_version_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 12. Bảng GTFS: stop_times (lần dừng)
-- Giờ dùng VARCHAR(8) để hỗ trợ giờ vượt 24h (VD: 25:10:00)
-- =============================================
CREATE TABLE `gtfs_stop_times` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `trip_id` VARCHAR(100) NOT NULL,
    `stop_id` VARCHAR(100) NOT NULL,
    `arrival_time` VARCHAR(8) NOT NULL COMMENT 'HH:MM:SS, cho phép HH > 23 (VD: 25:10:00)',
    `departure_time` VARCHAR(8) NOT NULL COMMENT 'HH:MM:SS, cho phép HH > 23',
    `stop_sequence` INT UNSIGNED NOT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_st_version_trip` (`feed_version_id`, `trip_id`),
    INDEX `idx_st_version_stop` (`feed_version_id`, `stop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 13. Bảng truy vấn mẫu đã lưu
-- =============================================
CREATE TABLE `saved_queries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `query_name` VARCHAR(200) NOT NULL,
    `query_type` ENUM('next_trip','search','compare') NOT NULL,
    `parameters` JSON NOT NULL COMMENT 'Lưu tham số tra cứu dạng JSON',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
