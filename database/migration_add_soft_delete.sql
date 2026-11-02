-- Migration: Thêm cột deleted_at cho tính năng xóa mềm phiên bản GTFS
-- Chạy script này nếu database đã được tạo trước đó (không cần chạy nếu dùng database.sql mới)

ALTER TABLE `gtfs_feed_versions`
    ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Thời điểm xóa mềm, NULL = chưa xóa'
    AFTER `notes`;
