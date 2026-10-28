<?php
// gtfs_browse.php - Tra cứu tuyến, trạm, chuyến và các lần dừng chuẩn GTFS
// Thiết kế giao diện hiện đại theo phong cách OpenUI Component System
// Tích hợp phân trang chuyên nghiệp cho danh sách chuyến xe (tối thiểu 10 chuyến/trang)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

// Xác định feed version ID (mặc định ưu tiên tham số GET hoặc active version)
$version_id = isset($_GET['version_id']) ? (int)$_GET['version_id'] : ($active_version['id'] ?? 0);
$current_version = get_feed_version($version_id);

$tab = $_GET['tab'] ?? 'routes'; // 'routes', 'stops', 'trips'
$keyword = trim($_GET['keyword'] ?? '');
$view_mode = $_GET['view'] ?? 'cards'; // 'cards' (Thẻ chuyến xe OpenUI) | 'table' (Bảng biểu)

// Cấu hình phân trang: Mặc định 10 chuyến/trang (hỗ trợ 10, 20, 50 chuyến)
$per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Dữ liệu tùy theo tab
$routes = [];
$stops = [];
$trips = [];
$selected_route = null;
$selected_trip = null;
$selected_stop = null;
$stop_times = [];
$routes_at_stop = [];

$total_records = 0;
$total_pages = 1;
$total_routes_count = 0;
$total_stops_count = 0;
$total_trips_count = 0;

