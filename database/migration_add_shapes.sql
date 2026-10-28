-- migration_add_shapes.sql
-- Dùng cho CSDL transit_db ĐÃ TẠO TRƯỚC ĐÓ (không muốn import lại database.sql và mất dữ liệu).
-- Thêm hỗ trợ shapes.txt để bản đồ vẽ tuyến bám theo đường thật.
-- Chạy trong phpMyAdmin (tab SQL) sau khi chọn database transit_db.
-- Nếu báo "#1060 Duplicate column name shape_id" nghĩa là cột đã có, bỏ qua và chạy tiếp lệnh CREATE TABLE bên dưới.
-- Lưu ý: các phiên bản GTFS đã nạp trước đó KHÔNG có dữ liệu shapes, cần nạp lại để có.

USE `transit_db`;

ALTER TABLE `gtfs_trips`
    ADD COLUMN `shape_id` VARCHAR(100) DEFAULT NULL
    COMMENT 'Mã hình dạng lộ trình (shapes.txt), dùng để vẽ đường trên bản đồ'
    AFTER `direction_id`;

CREATE TABLE IF NOT EXISTS `gtfs_shapes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feed_version_id` INT UNSIGNED NOT NULL,
    `shape_id` VARCHAR(100) NOT NULL,
    `shape_pt_lat` DECIMAL(10,7) NOT NULL,
    `shape_pt_lon` DECIMAL(10,7) NOT NULL,
    `shape_pt_sequence` INT UNSIGNED NOT NULL,
    FOREIGN KEY (`feed_version_id`) REFERENCES `gtfs_feed_versions`(`id`) ON DELETE CASCADE,
    INDEX `idx_shapes_version` (`feed_version_id`, `shape_id`, `shape_pt_sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
