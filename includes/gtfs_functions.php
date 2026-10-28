<?php
// includes/gtfs_functions.php - Hàm cốt lõi xử lý GTFS: tra cứu, validate, so sánh, xử lý giờ >24h
require_once __DIR__ . '/../config/db.php';

// =============================================
// 1. QUẢN LÝ PHIÊN BẢN
// =============================================

/**
 * Lấy phiên bản GTFS đang active
 * @return array|null
 */
function get_active_feed_version(): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_feed_versions WHERE status = 'active' LIMIT 1");
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

/**
 * Lấy danh sách tất cả phiên bản GTFS
 * @return array
 */
function get_all_feed_versions(): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT fv.*, u.fullname AS uploader_name 
                           FROM gtfs_feed_versions fv 
                           JOIN users u ON fv.uploaded_by = u.id 
                           ORDER BY fv.uploaded_at DESC");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Lấy thông tin 1 phiên bản
 */
function get_feed_version(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_feed_versions WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Kích hoạt 1 phiên bản (deactivate bản cũ)
 * Chỉ bản có status='valid' mới được kích hoạt
 */
function activate_feed_version(int $version_id): bool {
    global $pdo;
    $ver = get_feed_version($version_id);
    if (!$ver || $ver['status'] !== 'valid') {
        return false;
    }
    $pdo->beginTransaction();
    try {
        // Đổi bản active hiện tại thành valid
        $pdo->exec("UPDATE gtfs_feed_versions SET status = 'valid' WHERE status = 'active'");
        // Đặt bản mới làm active
        $stmt = $pdo->prepare("UPDATE gtfs_feed_versions SET status = 'active' WHERE id = ?");
        $stmt->execute([$version_id]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Lấy lỗi validate của 1 phiên bản
 */
function get_validation_errors(int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_validation_errors WHERE feed_version_id = ? ORDER BY id ASC");
    $stmt->execute([$version_id]);
    return $stmt->fetchAll();
}

/**
 * Đếm số lỗi validate
 */
function count_validation_errors(int $version_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM gtfs_validation_errors WHERE feed_version_id = ?");
    $stmt->execute([$version_id]);
    return (int)$stmt->fetchColumn();
}

// =============================================
// 2. TRA CỨU GTFS
// =============================================

/**
 * Tìm tuyến (routes) theo tên hoặc mã
 */
function search_routes(string $keyword, int $version_id): array {
    global $pdo;
    $kw = '%' . $keyword . '%';
    $stmt = $pdo->prepare("SELECT r.*, a.agency_name 
                           FROM gtfs_routes r
                           LEFT JOIN gtfs_agency a ON r.feed_version_id = a.feed_version_id AND r.agency_id = a.agency_id
                           WHERE r.feed_version_id = ? 
                             AND (r.route_id LIKE ? OR r.route_short_name LIKE ? OR r.route_long_name LIKE ?)
                           ORDER BY r.route_short_name ASC");
    $stmt->execute([$version_id, $kw, $kw, $kw]);
    return $stmt->fetchAll();
}

/**
 * Lấy tất cả tuyến
 */
function get_all_routes(int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT r.*, a.agency_name 
                           FROM gtfs_routes r
                           LEFT JOIN gtfs_agency a ON r.feed_version_id = a.feed_version_id AND r.agency_id = a.agency_id
                           WHERE r.feed_version_id = ?
                           ORDER BY r.route_short_name ASC");
    $stmt->execute([$version_id]);
    return $stmt->fetchAll();
}

/**
 * Lấy 1 tuyến theo route_id
 */
function get_route(string $route_id, int $version_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT r.*, a.agency_name 
                           FROM gtfs_routes r
                           LEFT JOIN gtfs_agency a ON r.feed_version_id = a.feed_version_id AND r.agency_id = a.agency_id
                           WHERE r.feed_version_id = ? AND r.route_id = ?");
    $stmt->execute([$version_id, $route_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Tìm trạm (stops) theo tên hoặc mã
 */
function search_stops(string $keyword, int $version_id): array {
    global $pdo;
    $kw = '%' . $keyword . '%';
    $stmt = $pdo->prepare("SELECT * FROM gtfs_stops 
                           WHERE feed_version_id = ? 
                             AND (stop_id LIKE ? OR stop_name LIKE ?)
                           ORDER BY stop_name ASC");
    $stmt->execute([$version_id, $kw, $kw]);
    return $stmt->fetchAll();
}

/**
 * Lấy tất cả trạm
 */
function get_all_stops(int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_stops WHERE feed_version_id = ? ORDER BY stop_name ASC");
    $stmt->execute([$version_id]);
    return $stmt->fetchAll();
}

/**
 * Lấy 1 trạm theo stop_id
 */
function get_stop(string $stop_id, int $version_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_stops WHERE feed_version_id = ? AND stop_id = ?");
    $stmt->execute([$version_id, $stop_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Tìm chuyến (trips) theo bộ lọc
 */
function search_trips(array $filters, int $version_id): array {
    global $pdo;
    $where = ["t.feed_version_id = ?"];
    $params = [$version_id];

    if (!empty($filters['route_id'])) {
        $where[] = "t.route_id = ?";
        $params[] = $filters['route_id'];
    }
    if (!empty($filters['service_id'])) {
        $where[] = "t.service_id = ?";
        $params[] = $filters['service_id'];
    }
    if (!empty($filters['keyword'])) {
        $kw = '%' . $filters['keyword'] . '%';
        $where[] = "(t.trip_id LIKE ? OR t.trip_headsign LIKE ?)";
        $params[] = $kw;
        $params[] = $kw;
    }

    $sql = "SELECT t.*, r.route_short_name, r.route_long_name
            FROM gtfs_trips t
            JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.route_id, t.trip_id ASC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Lấy danh sách lần dừng (stop_times) của 1 chuyến
 */
function get_stop_times(string $trip_id, int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT st.*, s.stop_name
                           FROM gtfs_stop_times st
                           JOIN gtfs_stops s ON st.feed_version_id = s.feed_version_id AND st.stop_id = s.stop_id
                           WHERE st.feed_version_id = ? AND st.trip_id = ?
                           ORDER BY st.stop_sequence ASC");
    $stmt->execute([$version_id, $trip_id]);
    return $stmt->fetchAll();
}

/**
 * Lấy các tuyến đi qua 1 trạm
 */
function get_routes_at_stop(string $stop_id, int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT DISTINCT r.route_id, r.route_short_name, r.route_long_name, r.route_type
                           FROM gtfs_stop_times st
                           JOIN gtfs_trips t ON st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id
                           JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
                           WHERE st.feed_version_id = ? AND st.stop_id = ?
                           ORDER BY r.route_short_name ASC");
    $stmt->execute([$version_id, $stop_id]);
    return $stmt->fetchAll();
}

/**
 * Lấy thông tin calendar theo service_id
 */
function get_calendar(string $service_id, int $version_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_calendar WHERE feed_version_id = ? AND service_id = ?");
    $stmt->execute([$version_id, $service_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Lấy calendar_dates theo service_id
 */
function get_calendar_dates(string $service_id, int $version_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gtfs_calendar_dates WHERE feed_version_id = ? AND service_id = ? ORDER BY date ASC");
    $stmt->execute([$version_id, $service_id]);
    return $stmt->fetchAll();
}

// =============================================
// 3. XỬ LÝ GIỜ GTFS (YÊU CẦU KỸ THUẬT 1)
// =============================================

/**
 * Chuyển giờ GTFS (HH:MM:SS, có thể >24h) thành tổng số giây từ đầu ngày hoạt động
 * VD: "25:10:00" -> 90600 (giây)
 *     "08:30:00" -> 30600 (giây)
 */
function gtfs_time_to_seconds(string $time): int {
    $parts = explode(':', $time);
    if (count($parts) !== 3) return 0;
    return ((int)$parts[0]) * 3600 + ((int)$parts[1]) * 60 + ((int)$parts[2]);
}

/**
 * Chuyển tổng số giây thành giờ HH:MM:SS (cho phép >24h)
 */
function seconds_to_gtfs_time(int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/**
 * Chuyển giờ GTFS thành giờ đồng hồ thực (mod 24)
 * VD: "25:10:00" -> "01:10:00"
 */
function gtfs_time_to_clock(string $time): string {
    $secs = gtfs_time_to_seconds($time);
    $secs = $secs % 86400; // mod 24h
    return seconds_to_gtfs_time($secs);
}

/**
 * Kiểm tra 1 service_id có hoạt động vào 1 ngày cụ thể không
 * Xét cả calendar (ngày thường) + calendar_dates (ngoại lệ)
 */
function is_service_active(string $service_id, string $date_str, int $version_id): bool {
    global $pdo;
    
    $date = new DateTime($date_str);
    $day_of_week = strtolower($date->format('l')); // monday, tuesday, ...
    
    // 1. Kiểm tra calendar (lịch thường xuyên)
    $calendar = get_calendar($service_id, $version_id);
    $active = false;
    
    if ($calendar) {
        $cal_start = new DateTime($calendar['start_date']);
        $cal_end = new DateTime($calendar['end_date']);
        
        // Ngày phải trong khoảng start_date..end_date
        if ($date >= $cal_start && $date <= $cal_end) {
            // Kiểm tra ngày trong tuần
            $active = (bool)$calendar[$day_of_week];
        }
    }
    
    // 2. Kiểm tra calendar_dates (ngoại lệ) - ghi đè kết quả trên
    $stmt = $pdo->prepare("SELECT exception_type FROM gtfs_calendar_dates 
                           WHERE feed_version_id = ? AND service_id = ? AND date = ?");
    $stmt->execute([$version_id, $service_id, $date_str]);
    $exception = $stmt->fetch();
    
    if ($exception) {
        if ((int)$exception['exception_type'] === 1) {
            $active = true;  // Thêm dịch vụ vào ngày này
        } elseif ((int)$exception['exception_type'] === 2) {
            $active = false; // Hủy dịch vụ vào ngày này
        }
    }
    
    return $active;
}

/**
 * TÌM CHUYẾN TIẾP THEO (YÊU CẦU KỸ THUẬT CHÍNH)
 * 
 * Xử lý đầy đủ:
 * - Ngày hoạt động (calendar)
 * - Ngày ngoại lệ (calendar_dates: exception_type 1=thêm, 2=hủy)
 * - Giờ vượt 24h (VD: 25:10:00 = chuyến thuộc ngày hôm trước nhưng chạy 01:10 sáng hôm sau)
 */
function find_next_trips(string $stop_id, string $date_str, string $time_str, int $version_id, int $limit = 10): array {
    global $pdo;
    
    if (strlen($time_str) === 5) {
        $time_str .= ':00';
    }
    $query_seconds = gtfs_time_to_seconds($time_str);
    
    $results = [];
    
    // Lấy tất cả stop_times tại trạm này
    $stmt = $pdo->prepare("
        SELECT st.*, t.trip_id AS t_trip_id, t.route_id, t.service_id, t.trip_headsign, t.direction_id,
               r.route_short_name, r.route_long_name, r.route_type,
               s.stop_name
        FROM gtfs_stop_times st
        JOIN gtfs_trips t ON st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id
        JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
        JOIN gtfs_stops s ON st.feed_version_id = s.feed_version_id AND st.stop_id = s.stop_id
        WHERE st.feed_version_id = ? AND st.stop_id = ?
        ORDER BY st.departure_time ASC
    ");
    $stmt->execute([$version_id, $stop_id]);
    $all_stop_times = $stmt->fetchAll();
    
    foreach ($all_stop_times as $st) {
        $dep_seconds = gtfs_time_to_seconds($st['departure_time']);
        
        if ($dep_seconds < 86400) {
            if ($dep_seconds >= $query_seconds) {
                if (is_service_active($st['service_id'], $date_str, $version_id)) {
                    $st['actual_date'] = $date_str;
                    $st['clock_time'] = gtfs_time_to_clock($st['departure_time']);
                    $st['is_overnight'] = false;
                    $results[] = $st;
                }
            }
        } else {
            $clock_seconds = $dep_seconds - 86400;
            $yesterday = (new DateTime($date_str))->modify('-1 day')->format('Y-m-d');
            if ($clock_seconds >= $query_seconds || $query_seconds >= 86400) {
                if (is_service_active($st['service_id'], $yesterday, $version_id)) {
                    $st['actual_date'] = $date_str;
                    $st['service_date'] = $yesterday;
                    $st['clock_time'] = gtfs_time_to_clock($st['departure_time']);
                    $st['is_overnight'] = true;
                    $st['_sort_seconds'] = $clock_seconds;
                    $results[] = $st;
                }
            }
        }
    }
    
    foreach ($all_stop_times as $st) {
        $dep_seconds = gtfs_time_to_seconds($st['departure_time']);
        if ($dep_seconds >= 86400) {
            if ($dep_seconds >= $query_seconds) {
                if (is_service_active($st['service_id'], $date_str, $version_id)) {
                    $already_added = false;
                    foreach ($results as $r) {
                        if ($r['trip_id'] === $st['trip_id'] && ($r['service_date'] ?? '') === $date_str) {
                            $already_added = true;
                            break;
                        }
                    }
                    if (!$already_added) {
                        $st['actual_date'] = (new DateTime($date_str))->modify('+1 day')->format('Y-m-d');
                        $st['service_date'] = $date_str;
                        $st['clock_time'] = gtfs_time_to_clock($st['departure_time']);
                        $st['is_overnight'] = true;
                        $st['_sort_seconds'] = $dep_seconds;
                        $results[] = $st;
                    }
                }
            }
        }
    }
    
    usort($results, function($a, $b) {
        $sa = isset($a['_sort_seconds']) ? $a['_sort_seconds'] : gtfs_time_to_seconds($a['departure_time']);
        $sb = isset($b['_sort_seconds']) ? $b['_sort_seconds'] : gtfs_time_to_seconds($b['departure_time']);
        return $sa - $sb;
    });
    
    $unique = [];
    $seen_trips = [];
    foreach ($results as $r) {
        if (!in_array($r['trip_id'], $seen_trips)) {
            $seen_trips[] = $r['trip_id'];
            $unique[] = $r;
        }
    }
    
    return array_slice($unique, 0, $limit);
}

// =============================================
// 4. SO SÁNH 2 PHIÊN BẢN
// =============================================

function compare_versions(int $ver1_id, int $ver2_id): array {
    global $pdo;
    
    $routes1 = get_all_routes($ver1_id);
    $routes2 = get_all_routes($ver2_id);
    
    $r1_ids = array_column($routes1, 'route_id');
    $r2_ids = array_column($routes2, 'route_id');
    
    $routes_added = array_diff($r2_ids, $r1_ids);
    $routes_removed = array_diff($r1_ids, $r2_ids);
    $routes_common = array_intersect($r1_ids, $r2_ids);
    
    $routes_modified = [];
    $r1_map = [];
    foreach ($routes1 as $r) $r1_map[$r['route_id']] = $r;
    $r2_map = [];
    foreach ($routes2 as $r) $r2_map[$r['route_id']] = $r;
    
    foreach ($routes_common as $rid) {
        $changes = [];
        if ($r1_map[$rid]['route_short_name'] !== $r2_map[$rid]['route_short_name']) {
            $changes[] = "Tên ngắn: '{$r1_map[$rid]['route_short_name']}' → '{$r2_map[$rid]['route_short_name']}'";
        }
        if ($r1_map[$rid]['route_long_name'] !== $r2_map[$rid]['route_long_name']) {
            $changes[] = "Tên dài: '{$r1_map[$rid]['route_long_name']}' → '{$r2_map[$rid]['route_long_name']}'";
        }
        if (!empty($changes)) {
            $routes_modified[$rid] = $changes;
        }
    }
    
    $stmt1 = $pdo->prepare("SELECT t.trip_id, t.route_id, t.service_id, t.trip_headsign, r.route_short_name
                            FROM gtfs_trips t
                            JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
                            WHERE t.feed_version_id = ?
                            ORDER BY t.route_id, t.trip_id");
    $stmt1->execute([$ver1_id]);
    $trips1 = $stmt1->fetchAll();
    
    $stmt2 = $pdo->prepare("SELECT t.trip_id, t.route_id, t.service_id, t.trip_headsign, r.route_short_name
                            FROM gtfs_trips t
                            JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
                            WHERE t.feed_version_id = ?
                            ORDER BY t.route_id, t.trip_id");
    $stmt2->execute([$ver2_id]);
    $trips2 = $stmt2->fetchAll();
    
    $t1_ids = array_column($trips1, 'trip_id');
    $t2_ids = array_column($trips2, 'trip_id');
    
    $trips_added = array_diff($t2_ids, $t1_ids);
    $trips_removed = array_diff($t1_ids, $t2_ids);
    
    $trips_common = array_intersect($t1_ids, $t2_ids);
    $trips_modified = [];
    foreach ($trips_common as $tid) {
        $st1 = get_stop_times($tid, $ver1_id);
        $st2 = get_stop_times($tid, $ver2_id);
        if (count($st1) !== count($st2)) {
            $trips_modified[$tid] = "Số trạm dừng thay đổi: " . count($st1) . " → " . count($st2);
        } else {
            for ($i = 0; $i < count($st1); $i++) {
                if ($st1[$i]['departure_time'] !== $st2[$i]['departure_time'] ||
                    $st1[$i]['stop_id'] !== $st2[$i]['stop_id']) {
                    $trips_modified[$tid] = "Giờ hoặc trạm dừng thay đổi";
                    break;
                }
            }
        }
    }
    
    $t1_map = [];
    foreach ($trips1 as $t) $t1_map[$t['trip_id']] = $t;
    $t2_map = [];
    foreach ($trips2 as $t) $t2_map[$t['trip_id']] = $t;
    
    return [
        'routes' => [
            'added' => array_map(fn($id) => $r2_map[$id], array_values($routes_added)),
            'removed' => array_map(fn($id) => $r1_map[$id], array_values($routes_removed)),
            'modified' => $routes_modified,
            'total_v1' => count($routes1),
            'total_v2' => count($routes2),
        ],
        'trips' => [
            'added' => array_map(fn($id) => $t2_map[$id] ?? ['trip_id' => $id], array_values($trips_added)),
            'removed' => array_map(fn($id) => $t1_map[$id] ?? ['trip_id' => $id], array_values($trips_removed)),
            'modified' => $trips_modified,
            'total_v1' => count($trips1),
            'total_v2' => count($trips2),
        ],
    ];
}

// =============================================
// 5. THỐNG KÊ NHANH
// =============================================

function get_feed_stats(int $version_id): array {
    global $pdo;
    
    $stats = [];
    $tables = [
        'agency' => 'gtfs_agency',
        'routes' => 'gtfs_routes',
        'stops' => 'gtfs_stops',
        'trips' => 'gtfs_trips',
        'stop_times' => 'gtfs_stop_times',
        'calendar' => 'gtfs_calendar',
        'calendar_dates' => 'gtfs_calendar_dates',
    ];
    // gtfs_shapes chỉ có sau khi chạy migration_add_shapes.sql
    try {
        if ($pdo->query("SHOW TABLES LIKE 'gtfs_shapes'")->fetch()) {
            $tables['shapes'] = 'gtfs_shapes';
        }
    } catch (Throwable $e) {
    }
    
    foreach ($tables as $key => $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE feed_version_id = ?");
        $stmt->execute([$version_id]);
        $stats[$key] = (int)$stmt->fetchColumn();
    }
    
    return $stats;
}

// =============================================
// 6. TRUY VẤN MẪU
// =============================================

function save_query(int $user_id, string $name, string $type, array $params): bool {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO saved_queries (user_id, query_name, query_type, parameters) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$user_id, $name, $type, json_encode($params, JSON_UNESCAPED_UNICODE)]);
}

function get_saved_queries(int $user_id): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM saved_queries WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function delete_saved_query(int $query_id, int $user_id): bool {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM saved_queries WHERE id = ? AND user_id = ?");
    return $stmt->execute([$query_id, $user_id]);
}

function get_saved_query(int $query_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM saved_queries WHERE id = ?");
    $stmt->execute([$query_id]);
    $row = $stmt->fetch();
    if ($row) {
        $row['parameters'] = json_decode($row['parameters'], true);
    }
    return $row ?: null;
}

// =============================================
// 7. HELPER: Tên loại tuyến GTFS
// =============================================
function route_type_name(int $type): string {
    $types = [
        0 => 'Tram / Xe điện nhẹ',
        1 => 'Metro / Tàu điện ngầm',
        2 => 'Tàu hỏa',
        3 => 'Xe buýt',
        4 => 'Phà',
        5 => 'Cáp treo',
        6 => 'Gondola',
        7 => 'Leo dốc',
    ];
    return $types[$type] ?? 'Khác';
}

function route_type_icon(int $type): string {
    $icons = [
        0 => 'bi-train-front',
        1 => 'bi-train-front-fill',
        2 => 'bi-train-lightrail-front',
        3 => 'bi-bus-front-fill',
        4 => 'bi-water',
        5 => 'bi-cable-car-front',
        6 => 'bi-balloon',
        7 => 'bi-arrow-up-circle',
    ];
    return $icons[$type] ?? 'bi-signpost';
}

// =============================================
// 8. BẢN ĐỒ TUYẾN: dữ liệu lộ trình để vẽ lên bản đồ
// =============================================

/**
 * Lấy dữ liệu bản đồ của tất cả tuyến trong một phiên bản GTFS.
 * Với mỗi tuyến và mỗi hướng (direction_id), chọn chuyến có nhiều trạm dừng nhất
 * làm lộ trình đại diện, rồi nối các trạm theo stop_sequence thành đường đi.
 * Nếu chuyến có shape_id và đã nạp shapes.txt thì trả thêm 'path' (đường đi bám đường thật);
 * ngược lại 'path' = null và bản đồ nối thẳng các trạm.
 *
 * @return array ['routes' => [...], 'total_routes' => int, 'without_geometry' => int]
 */
function get_route_map_data(int $version_id): array {
    global $pdo;

    $routes = get_all_routes($version_id);

    // CSDL đã nâng cấp hỗ trợ shapes chưa? (cột gtfs_trips.shape_id và bảng gtfs_shapes)
    $has_shapes = false;
    try {
        $has_col = $pdo->query("SHOW COLUMNS FROM gtfs_trips LIKE 'shape_id'")->fetch();
        $has_tbl = $pdo->query("SHOW TABLES LIKE 'gtfs_shapes'")->fetch();
        $has_shapes = (bool)$has_col && (bool)$has_tbl;
    } catch (Throwable $e) {
        $has_shapes = false;
    }
    $shape_col = $has_shapes ? 't.shape_id' : 'NULL';

    // 1. Mỗi chuyến có bao nhiêu lần dừng -> chọn chuyến đại diện cho từng (tuyến, hướng)
    $stmt = $pdo->prepare("SELECT t.route_id, t.trip_id, t.direction_id, t.trip_headsign, {$shape_col} AS shape_id, COUNT(st.id) AS cnt
                           FROM gtfs_trips t
                           JOIN gtfs_stop_times st
                             ON st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id
                           WHERE t.feed_version_id = ?
                           GROUP BY t.route_id, t.trip_id, t.direction_id, t.trip_headsign" . ($has_shapes ? ", t.shape_id" : ""));
    $stmt->execute([$version_id]);

    $best = []; // [route_id][direction_key] => row
    foreach ($stmt->fetchAll() as $row) {
        $dir_key = ($row['direction_id'] === null) ? 'x' : (string)$row['direction_id'];
        $cur = $best[$row['route_id']][$dir_key] ?? null;
        if ($cur === null || (int)$row['cnt'] > (int)$cur['cnt']) {
            $best[$row['route_id']][$dir_key] = $row;
        }
    }

    // 2. Lấy toạ độ các trạm của những chuyến đã chọn (chia nhỏ để tránh IN quá dài)
    $trip_ids = [];
    foreach ($best as $dirs) {
        foreach ($dirs as $row) {
            $trip_ids[] = $row['trip_id'];
        }
    }

    $stops_by_trip = [];
    foreach (array_chunk($trip_ids, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT st.trip_id, st.stop_sequence, s.stop_id, s.stop_name, s.stop_lat, s.stop_lon
                FROM gtfs_stop_times st
                JOIN gtfs_stops s
                  ON s.feed_version_id = st.feed_version_id AND s.stop_id = st.stop_id
                WHERE st.feed_version_id = ? AND st.trip_id IN ($ph)
                ORDER BY st.trip_id, st.stop_sequence";
        $q = $pdo->prepare($sql);
        $q->execute(array_merge([$version_id], $chunk));
        foreach ($q->fetchAll() as $r) {
            if ($r['stop_lat'] === null || $r['stop_lon'] === null) {
                continue;
            }
            $lat = (float)$r['stop_lat'];
            $lon = (float)$r['stop_lon'];
            if ($lat == 0.0 && $lon == 0.0) {
                continue;
            }
            $stops_by_trip[$r['trip_id']][] = [
                'id'   => $r['stop_id'],
                'name' => $r['stop_name'],
                'lat'  => $lat,
                'lon'  => $lon,
            ];
        }
    }

    // 2b. Lấy đường đi thật (shapes) của các chuyến đã chọn, nếu có
    $shape_ids = [];
    foreach ($best as $dirs) {
        foreach ($dirs as $row) {
            if (!empty($row['shape_id'])) {
                $shape_ids[$row['shape_id']] = true;
            }
        }
    }
    $paths = [];
    if ($has_shapes && !empty($shape_ids)) {
        foreach (array_chunk(array_keys($shape_ids), 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT shape_id, shape_pt_lat, shape_pt_lon
                                FROM gtfs_shapes
                                WHERE feed_version_id = ? AND shape_id IN ($ph)
                                ORDER BY shape_id, shape_pt_sequence");
            $q->execute(array_merge([$version_id], $chunk));
            foreach ($q->fetchAll() as $p) {
                $paths[$p['shape_id']][] = [round((float)$p['shape_pt_lat'], 6), round((float)$p['shape_pt_lon'], 6)];
            }
        }
    }

    // 3. Ghép lại theo từng tuyến
    $out = [];
    foreach ($routes as $route) {
        $lines = [];
        foreach (($best[$route['route_id']] ?? []) as $dir_key => $row) {
            $stops = $stops_by_trip[$row['trip_id']] ?? [];
            if (count($stops) < 2) {
                continue;
            }
            $path = (!empty($row['shape_id']) && count($paths[$row['shape_id']] ?? []) >= 2)
                ? $paths[$row['shape_id']]
                : null;
            $lines[] = [
                'direction' => $dir_key === 'x' ? null : (int)$dir_key,
                'headsign'  => $row['trip_headsign'],
                'trip_id'   => $row['trip_id'],
                'stops'     => $stops,
                'path'      => $path, // null nếu không có shapes -> bản đồ nối thẳng các trạm
            ];
        }
        if (empty($lines)) {
            continue;
        }

        $color = (string)($route['route_color'] ?? '');
        $out[] = [
            'route_id'  => $route['route_id'],
            'short'     => $route['route_short_name'],
            'long'      => $route['route_long_name'],
            'type'      => (int)$route['route_type'],
            'type_name' => route_type_name((int)$route['route_type']),
            'color'     => preg_match('/^[0-9A-Fa-f]{6}$/', $color) ? '#' . strtoupper($color) : null,
            'agency'    => $route['agency_name'] ?? null,
            'lines'     => $lines,
        ];
    }

    return [
        'routes'           => $out,
        'total_routes'     => count($routes),
        'without_geometry' => count($routes) - count($out),
    ];
}