if ($current_version) {
    // Đếm tổng số lượng danh mục chính của feed version này
    try {
        $total_routes_count = (int)$pdo->query("SELECT COUNT(*) FROM gtfs_routes WHERE feed_version_id = $version_id")->fetchColumn();
        $total_stops_count = (int)$pdo->query("SELECT COUNT(*) FROM gtfs_stops WHERE feed_version_id = $version_id")->fetchColumn();
        $total_trips_count = (int)$pdo->query("SELECT COUNT(*) FROM gtfs_trips WHERE feed_version_id = $version_id")->fetchColumn();
    } catch (Exception $e) {
        // Dự phòng
    }

    if ($tab === 'routes') {
        $route_id = $_GET['route_id'] ?? '';
        if ($route_id !== '') {
            $selected_route = get_route($route_id, $version_id);
            if ($selected_route) {
                // Đếm tổng số chuyến của tuyến này
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM gtfs_trips WHERE feed_version_id = ? AND route_id = ?");
                $stmt_count->execute([$version_id, $route_id]);
                $total_records = (int)$stmt_count->fetchColumn();
                $total_pages = max(1, (int)ceil($total_records / $per_page));
                if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

                // Lấy danh sách chuyến thuộc tuyến này kèm giờ xuất phát, giờ đến và số trạm dừng
                $stmt_trips = $pdo->prepare("SELECT t.*, r.route_short_name, r.route_long_name, r.route_type,
                                                    (SELECT MIN(departure_time) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as start_time,
                                                    (SELECT MAX(arrival_time) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as end_time,
                                                    (SELECT COUNT(*) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as stop_count
                                             FROM gtfs_trips t
                                             JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
                                             WHERE t.feed_version_id = ? AND t.route_id = ?
                                             ORDER BY t.trip_id ASC
                                             LIMIT $offset, $per_page");
                $stmt_trips->execute([$version_id, $route_id]);
                $trips = $stmt_trips->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Lấy danh sách tuyến kèm số chuyến của từng tuyến
            if ($keyword !== '') {
                $kw = '%' . $keyword . '%';
                $stmt_routes = $pdo->prepare("SELECT r.*, a.agency_name,
                                                     (SELECT COUNT(*) FROM gtfs_trips t WHERE t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id) as total_trips
                                              FROM gtfs_routes r
                                              LEFT JOIN gtfs_agency a ON r.feed_version_id = a.feed_version_id AND r.agency_id = a.agency_id
                                              WHERE r.feed_version_id = ? 
                                                AND (r.route_id LIKE ? OR r.route_short_name LIKE ? OR r.route_long_name LIKE ?)
                                              ORDER BY r.route_short_name ASC");
                $stmt_routes->execute([$version_id, $kw, $kw, $kw]);
            } else {
                $stmt_routes = $pdo->prepare("SELECT r.*, a.agency_name,
                                                     (SELECT COUNT(*) FROM gtfs_trips t WHERE t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id) as total_trips
                                              FROM gtfs_routes r
                                              LEFT JOIN gtfs_agency a ON r.feed_version_id = a.feed_version_id AND r.agency_id = a.agency_id
                                              WHERE r.feed_version_id = ?
                                              ORDER BY r.route_short_name ASC");
                $stmt_routes->execute([$version_id]);
            }
            $routes = $stmt_routes->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($tab === 'stops') {
        $stop_id = $_GET['stop_id'] ?? '';
        if ($stop_id !== '') {
            $selected_stop = get_stop($stop_id, $version_id);
            if ($selected_stop) {
                $routes_at_stop = get_routes_at_stop($stop_id, $version_id);
            }
        } else {
            // Phân trang danh sách trạm dừng
            $where_stop = ["feed_version_id = ?"];
            $params_stop = [$version_id];
            if ($keyword !== '') {
                $kw = '%' . $keyword . '%';
                $where_stop[] = "(stop_id LIKE ? OR stop_name LIKE ?)";
                $params_stop[] = $kw;
                $params_stop[] = $kw;
            }
            $where_stop_sql = implode(' AND ', $where_stop);

            $stmt_count_stops = $pdo->prepare("SELECT COUNT(*) FROM gtfs_stops WHERE $where_stop_sql");
            $stmt_count_stops->execute($params_stop);
            $total_records = (int)$stmt_count_stops->fetchColumn();
            $total_pages = max(1, (int)ceil($total_records / $per_page));
            if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

            $stmt_stops = $pdo->prepare("SELECT * FROM gtfs_stops WHERE $where_stop_sql ORDER BY stop_name ASC LIMIT $offset, $per_page");
            $stmt_stops->execute($params_stop);
            $stops = $stmt_stops->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($tab === 'trips') {
        $trip_id = $_GET['trip_id'] ?? '';
        if ($trip_id !== '') {
            $stmt = $pdo->prepare("SELECT t.*, r.route_short_name, r.route_long_name, r.route_type 
                                   FROM gtfs_trips t 
                                   JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id 
                                   WHERE t.feed_version_id = ? AND t.trip_id = ?");
            $stmt->execute([$version_id, $trip_id]);
            $selected_trip = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($selected_trip) {
                $stop_times = get_stop_times($trip_id, $version_id);
            }
        } else {
            $route_filter = $_GET['route_filter'] ?? '';
            $all_routes = get_all_routes($version_id);

            // Điều kiện lọc chuyến xe
            $where = ["t.feed_version_id = ?"];
            $params = [$version_id];

            if ($route_filter !== '') {
                $where[] = "t.route_id = ?";
                $params[] = $route_filter;
            }
            if ($keyword !== '') {
                $kw = '%' . $keyword . '%';
                $where[] = "(t.trip_id LIKE ? OR t.trip_headsign LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
            }

            $where_sql = implode(' AND ', $where);

            // Đếm tổng số chuyến xe
            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM gtfs_trips t WHERE $where_sql");
            $stmt_count->execute($params);
            $total_records = (int)$stmt_count->fetchColumn();
            $total_pages = max(1, (int)ceil($total_records / $per_page));
            if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

            // Lấy danh sách chuyến có phân trang (tối thiểu 10 chuyến/trang)
            $sql_trips = "SELECT t.*, r.route_short_name, r.route_long_name, r.route_type,
                                 (SELECT MIN(departure_time) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as start_time,
                                 (SELECT MAX(arrival_time) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as end_time,
                                 (SELECT COUNT(*) FROM gtfs_stop_times st WHERE st.feed_version_id = t.feed_version_id AND st.trip_id = t.trip_id) as stop_count
                          FROM gtfs_trips t
                          JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id
                          WHERE $where_sql
                          ORDER BY t.route_id ASC, t.trip_id ASC
                          LIMIT $offset, $per_page";
            $stmt_trips = $pdo->prepare($sql_trips);
            $stmt_trips->execute($params);
            $trips = $stmt_trips->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Helper tạo URL phân trang duy trì đầy đủ query params
if (!function_exists('pagination_url')) {
    function pagination_url($target_page, $override_params = []) {
        $params = array_merge($_GET, $override_params, ['page' => $target_page]);
        return 'gtfs_browse.php?' . http_build_query($params);
    }
}
?>
<!DOCTYPE html>
<html lang="vi" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tra Cứu Lịch Trình GTFS - OpenUI Design System</title>
    
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --openui-primary: #0284c7;
            --openui-primary-hover: #0369a1;
            --openui-primary-light: #e0f2fe;
            --openui-surface: #ffffff;
            --openui-bg: #f8fafc;
            --openui-border: #e2e8f0;
            --openui-text: #0f172a;
            --openui-muted: #64748b;
            --openui-radius: 1rem;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--openui-bg);
            color: var(--openui-text);
            -webkit-font-smoothing: antialiased;
        }

        /* Top Bar OpenUI Dark/Navy */
        .openui-navbar {
            background-color: #0f172a;
            border-bottom: 1px solid #1e293b;
        }

        /* Card Container OpenUI */
        .openui-card {
            background: var(--openui-surface);
            border: 1px solid var(--openui-border);
            border-radius: var(--openui-radius);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .openui-card-hover:hover {
            transform: translateY(-2px);
            border-color: #93c5fd;
            box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.12), 0 8px 10px -6px rgba(2, 132, 199, 0.08);
        }

        /* Tabs OpenUI */
        .openui-tab-btn {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--openui-muted);
            border: 1px solid transparent;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .openui-tab-btn:hover {
            color: var(--openui-text);
            background-color: #f1f5f9;
        }

        .openui-tab-btn.active {
            color: var(--openui-primary);
            background-color: var(--openui-primary-light);
            border-color: #bae6fd;
            box-shadow: 0 1px 2px rgba(2, 132, 199, 0.08);
        }

        /* Badges OpenUI */
        .openui-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            letter-spacing: 0.025em;
        }

        .openui-badge-primary {
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .openui-badge-emerald {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .openui-badge-amber {
            background-color: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* Chuyến xe timeline box */
        .trip-time-box {
            background-color: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 0.75rem;
            padding: 0.5rem 0.75rem;
        }

        /* OpenUI Pagination */
        .openui-pagination .page-link {
            font-size: 0.875rem;
            font-weight: 600;
            color: #334155;
            border: 1px solid #e2e8f0;
            margin: 0 2px;
            border-radius: 0.5rem;
            padding: 0.4rem 0.75rem;
            transition: all 0.15s ease;
        }

        .openui-pagination .page-link:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--openui-primary);
        }

        .openui-pagination .page-item.active .page-link {
            background-color: var(--openui-primary);
            border-color: var(--openui-primary);
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.25);
        }

        .openui-pagination .page-item.disabled .page-link {
            color: #94a3b8;
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        /* Timeline Lộ Trình Stop Times */
        .timeline-stop-item {
            position: relative;
            padding-left: 2.25rem;
            padding-bottom: 1.5rem;
        }
        .timeline-stop-item::before {
            content: '';
            position: absolute;
            left: 0.65rem;
            top: 0.85rem;
            bottom: 0;
            width: 2px;
            background: #cbd5e1;
        }
        .timeline-stop-item:last-child::before {
            display: none;
        }
        .timeline-stop-node {
            position: absolute;
            left: 0.2rem;
            top: 0.2rem;
            width: 1.1rem;
            height: 1.1rem;
            border-radius: 9999px;
            border: 2px solid #0284c7;
            background: #ffffff;
            z-index: 1;
        }
        .timeline-stop-item:first-child .timeline-stop-node {
            background: #10b981;
            border-color: #059669;
        }
        .timeline-stop-item:last-child .timeline-stop-node {
            background: #ef4444;
            border-color: #dc2626;
        }

        /* Monospace font */
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
    </style>
</head>
<body class="min-h-full flex flex-col">

    <!-- ========================================================================= -->
    <!-- 1. OPENUI TOPBAR NAVIGATION                                               -->
    <!-- ========================================================================= -->
    <header class="openui-navbar sticky-top py-2.5 z-40 shadow-sm">
        <div class="container-xl d-flex align-items-center justify-between">
            <div class="d-flex align-items-center gap-2.5">
                <a href="../index.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
                    <div class="rounded-xl p-1.5 d-flex align-items-center justify-center text-white" style="background: linear-gradient(135deg, #0284c7, #4f46e5); border-radius: 10px;">
                        <i class="bi bi-bus-front-fill fs-5 text-sky-300"></i>
                    </div>
                    <div>
                        <div class="fw-extrabold text-white lh-1 fs-6">Smart Transit GTFS</div>
                        <div class="text-slate-400 text-xs" style="font-size: 0.68rem; color: #94a3b8;">Hệ Thống Tra Cứu Lịch Trình Chuẩn Quốc Tế</div>
                    </div>
                </a>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="../index.php" class="btn btn-sm text-slate-300 hover:text-white border-0" style="color: #cbd5e1;">
                    <i class="bi bi-house-door me-1"></i> Trang Chủ
                </a>
                <a href="gtfs_browse.php?tab=routes&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-primary fw-bold px-3" style="border-radius: 8px;">
                    <i class="bi bi-compass me-1"></i> Tra Cứu GTFS
                </a>
                <a href="map.php?version_id=<?php echo $version_id; ?>" class="btn btn-sm text-slate-300 hover:text-white border-0" style="color: #cbd5e1;">
                    <i class="bi bi-map me-1"></i> Bản Đồ
                </a>
                <a href="map.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-map-fill me-1"></i> Bản Đồ
                </a>
                <a href="next_trip.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-clock-history me-1"></i> Chuyến Kế Tiếp
                </a>
                <a href="../smart_transit_navbar.php" class="btn btn-sm text-warning border-0 fw-semibold">
                    <i class="bi bi-tv me-1"></i> Stage Parallax
                </a>

                <?php if ($user): ?>
                    <div class="d-flex align-items-center gap-1 ps-2 ms-2 border-start border-secondary">
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-mono text-xs">
                            <i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($user['fullname']); ?>
                        </span>
                        <a href="../auth/logout.php" title="Đăng xuất" class="btn btn-sm btn-link text-danger p-1">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <a href="../auth/login.php" class="btn btn-sm btn-outline-light ms-2">Đăng nhập</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ========================================================================= -->
    <!-- 2. OPENUI MAIN CONTENT VIEWPORT                                           -->
    <!-- ========================================================================= -->
    <main class="flex-grow-1 py-4">
        <div class="container-xl">
            
            <!-- Breadcrumbs & Header Panel -->
            <div class="openui-card p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-between gap-3">
                    <div>
                        <!-- Breadcrumbs -->
                        <nav aria-label="breadcrumb" class="mb-1">
                            <ol class="breadcrumb mb-0 small" style="font-size: 0.8rem;">
                                <li class="breadcrumb-item"><a href="../index.php" class="text-decoration-none text-muted">Trang Chủ</a></li>
                                <li class="breadcrumb-item"><a href="gtfs_browse.php" class="text-decoration-none text-muted">Tra Cứu GTFS</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">
                                    <?php 
                                        if ($tab === 'routes') echo 'Tuyến (Routes)';
                                        elseif ($tab === 'stops') echo 'Trạm Dừng (Stops)';
                                        else echo 'Chuyến Xe (Trips)';
                                    ?>
                                </li>
                            </ol>
                        </nav>
                        <h2 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                            <span>Khám Phá Dữ Liệu Giao Thông GTFS</span>
                            <span class="openui-badge openui-badge-emerald fs-6">OpenUI Lang 1.0</span>
                        </h2>
                        <p class="text-muted mb-0 small">
                            Hệ thống hiển thị trực quan thông số các tuyến đường, trạm dừng, và phân trang danh sách chuyến xe đô thị.
                        </p>
                    </div>

                    <!-- Feed Version Selector & Status Badge -->
                    <div class="d-flex align-items-center gap-2">
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle fw-semibold rounded-3 px-3 py-2" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-layers-fill text-primary me-1"></i>
                                Phiên Bản: <strong><?php echo htmlspecialchars($current_version['version_name'] ?? 'Chưa chọn'); ?></strong> (ID: <?php echo $version_id; ?>)
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><h6 class="dropdown-header">Chọn Phiên Bản Dữ Liệu GTFS</h6></li>
                                <?php
                                $feed_list = $pdo->query("SELECT id, version_name, status FROM gtfs_feed_versions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($feed_list as $fv):
                                    $is_active = ($fv['id'] == $version_id);
                                ?>
                                    <li>
                                        <a class="dropdown-item d-flex justify-content-between align-items-center <?php echo $is_active ? 'active fw-bold' : ''; ?>" 
                                           href="gtfs_browse.php?tab=<?php echo urlencode($tab); ?>&version_id=<?php echo $fv['id']; ?>">
                                            <span><?php echo htmlspecialchars($fv['version_name']); ?> (#<?php echo $fv['id']; ?>)</span>
                                            <span class="badge <?php echo $fv['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?> ms-2"><?php echo $fv['status']; ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <!-- Nút xem OpenUI Lang Specification -->
                        <button type="button" class="btn btn-sm btn-dark rounded-3 px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#openuiSpecModal">
                            <i class="bi bi-code-slash text-warning"></i>
                            <span>OpenUI Lang Spec</span>
                        </button>
                    </div>
                </div>

                <!-- OpenUI Tab Group Navigation -->
                <div class="d-flex flex-wrap align-items-center gap-2 mt-4 pt-3 border-top border-slate-100">
                    <a href="gtfs_browse.php?tab=routes&version_id=<?php echo $version_id; ?>" 
                       class="openui-tab-btn <?php echo ($tab === 'routes') ? 'active' : ''; ?>">
                        <i class="bi bi-signpost-2-fill"></i>
                        <span>1. Tuyến Xe (Routes)</span>
                        <span class="badge rounded-pill <?php echo ($tab === 'routes') ? 'bg-primary text-white' : 'bg-slate-200 text-slate-700'; ?>">
                            <?php echo $total_routes_count; ?>
                        </span>
                    </a>

                    <a href="gtfs_browse.php?tab=stops&version_id=<?php echo $version_id; ?>" 
                       class="openui-tab-btn <?php echo ($tab === 'stops') ? 'active' : ''; ?>">
                        <i class="bi bi-geo-alt-fill"></i>
                        <span>2. Trạm Dừng (Stops)</span>
                        <span class="badge rounded-pill <?php echo ($tab === 'stops') ? 'bg-primary text-white' : 'bg-slate-200 text-slate-700'; ?>">
                            <?php echo $total_stops_count; ?>
                        </span>
                    </a>

                    <a href="gtfs_browse.php?tab=trips&version_id=<?php echo $version_id; ?>" 
                       class="openui-tab-btn <?php echo ($tab === 'trips') ? 'active' : ''; ?>">
                        <i class="bi bi-bus-front-fill"></i>
                        <span>3. Danh Sách Chuyến Xe (Trips)</span>
                        <span class="badge rounded-pill <?php echo ($tab === 'trips') ? 'bg-primary text-white' : 'bg-slate-200 text-slate-700'; ?>">
                            <?php echo $total_trips_count; ?>
                        </span>
                    </a>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- TAB 1: TUYẾN XE (ROUTES & VERSION_ID = 2)                         -->
            <!-- ================================================================= -->
            <?php if ($tab === 'routes'): ?>

                <?php if ($selected_route): ?>
                    <!-- Chi tiết 1 Tuyến xe & Danh sách chuyến xe có phân trang -->
                    <div class="mb-3 d-flex align-items-center justify-between">
                        <a href="gtfs_browse.php?tab=routes&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="bi bi-arrow-left me-1"></i> Quay lại danh sách tuyến
                        </a>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small">Chế độ xem:</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="<?php echo pagination_url($page, ['view' => 'cards']); ?>" class="btn <?php echo $view_mode === 'cards' ? 'btn-primary' : 'btn-outline-secondary'; ?>" title="Dạng Thẻ Chuyến Xe">
                                    <i class="bi bi-grid-fill"></i> Thẻ Card
                                </a>
                                <a href="<?php echo pagination_url($page, ['view' => 'table']); ?>" class="btn <?php echo $view_mode === 'table' ? 'btn-primary' : 'btn-outline-secondary'; ?>" title="Dạng Bảng">
                                    <i class="bi bi-table"></i> Bảng
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Header Tuyến OpenUI Hero Card -->
                    <div class="openui-card p-4 mb-4 border-start border-4 border-primary">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="openui-badge openui-badge-primary fs-6 px-3 py-1">
                                        <i class="bi <?php echo route_type_icon($selected_route['route_type']); ?> me-1"></i>
                                        Tuyến <?php echo htmlspecialchars($selected_route['route_short_name']); ?>
                                    </span>
                                    <span class="badge bg-light text-secondary border font-mono">ID: <?php echo htmlspecialchars($selected_route['route_id']); ?></span>
                                    <span class="openui-badge openui-badge-amber"><?php echo route_type_name($selected_route['route_type']); ?></span>
                                </div>
                                <h3 class="fw-extrabold text-dark mb-1"><?php echo htmlspecialchars($selected_route['route_long_name']); ?></h3>
                                <p class="text-muted mb-0 small">
                                    Đơn vị vận hành: <strong><?php echo htmlspecialchars($selected_route['agency_name'] ?? 'Giao Thông Đô Thị'); ?></strong>
                                    | Tổng số chuyến được ghi nhận: <strong><?php echo $total_records; ?> chuyến</strong>
                                </p>
                            </div>

                            <div class="text-end">
                                <div class="text-muted small mb-1">Quy định hiển thị</div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 rounded-pill">
                                    <i class="bi bi-ui-checks-grid me-1"></i> Phân trang: <?php echo $per_page; ?> chuyến / trang
                                </span>
                            </div>
                            <a href="map.php?route_id=<?php echo urlencode($selected_route['route_id']); ?>" class="btn btn-primary btn-sm fw-semibold">
                                <i class="bi bi-map-fill me-1"></i> Xem trên bản đồ
                            </a>
                        </div>
                    </div>

                    <!-- DANH SÁCH CHUYẾN XE PHÂN TRANG (TỐI THIỂU 10 CHUYẾN / TRANG) -->
                    <div class="openui-card p-4 mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                                    <i class="bi bi-collection-play text-primary"></i>
                                    <span>Danh Sách Chuyến Xe Thuộc Tuyến Này</span>
                                    <span class="badge bg-secondary-subtle text-secondary font-mono text-xs"><?php echo $total_records; ?> Chuyến</span>
                                </h5>
                                <div class="text-muted small mt-0.5">
                                    Đang xem chuyến <?php echo $total_records > 0 ? ($offset + 1) : 0; ?> - <?php echo min($offset + $per_page, $total_records); ?> (Trang <?php echo $page; ?> / <?php echo $total_pages; ?>)
                                </div>
                            </div>

                            <!-- Bộ điều chỉnh số chuyến/trang -->
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small">Hiển thị:</span>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?php echo pagination_url(1, ['per_page' => 10]); ?>" class="btn <?php echo $per_page == 10 ? 'btn-primary' : 'btn-outline-secondary'; ?>">10</a>
                                    <a href="<?php echo pagination_url(1, ['per_page' => 20]); ?>" class="btn <?php echo $per_page == 20 ? 'btn-primary' : 'btn-outline-secondary'; ?>">20</a>
                                    <a href="<?php echo pagination_url(1, ['per_page' => 50]); ?>" class="btn <?php echo $per_page == 50 ? 'btn-primary' : 'btn-outline-secondary'; ?>">50</a>
                                </div>
                            </div>
                        </div>

                        <?php if (empty($trips)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-slate-300"></i>
                                <p class="mb-0">Chưa có chuyến xe nào được ghi nhận cho tuyến này.</p>
                            </div>
                        <?php else: ?>

                            <?php if ($view_mode === 'cards'): ?>
                                <!-- CHẾ ĐỘ THẺ CHUYẾN XE HIỆN ĐẠI (OPENUI TRIP CARDS GRID) -->
                                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4">
                                    <?php foreach ($trips as $t): ?>
                                        <div class="col">
                                            <div class="openui-card openui-card-hover p-3.5 h-100 d-flex flex-col justify-content-between position-relative">
                                                <div>
                                                    <!-- Header Thẻ -->
                                                    <div class="d-flex justify-content-between align-items-center mb-2.5">
                                                        <span class="openui-badge openui-badge-primary">
                                                            <i class="bi bi-bus-front"></i>
                                                            Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?>
                                                        </span>
                                                        <span class="badge bg-slate-100 text-slate-600 border font-mono small">
                                                            #<?php echo htmlspecialchars($t['trip_id']); ?>
                                                        </span>
                                                    </div>

                                                    <!-- Hướng Tuyến Đi -->
                                                    <h6 class="fw-bold text-dark mb-2 text-truncate" title="<?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?>">
                                                        <i class="bi bi-sign-turn-right text-primary me-1"></i>
                                                        <?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình chuẩn'); ?>
                                                    </h6>

                                                    <!-- Khung Thời Gian Giờ Xuất Phát & Giờ Đến -->
                                                    <div class="trip-time-box mb-3">
                                                        <div class="d-flex justify-content-between align-items-center text-xs">
                                                            <div>
                                                                <div class="text-muted" style="font-size: 0.7rem;">Khởi Hành</div>
                                                                <strong class="font-mono text-emerald-600 fs-6" style="color: #059669;">
                                                                    <?php echo htmlspecialchars($t['start_time'] ?? '--:--'); ?>
                                                                </strong>
                                                            </div>
                                                            <div class="text-center px-2">
                                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.65rem;">
                                                                    <?php echo ($t['stop_count'] ?? 0); ?> điểm dừng
                                                                </span>
                                                                <div class="text-muted" style="font-size: 0.65rem;"><i class="bi bi-arrow-right"></i></div>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="text-muted" style="font-size: 0.7rem;">Điểm Cuối</div>
                                                                <strong class="font-mono text-primary fs-6">
                                                                    <?php echo htmlspecialchars($t['end_time'] ?? '--:--'); ?>
                                                                </strong>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Lịch Dịch Vụ Service ID -->
                                                    <div class="d-flex items-center gap-1.5 text-xs text-muted mb-3">
                                                        <i class="bi bi-calendar-check text-slate-400"></i>
                                                        <span>Lịch chạy:</span>
                                                        <span class="badge bg-light text-dark border font-mono"><?php echo htmlspecialchars($t['service_id']); ?></span>
                                                    </div>
                                                </div>

                                                <!-- Footer Thẻ Nút Xem Lần Dừng -->
                                                <div class="pt-2 border-top">
                                                    <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" 
                                                       class="btn btn-outline-primary btn-sm w-100 fw-bold rounded-xl d-flex align-items-center justify-content-center gap-1.5 py-1.5">
                                                        <i class="bi bi-clock-history"></i>
                                                        <span>Xem Lộ Trình Lần Dừng</span>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <!-- CHẾ ĐỘ BẢNG TRUYỀN THỐNG PHÂN TRANG -->
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Mã Chuyến (trip_id)</th>
                                                <th>Hướng Tuyến (Headsign)</th>
                                                <th>Lịch Hoạt Động</th>
                                                <th>Giờ Bắt Đầu - Kết Thúc</th>
                                                <th>Số Trạm Dừng</th>
                                                <th class="text-end">Thao Tác</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trips as $t): ?>
                                                <tr>
                                                    <td><code class="fw-bold"><?php echo htmlspecialchars($t['trip_id']); ?></code></td>
                                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></td>
                                                    <td><span class="badge bg-light text-dark border font-mono"><?php echo htmlspecialchars($t['service_id']); ?></span></td>
                                                    <td class="font-mono text-xs">
                                                        <span class="text-success fw-bold"><?php echo htmlspecialchars($t['start_time'] ?? '--:--'); ?></span>
                                                        <span class="text-muted mx-1">➔</span>
                                                        <span class="text-primary fw-bold"><?php echo htmlspecialchars($t['end_time'] ?? '--:--'); ?></span>
                                                    </td>
                                                    <td><span class="badge bg-secondary-subtle text-secondary"><?php echo (int)($t['stop_count'] ?? 0); ?> trạm</span></td>
                                                    <td class="text-end">
                                                        <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3">
                                                            <i class="bi bi-clock-history"></i> Chi tiết
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- THANH PHÂN TRANG OPENUI PAGINATION -->
                            <?php if ($total_pages > 1): ?>
                                <nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 border-top">
                                    <div class="text-muted small">
                                        Trang <strong><?php echo $page; ?></strong> / <?php echo $total_pages; ?> (<?php echo $total_records; ?> chuyến xe)
                                    </div>
                                    <ul class="pagination openui-pagination mb-0">
                                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url(1); ?>" title="Trang đầu">&laquo;</a>
                                        </li>
                                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($page - 1); ?>" title="Trang trước">&lsaquo;</a>
                                        </li>

                                        <?php
                                        $start_p = max(1, $page - 2);
                                        $end_p = min($total_pages, $page + 2);
                                        if ($start_p > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        for ($i = $start_p; $i <= $end_p; $i++):
                                        ?>
                                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo pagination_url($i); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <?php if ($end_p < $total_pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>

                                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($page + 1); ?>" title="Trang sau">&rsaquo;</a>
                                        </li>
                                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($total_pages); ?>" title="Trang cuối">&raquo;</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Tìm kiếm & Danh sách tuyến OpenUI Cards -->
                    <div class="openui-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="routes">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">
                            <div class="col-md-9">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 text-primary">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" name="keyword" class="form-control border-start-0 py-2.5 ps-0" 
                                           placeholder="Tìm theo tên tuyến hoặc mã tuyến (VD: 01, Tuyến Metro, Bến Thành...)" 
                                           value="<?php echo htmlspecialchars($keyword); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100 py-2.5 fw-bold rounded-xl shadow-sm">
                                    <i class="bi bi-funnel me-1"></i> Tìm Tuyến
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
                        <?php if (empty($routes)): ?>
                            <div class="col-12 w-100">
                                <div class="alert alert-warning text-center py-4 rounded-3">
                                    <i class="bi bi-exclamation-triangle fs-4 d-block mb-2"></i>
                                    Không tìm thấy tuyến xe nào phù hợp với từ khóa!
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($routes as $r): ?>
                                <div class="col">
                                    <div class="openui-card openui-card-hover p-4 h-100 d-flex flex-column justify-content-between">
                                        <div>
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <span class="openui-badge openui-badge-primary fs-6 px-3 py-1">
                                                    <i class="bi <?php echo route_type_icon($r['route_type']); ?> me-1"></i>
                                                    Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?>
                                                </span>
                                                <span class="badge bg-light text-secondary border font-mono">ID: <?php echo htmlspecialchars($r['route_id']); ?></span>
                                            </div>

                                            <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($r['route_long_name']); ?></h5>
                                            
                                            <div class="d-flex align-items-center gap-2 mb-3">
                                                <span class="openui-badge openui-badge-amber">
                                                    <?php echo route_type_name($r['route_type']); ?>
                                                </span>
                                                <span class="badge bg-slate-100 text-slate-600 border">
                                                    <?php echo (int)($r['total_trips'] ?? 0); ?> Chuyến hoạt động
                                                </span>
                                            </div>

                                            <?php if (!empty($r['agency_name'])): ?>
                                                <div class="text-muted small mb-3">
                                                    <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($r['agency_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="pt-3 border-top">
                                            <a href="gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>&version_id=<?php echo $version_id; ?>" 
                                               class="btn btn-outline-primary btn-sm w-100 fw-bold rounded-xl py-2 d-flex align-items-center justify-content-center gap-1.5">
                                                <i class="bi bi-eye"></i>
                                                <span>Xem Danh Sách Chuyến Xe</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <!-- ================================================================= -->
            <!-- TAB 2: TRẠM DỪNG (STOPS & VERSION_ID = 2)                         -->
            <!-- ================================================================= -->
            <?php elseif ($tab === 'stops'): ?>

                <?php if ($selected_stop): ?>
                    <!-- Chi tiết trạm dừng và các tuyến đi qua -->
                    <div class="mb-3">
                        <a href="gtfs_browse.php?tab=stops&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="bi bi-arrow-left me-1"></i> Quay lại danh sách trạm
                        </a>
                    </div>

                    <div class="openui-card p-4 mb-4 border-start border-4 border-danger">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <span class="badge bg-danger fs-6 mb-2 rounded-pill px-3 py-1.5">
                                    <i class="bi bi-geo-alt-fill me-1"></i> Mã Trạm: <?php echo htmlspecialchars($selected_stop['stop_id']); ?>
                                </span>
                                <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($selected_stop['stop_name']); ?></h3>
                                <?php if (!empty($selected_stop['stop_lat']) && !empty($selected_stop['stop_lon'])): ?>
                                    <div class="text-muted small font-mono mt-1">
                                        <i class="bi bi-pin-map text-danger"></i> Tọa độ GPS: 
                                        <strong><?php echo $selected_stop['stop_lat']; ?>, <?php echo $selected_stop['stop_lon']; ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                                <a href="next_trip.php?stop_id=<?php echo urlencode($selected_stop['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-primary rounded-xl px-3 py-2 fw-bold shadow-sm">
                                    <i class="bi bi-clock-history me-1"></i> Tìm Chuyến Kế Tiếp
                                </a>
                                <?php if (!empty($selected_stop['stop_lat']) && !empty($selected_stop['stop_lon'])): ?>
                                    <a href="https://www.google.com/maps?q=<?php echo $selected_stop['stop_lat']; ?>,<?php echo $selected_stop['stop_lon']; ?>" target="_blank" class="btn btn-outline-secondary rounded-xl px-3 py-2 fw-semibold">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Xem Bản Đồ
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="openui-card p-4">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-signpost-split text-primary me-1"></i> Các Tuyến Đi Qua Trạm Này (<?php echo count($routes_at_stop); ?> tuyến)
                        </h5>
                        <?php if (empty($routes_at_stop)): ?>
                            <p class="text-muted">Không có tuyến nào đi qua trạm này trong bộ dữ liệu.</p>
                        <?php else: ?>
                            <div class="row row-cols-1 row-cols-md-2 g-3">
                                <?php foreach ($routes_at_stop as $r): ?>
                                    <div class="col">
                                        <div class="openui-card p-3 d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="openui-badge openui-badge-primary me-2">Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?></span>
                                                <strong class="text-dark"><?php echo htmlspecialchars($r['route_long_name']); ?></strong>
                                            </div>
                                            <a href="gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                                Xem Tuyến
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Tìm kiếm trạm dừng OpenUI -->
                    <div class="openui-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="stops">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">
                            <div class="col-md-9">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 text-danger">
                                        <i class="bi bi-geo-alt"></i>
                                    </span>
                                    <input type="text" name="keyword" class="form-control border-start-0 py-2.5 ps-0" 
                                           placeholder="Nhập tên trạm hoặc mã trạm (VD: Bến Thành, Chợ Lớn, STOP_01...)" 
                                           value="<?php echo htmlspecialchars($keyword); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100 py-2.5 fw-bold rounded-xl shadow-sm">
                                    <i class="bi bi-search me-1"></i> Tìm Trạm Dừng
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Bảng danh sách trạm dừng OpenUI có phân trang -->
                    <div class="openui-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div>
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-pin-map text-danger"></i>
                                    <span>Danh Sách Trạm Dừng</span>
                                    <span class="badge bg-secondary-subtle text-secondary font-mono text-xs"><?php echo $total_records; ?> Trạm</span>
                                </h6>
                                <div class="text-muted small mt-0.5">
                                    Đang xem trạm <?php echo $total_records > 0 ? ($offset + 1) : 0; ?> - <?php echo min($offset + $per_page, $total_records); ?> (Trang <?php echo $page; ?> / <?php echo $total_pages; ?>)
                                </div>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small">Hiển thị:</span>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?php echo pagination_url(1, ['per_page' => 10]); ?>" class="btn <?php echo $per_page == 10 ? 'btn-primary' : 'btn-outline-secondary'; ?>">10</a>
                                    <a href="<?php echo pagination_url(1, ['per_page' => 20]); ?>" class="btn <?php echo $per_page == 20 ? 'btn-primary' : 'btn-outline-secondary'; ?>">20</a>
                                    <a href="<?php echo pagination_url(1, ['per_page' => 50]); ?>" class="btn <?php echo $per_page == 50 ? 'btn-primary' : 'btn-outline-secondary'; ?>">50</a>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Mã Trạm (stop_id)</th>
                                        <th>Tên Trạm Dừng</th>
                                        <th>Tọa Độ (Lat, Lon)</th>
                                        <th class="text-end">Hành Động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stops as $s): ?>
                                        <tr>
                                            <td><code class="fw-bold"><?php echo htmlspecialchars($s['stop_id']); ?></code></td>
                                            <td class="fw-semibold text-dark">
                                                <a href="gtfs_browse.php?tab=stops&stop_id=<?php echo urlencode($s['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($s['stop_name']); ?>
                                                </a>
                                            </td>
                                            <td class="text-muted small font-mono">
                                                <?php echo ($s['stop_lat'] && $s['stop_lon']) ? $s['stop_lat'] . ', ' . $s['stop_lon'] : 'N/A'; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="gtfs_browse.php?tab=stops&stop_id=<?php echo urlencode($s['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 me-1">
                                                    <i class="bi bi-info-circle"></i> Chi tiết
                                                </a>
                                                <a href="next_trip.php?stop_id=<?php echo urlencode($s['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                                    <i class="bi bi-clock-history"></i> Tìm Chuyến
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Phân trang trạm dừng -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 border-top">
                                <div class="text-muted small">
                                    Trang <strong><?php echo $page; ?></strong> / <?php echo $total_pages; ?> (<?php echo $total_records; ?> trạm)
                                </div>
                                <ul class="pagination openui-pagination mb-0">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo pagination_url(1); ?>">&laquo;</a>
                                    </li>
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo pagination_url($page - 1); ?>">&lsaquo;</a>
                                    </li>
                                    <?php
                                    $start_p = max(1, $page - 2);
                                    $end_p = min($total_pages, $page + 2);
                                    for ($i = $start_p; $i <= $end_p; $i++):
                                    ?>
                                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($i); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo pagination_url($page + 1); ?>">&rsaquo;</a>
                                    </li>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo pagination_url($total_pages); ?>">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

            <!-- ================================================================= -->
            <!-- TAB 3: DANH SÁCH CHUYẾN XE (TRIPS & VERSION_ID = 2)               -->
            <!-- ================================================================= -->
            <?php elseif ($tab === 'trips'): ?>

                <?php if ($selected_trip): ?>
                    <!-- Xem chi tiết 1 chuyến xe và timeline các lần dừng -->
                    <div class="mb-3 d-flex align-items-center justify-between">
                        <a href="gtfs_browse.php?tab=trips&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="bi bi-arrow-left me-1"></i> Quay lại danh sách chuyến
                        </a>
                    </div>

                    <div class="openui-card p-4 mb-4 border-start border-4 border-success">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="openui-badge openui-badge-primary fs-6 px-3 py-1">
                                        Tuyến <?php echo htmlspecialchars($selected_trip['route_short_name']); ?>
                                    </span>
                                    <span class="badge bg-secondary font-mono">Chuyến #<?php echo htmlspecialchars($selected_trip['trip_id']); ?></span>
                                    <span class="openui-badge openui-badge-emerald">Lộ trình hoạt động</span>
                                </div>

                                <h3 class="fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($selected_trip['route_long_name']); ?>
                                </h3>

                                <div class="text-muted small">
                                    Hướng: <strong class="text-dark"><?php echo htmlspecialchars($selected_trip['trip_headsign'] ?: 'Theo lộ trình chuẩn'); ?></strong> | 
                                    Lịch dịch vụ (service_id): <code class="fw-bold"><?php echo htmlspecialchars($selected_trip['service_id']); ?></code>
                                </div>
                            </div>

                            <div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill font-mono">
                                    <i class="bi bi-clock-fill me-1"></i> Tổng số lần dừng: <?php echo count($stop_times); ?> trạm
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- TIMELINE CÁC LẦN DỪNG THEO PHONG CÁCH OPENUI TRANSIT TIMELINE -->
                    <div class="openui-card p-4">
                        <h5 class="fw-bold text-dark mb-4 d-flex align-items-center gap-2">
                            <i class="bi bi-geo-alt text-primary"></i>
                            <span>Lộ Trình & Giờ Dừng Chi Tiết Từng Trạm (stop_times)</span>
                        </h5>

                        <div class="ps-2">
                            <?php foreach ($stop_times as $idx => $st): 
                                $dep_secs = gtfs_time_to_seconds($st['departure_time']);
                                $is_over_24 = ($dep_secs >= 86400);
                            ?>
                                <div class="timeline-stop-item">
                                    <div class="timeline-stop-node"></div>
                                    <div class="d-flex flex-wrap justify-content-between align-items-center p-3 rounded-3 bg-light border border-slate-100">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-secondary font-mono">#<?php echo $st['stop_sequence']; ?></span>
                                                <a href="gtfs_browse.php?tab=stops&stop_id=<?php echo urlencode($st['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="fw-bold text-dark text-decoration-none fs-6">
                                                    <?php echo htmlspecialchars($st['stop_name']); ?>
                                                </a>
                                                <code class="text-muted small"><?php echo htmlspecialchars($st['stop_id']); ?></code>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center gap-3 font-mono text-sm">
                                            <div>
                                                <span class="text-muted small">Đến:</span>
                                                <strong class="text-secondary"><?php echo htmlspecialchars($st['arrival_time']); ?></strong>
                                            </div>
                                            <div>
                                                <span class="text-muted small">Rời:</span>
                                                <strong class="text-primary"><?php echo htmlspecialchars($st['departure_time']); ?></strong>
                                            </div>

                                            <?php if ($is_over_24): ?>
                                                <span class="badge bg-purple text-white px-2 py-1" style="background-color: #7c3aed;">
                                                    <i class="bi bi-moon-stars"></i> +24h (<?php echo gtfs_time_to_clock($st['departure_time']); ?> sáng sau)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- BỘ LỌC TÌM KIẾM CHUYẾN XE OPENUI -->
                    <div class="openui-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-end">
                            <input type="hidden" name="tab" value="trips">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">

                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-dark mb-1">
                                    <i class="bi bi-filter-circle text-primary"></i> Lọc Theo Tuyến Xe:
                                </label>
                                <select name="route_filter" class="form-select py-2 rounded-xl">
                                    <option value="">-- Tất cả các tuyến --</option>
                                    <?php if (!empty($all_routes)): ?>
                                        <?php foreach ($all_routes as $r): ?>
                                            <option value="<?php echo htmlspecialchars($r['route_id']); ?>" <?php echo (($_GET['route_filter'] ?? '') === $r['route_id']) ? 'selected' : ''; ?>>
                                                Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?> - <?php echo htmlspecialchars($r['route_long_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-dark mb-1">
                                    <i class="bi bi-search text-primary"></i> Mã Chuyến hoặc Hướng Đi:
                                </label>
                                <input type="text" name="keyword" class="form-control py-2 rounded-xl" 
                                       placeholder="Nhập mã chuyến (trip_id) hoặc hướng xe chạy..." 
                                       value="<?php echo htmlspecialchars($keyword); ?>">
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-xl shadow-sm">
                                    <i class="bi bi-funnel"></i> Lọc Chuyến
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- DANH SÁCH CHUYẾN XE PHÂN TRANG (TỐI THIỂU 10 CHUYẾN/TRANG) -->
                    <div class="openui-card p-4 mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                                    <i class="bi bi-bus-front text-primary"></i>
                                    <span>Danh Sách Chuyến Xe GTFS</span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono text-xs">
                                        Tổng: <?php echo $total_records; ?> Chuyến
                                    </span>
                                </h5>
                                <div class="text-muted small mt-0.5">
                                    Hiển thị chuyến <?php echo $total_records > 0 ? ($offset + 1) : 0; ?> - <?php echo min($offset + $per_page, $total_records); ?> (Trang <?php echo $page; ?> / <?php echo $total_pages; ?>)
                                </div>
                            </div>

                            <!-- Công cụ chọn Chế độ hiển thị & số chuyến/trang -->
                            <div class="d-flex align-items-center gap-3">
                                <!-- Chọn Chế độ xem Thẻ Card hoặc Table -->
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="<?php echo pagination_url($page, ['view' => 'cards']); ?>" 
                                       class="btn <?php echo $view_mode === 'cards' ? 'btn-primary' : 'btn-outline-secondary'; ?>" 
                                       title="Chế độ Thẻ Chuyến Xe Hiện Đại">
                                        <i class="bi bi-grid-fill me-1"></i> Thẻ Card
                                    </a>
                                    <a href="<?php echo pagination_url($page, ['view' => 'table']); ?>" 
                                       class="btn <?php echo $view_mode === 'table' ? 'btn-primary' : 'btn-outline-secondary'; ?>" 
                                       title="Chế độ Bảng Truyền Thống">
                                        <i class="bi bi-table me-1"></i> Bảng
                                    </a>
                                </div>

                                <!-- Chọn số chuyến 1 trang: Tối thiểu 10 -->
                                <div class="d-flex align-items-center gap-1.5">
                                    <span class="text-muted small">Mỗi trang:</span>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?php echo pagination_url(1, ['per_page' => 10]); ?>" class="btn <?php echo $per_page == 10 ? 'btn-primary' : 'btn-outline-secondary'; ?>">10</a>
                                        <a href="<?php echo pagination_url(1, ['per_page' => 20]); ?>" class="btn <?php echo $per_page == 20 ? 'btn-primary' : 'btn-outline-secondary'; ?>">20</a>
                                        <a href="<?php echo pagination_url(1, ['per_page' => 50]); ?>" class="btn <?php echo $per_page == 50 ? 'btn-primary' : 'btn-outline-secondary'; ?>">50</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (empty($trips)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-emoji-neutral fs-1 d-block mb-2 text-slate-300"></i>
                                <h6 class="fw-bold">Không tìm thấy chuyến xe nào phù hợp bộ lọc!</h6>
                                <p class="small text-muted">Hãy thử xóa bộ lọc hoặc chọn tuyến xe khác.</p>
                                <a href="gtfs_browse.php?tab=trips&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-secondary rounded-pill">
                                    Xóa Bộ Lọc
                                </a>
                            </div>
                        <?php else: ?>

                            <?php if ($view_mode === 'cards'): ?>
                                <!-- CHẾ ĐỘ THẺ CHUYẾN XE (OPENUI TRIP CARDS) - TỐI THIỂU 10 CHUYẾN / TRANG -->
                                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4">
                                    <?php foreach ($trips as $t): ?>
                                        <div class="col">
                                            <div class="openui-card openui-card-hover p-3.5 h-100 d-flex flex-column justify-content-between position-relative">
                                                <div>
                                                    <!-- Header Thẻ -->
                                                    <div class="d-flex justify-content-between align-items-center mb-2.5">
                                                        <span class="openui-badge openui-badge-primary">
                                                            <i class="bi bi-bus-front"></i>
                                                            Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?>
                                                        </span>
                                                        <span class="badge bg-slate-100 text-slate-600 border font-mono small">
                                                            #<?php echo htmlspecialchars($t['trip_id']); ?>
                                                        </span>
                                                    </div>

                                                    <!-- Tên Tuyến và Hướng Tuyến Đi -->
                                                    <div class="text-muted text-xs mb-1 text-truncate" title="<?php echo htmlspecialchars($t['route_long_name']); ?>">
                                                        <?php echo htmlspecialchars($t['route_long_name']); ?>
                                                    </div>

                                                    <h6 class="fw-bold text-dark mb-2 text-truncate" title="<?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?>">
                                                        <i class="bi bi-sign-turn-right text-primary me-1"></i>
                                                        <?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình chuẩn'); ?>
                                                    </h6>

                                                    <!-- Khung Thời Gian Giờ Xuất Phát & Giờ Đến -->
                                                    <div class="trip-time-box mb-3">
                                                        <div class="d-flex justify-content-between align-items-center text-xs">
                                                            <div>
                                                                <div class="text-muted" style="font-size: 0.7rem;">Khởi Hành</div>
                                                                <strong class="font-mono text-emerald-600 fs-6" style="color: #059669;">
                                                                    <?php echo htmlspecialchars($t['start_time'] ?? '--:--'); ?>
                                                                </strong>
                                                            </div>
                                                            <div class="text-center px-2">
                                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.65rem;">
                                                                    <?php echo ($t['stop_count'] ?? 0); ?> điểm dừng
                                                                </span>
                                                                <div class="text-muted" style="font-size: 0.65rem;"><i class="bi bi-arrow-right"></i></div>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="text-muted" style="font-size: 0.7rem;">Điểm Cuối</div>
                                                                <strong class="font-mono text-primary fs-6">
                                                                    <?php echo htmlspecialchars($t['end_time'] ?? '--:--'); ?>
                                                                </strong>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Lịch Dịch Vụ Service ID -->
                                                    <div class="d-flex align-items-center justify-content-between text-xs text-muted mb-3">
                                                        <span class="d-flex align-items-center gap-1">
                                                            <i class="bi bi-calendar-check text-slate-400"></i>
                                                            <span>Lịch chạy:</span>
                                                            <span class="badge bg-light text-dark border font-mono"><?php echo htmlspecialchars($t['service_id']); ?></span>
                                                        </span>
                                                        <span class="badge bg-slate-100 text-slate-500 border" style="font-size: 0.65rem;">
                                                            <?php echo ($t['direction_id'] == 1) ? 'Chiều Về' : 'Chiều Đi'; ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Nút Xem Lần Dừng Chi Tiết -->
                                                <div class="pt-2 border-top">
                                                    <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" 
                                                       class="btn btn-outline-primary btn-sm w-100 fw-bold rounded-xl d-flex align-items-center justify-content-center gap-1.5 py-1.5">
                                                        <i class="bi bi-clock-history"></i>
                                                        <span>Xem Lộ Trình Lần Dừng</span>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <!-- CHẾ ĐỘ BẢNG TRUYỀN THỐNG PHÂN TRANG -->
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Mã Chuyến (trip_id)</th>
                                                <th>Tuyến Xe</th>
                                                <th>Hướng Tuyến (Headsign)</th>
                                                <th>Lịch Chạy</th>
                                                <th>Giờ Bắt Đầu ➔ Kết Thúc</th>
                                                <th>Số Trạm</th>
                                                <th class="text-end">Hành Động</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trips as $t): ?>
                                                <tr>
                                                    <td><code class="fw-bold"><?php echo htmlspecialchars($t['trip_id']); ?></code></td>
                                                    <td>
                                                        <span class="openui-badge openui-badge-primary me-1">Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?></span>
                                                        <span class="text-muted small"><?php echo htmlspecialchars($t['route_long_name']); ?></span>
                                                    </td>
                                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></td>
                                                    <td><span class="badge bg-light text-dark border font-mono"><?php echo htmlspecialchars($t['service_id']); ?></span></td>
                                                    <td class="font-mono text-xs">
                                                        <strong class="text-success"><?php echo htmlspecialchars($t['start_time'] ?? '--:--'); ?></strong>
                                                        <span class="text-muted mx-1">➔</span>
                                                        <strong class="text-primary"><?php echo htmlspecialchars($t['end_time'] ?? '--:--'); ?></strong>
                                                    </td>
                                                    <td><span class="badge bg-secondary-subtle text-secondary"><?php echo (int)($t['stop_count'] ?? 0); ?> trạm</span></td>
                                                    <td class="text-end">
                                                        <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3">
                                                            <i class="bi bi-clock-history"></i> Xem Lần Dừng
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- THANH ĐIỀU HƯỚNG PHÂN TRANG (TỐI THIỂU 10 CHUYẾN / TRANG) -->
                            <?php if ($total_pages > 1): ?>
                                <nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 border-top">
                                    <div class="text-muted small">
                                        Hiển thị <strong><?php echo $offset + 1; ?></strong> - <strong><?php echo min($offset + $per_page, $total_records); ?></strong> trong tổng số <strong><?php echo $total_records; ?></strong> chuyến xe (Trang <?php echo $page; ?> / <?php echo $total_pages; ?>)
                                    </div>
                                    <ul class="pagination openui-pagination mb-0">
                                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url(1); ?>" title="Trang đầu">&laquo; Đầu</a>
                                        </li>
                                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($page - 1); ?>" title="Trang trước">&lsaquo; Trước</a>
                                        </li>

                                        <?php
                                        $start_p = max(1, $page - 2);
                                        $end_p = min($total_pages, $page + 2);
                                        if ($start_p > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        for ($i = $start_p; $i <= $end_p; $i++):
                                        ?>
                                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo pagination_url($i); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <?php if ($end_p < $total_pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>

                                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($page + 1); ?>" title="Trang sau">Sau &rsaquo;</a>
                                        </li>
                                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo pagination_url($total_pages); ?>" title="Trang cuối">Cuối &raquo;</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>
    </main>

    <!-- ========================================================================= -->
    <!-- 3. MODAL XEM MÃ NGUỒN ĐẶC TẢ OPENUI LANG (OPENUI LANG SPECIFICATION)      -->
    <!-- ========================================================================= -->
    <div class="modal fade" id="openuiSpecModal" tabindex="-1" aria-labelledby="openuiSpecModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white border-0 py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-code-square text-warning fs-5"></i>
                        <h5 class="modal-title fw-bold" id="openuiSpecModalLabel">Mã Đặc Tả OpenUI Lang 1.0 (Trang GTFS Browse)</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-slate-900 text-slate-100 p-4" style="background-color: #0b1120;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono text-xs">
                            Spec: OpenUI Lang 1.0 / Line-Oriented AST
                        </span>
                        <span class="text-slate-400 small">Target: tab=<?php echo htmlspecialchars($tab); ?>&amp;version_id=<?php echo $version_id; ?></span>
                    </div>
                    
                    <pre class="font-mono text-xs p-3 rounded-3 text-sky-300 overflow-auto" style="background: #020617; border: 1px solid #1e293b; max-height: 420px; line-height: 1.6;">
# OpenUI Lang 1.0 Specification for GTFS Browse View
# Version ID: <?php echo $version_id; ?> | Tab: <?php echo htmlspecialchars($tab); ?>

root = Container([
    headerSection,
    tabGroup,
    filterBar,
    <?php if ($tab === 'trips'): ?>
    tripCardsGrid,
    paginationControls
    <?php elseif ($tab === 'routes'): ?>
    routeCardsGrid
    <?php else: ?>
    stopListTable,
    paginationControls
    <?php endif; ?>
])

headerSection = Header(
    "Khám Phá Dữ Liệu Giao Thông GTFS",
    "Phiên bản: <?php echo htmlspecialchars($current_version['version_name'] ?? 'N/A'); ?> (ID: <?php echo $version_id; ?>)",
    Badge("OpenUI Lang 1.0", "emerald")
)

tabGroup = Tabs("active_tab", [
    TabItem("routes", "Tuyến Xe", <?php echo $total_routes_count; ?>),
    TabItem("stops", "Trạm Dừng", <?php echo $total_stops_count; ?>),
    TabItem("trips", "Danh Sách Chuyến Xe", <?php echo $total_trips_count; ?>)
], "<?php echo htmlspecialchars($tab); ?>")

<?php if ($tab === 'trips'): ?>
filterBar = FilterBar([
    Select("route_filter", $routes_list, $selected_route, "Tất cả các tuyến"),
    Input("keyword", $search_query, "Mã chuyến hoặc hướng đi..."),
    Button("Lọc Chuyến", @Run(applyFilters))
])

# Phân trang chuyến xe: Tối thiểu 10 chuyến 1 trang
$per_page = <?php echo $per_page; ?>

$page = <?php echo $page; ?>

$total_pages = <?php echo $total_pages; ?>

$total_trips = <?php echo $total_records; ?>


tripCardsGrid = Grid(@Each($trips_slice, "trip", TripCard(
    trip.route_short_name,
    trip.id,
    trip.headsign,
    trip.start_time,
    trip.end_time,
    trip.stop_count,
    trip.service_id,
    Button("Xem Lộ Trình", @OpenUrl("gtfs_browse.php?tab=trips&trip_id=" + trip.id))
)), { columns: { sm: 1, md: 2, xl: 3 }, gap: "1rem" })

paginationControls = Pagination(
    $page,
    $total_pages,
    $per_page,
    "Hiển thị <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_records); ?> / <?php echo $total_records; ?> chuyến",
    Action([@Set($page, $target_page), @Run(fetchTrips)])
)
<?php else: ?>
# OpenUI Components for Routes and Stops view
routeCardsGrid = Grid(@Each($routes, "route", RouteCard(route.short_name, route.long_name, route.type)))
<?php endif; ?>
                    </pre>
                </div>
                <div class="modal-footer bg-slate-900 border-top border-slate-800 text-slate-400 py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
