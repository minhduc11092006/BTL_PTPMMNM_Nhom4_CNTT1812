<?php
// export.php - Xuất lịch trình GTFS hoặc báo cáo lỗi kiểm tra ra định dạng CSV
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gtfs_functions.php';

$type = $_GET['type'] ?? 'schedule'; // 'schedule', 'errors', 'comparison'
$version_id = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

// Xuất file CSV có kèm BOM UTF-8 để mở bằng Excel tiếng Việt không bị lỗi font
function init_csv_download(string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Ghi UTF-8 BOM
    echo "\xEF\xBB\xBF";
}

// 1. XUẤT BÁO CÁO LỖI KIỂM TRA (VALIDATION ERRORS)
if ($type === 'errors' && $version_id > 0) {
    require_admin(); // Chỉ admin mới được xuất lỗi kiểm tra
    $ver = get_feed_version($version_id);
    $errors = get_validation_errors($version_id);

    $filename = 'bao_cao_loi_gtfs_v' . $version_id . '_' . date('Ymd_His') . '.csv';
    init_csv_download($filename);

    $output = fopen('php://output', 'w');
    fputcsv($output, ['STT', 'Mã Phiên Bản', 'Tên Phiên Bản', 'Loại Lỗi', 'Tệp Tin Gây Lỗi', 'Cột Dữ Liệu', 'Dòng', 'Nội Dung Chi Tiết Lỗi', 'Thời Gian Ghi Nhận']);

    $idx = 1;
    foreach ($errors as $err) {
        fputcsv($output, [
            $idx++,
            $version_id,
            $ver['version_name'] ?? '',
            $err['error_type'],
            $err['file_name'] ?? '',
            $err['column_name'] ?? '',
            $err['row_number'] ?? '',
            $err['message'],
            $err['created_at']
        ]);
    }

    fclose($output);
    exit;
}

// 2. XUẤT LỊCH TRÌNH GTFS (SCHEDULE)
elseif ($type === 'schedule') {
    if ($version_id <= 0) {
        $active_ver = get_active_feed_version();
        $version_id = $active_ver['id'] ?? 0;
    }

    if ($version_id <= 0) {
        die('Không tìm thấy phiên bản lịch trình hợp lệ để xuất.');
    }

    $ver = get_feed_version($version_id);
    $filename = 'lich_trinh_gtfs_v' . $version_id . '_' . date('Ymd_His') . '.csv';
    init_csv_download($filename);

    $output = fopen('php://output', 'w');
    // Header cột
    fputcsv($output, ['Mã Tuyến', 'Tên Ngắn Tuyến', 'Tên Lộ Trình Tuyến', 'Loại Phương Tiện', 'Mã Chuyến', 'Hướng Tuyến', 'Mã Lịch Dịch Vụ', 'Thứ Tự Dừng', 'Mã Trạm', 'Tên Trạm Dừng', 'Giờ Đến', 'Giờ Khởi Hành']);

    // Truy vấn kết hợp lấy toàn bộ lịch trình
    $stmt = $pdo->prepare("
        SELECT r.route_id, r.route_short_name, r.route_long_name, r.route_type,
               t.trip_id, t.trip_headsign, t.service_id,
               st.stop_sequence, st.stop_id, s.stop_name, st.arrival_time, st.departure_time
        FROM gtfs_stop_times st
        JOIN gtfs_trips t ON st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id
        JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
        JOIN gtfs_stops s ON st.feed_version_id = s.feed_version_id AND st.stop_id = s.stop_id
        WHERE st.feed_version_id = ?
        ORDER BY r.route_id, t.trip_id, st.stop_sequence ASC
    ");
    $stmt->execute([$version_id]);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['route_id'],
            $row['route_short_name'],
            $row['route_long_name'],
            route_type_name((int)$row['route_type']),
            $row['trip_id'],
            $row['trip_headsign'],
            $row['service_id'],
            $row['stop_sequence'],
            $row['stop_id'],
            $row['stop_name'],
            $row['arrival_time'],
            $row['departure_time']
        ]);
    }

    fclose($output);
    exit;
}

// 3. XUẤT KẾT QUẢ SO SÁNH HAI BẢN LỊCH (COMPARISON)
elseif ($type === 'comparison') {
    require_admin();
    $v1 = (int)($_GET['v1'] ?? 0);
    $v2 = (int)($_GET['v2'] ?? 0);

    if ($v1 <= 0 || $v2 <= 0 || $v1 === $v2) {
        die('Vui lòng chỉ định 2 phiên bản khác nhau để xuất báo cáo so sánh.');
    }

    $comp = compare_versions($v1, $v2);
    $v1_info = get_feed_version($v1);
    $v2_info = get_feed_version($v2);

    $filename = 'so_sanh_gtfs_v' . $v1 . '_vs_v' . $v2 . '_' . date('Ymd_His') . '.csv';
    init_csv_download($filename);

    $output = fopen('php://output', 'w');

    // Section 1: Thông tin tổng quan
    fputcsv($output, ['BÁO CÁO SO SÁNH HAI PHIÊN BẢN LỊCH TRÌNH GTFS']);
    fputcsv($output, ['Phiên bản cũ (V1)', "#{$v1} - " . ($v1_info['version_name'] ?? '')]);
    fputcsv($output, ['Phiên bản mới (V2)', "#{$v2} - " . ($v2_info['version_name'] ?? '')]);
    fputcsv($output, ['Thời gian xuất báo cáo', date('d/m/Y H:i:s')]);
    fputcsv($output, []);

    // Section 2: Tuyến thay đổi
    fputcsv($output, ['--- THAY ĐỔI VỀ TUYẾN (ROUTES) ---']);
    fputcsv($output, ['Loại Thay Đổi', 'Mã Tuyến (route_id)', 'Tên Tuyến', 'Chi Tiết Thay Đổi']);

    foreach ($comp['routes']['added'] as $r) {
        fputcsv($output, ['Thêm mới (+)', $r['route_id'], $r['route_short_name'] . ' - ' . $r['route_long_name'], 'Tuyến mới được đưa vào hoạt động']);
    }
    foreach ($comp['routes']['removed'] as $r) {
        fputcsv($output, ['Xóa bỏ (-)', $r['route_id'], $r['route_short_name'] . ' - ' . $r['route_long_name'], 'Tuyến bị ngừng hoạt động']);
    }
    foreach ($comp['routes']['modified'] as $rid => $changes) {
        fputcsv($output, ['Chỉnh sửa (*)', $rid, '', implode('; ', $changes)]);
    }

    fputcsv($output, []);

    // Section 3: Chuyến thay đổi
    fputcsv($output, ['--- THAY ĐỔI VỀ CHUYẾN (TRIPS) ---']);
    fputcsv($output, ['Loại Thay Đổi', 'Mã Chuyến (trip_id)', 'Mã Tuyến', 'Chi Tiết Thay Đổi']);

    foreach ($comp['trips']['added'] as $t) {
        fputcsv($output, ['Thêm mới (+)', $t['trip_id'], $t['route_id'] ?? '', 'Chuyến mới được bổ sung']);
    }
    foreach ($comp['trips']['removed'] as $t) {
        fputcsv($output, ['Xóa bỏ (-)', $t['trip_id'], $t['route_id'] ?? '', 'Chuyến bị cắt giảm']);
    }
    foreach ($comp['trips']['modified'] as $tid => $change) {
        fputcsv($output, ['Chỉnh sửa (*)', $tid, '', $change]);
    }

    fclose($output);
    exit;
}

// Nếu type không đúng
header('Location: index.php');
exit;
