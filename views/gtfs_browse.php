<?php
// gtfs_browse.php - Tra cứu tuyến, trạm, chuyến và các lần dừng; tìm theo tên hoặc mã, kết hợp bộ lọc
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

// Cho phép chỉ định version_id (nếu không có thì dùng active version)
$version_id = isset($_GET['version_id']) ? (int)$_GET['version_id'] : ($active_version['id'] ?? 0);
$current_version = get_feed_version($version_id);

$tab = $_GET['tab'] ?? 'routes'; // 'routes', 'stops', 'trips'
$keyword = trim($_GET['keyword'] ?? '');

// Dữ liệu tùy theo tab
$routes = [];
$stops = [];
$trips = [];
$selected_route = null;
$selected_trip = null;
$selected_stop = null;
$stop_times = [];
$routes_at_stop = [];

if ($current_version) {
    if ($tab === 'routes') {
        $route_id = $_GET['route_id'] ?? '';
        if ($route_id !== '') {
            $selected_route = get_route($route_id, $version_id);
            // Lấy danh sách chuyến thuộc tuyến này
            if ($selected_route) {
                $trips = search_trips(['route_id' => $route_id], $version_id);
            }
        } else {
            $routes = ($keyword !== '') ? search_routes($keyword, $version_id) : get_all_routes($version_id);
        }
    } elseif ($tab === 'stops') {
        $stop_id = $_GET['stop_id'] ?? '';
        if ($stop_id !== '') {
            $selected_stop = get_stop($stop_id, $version_id);
            if ($selected_stop) {
                $routes_at_stop = get_routes_at_stop($stop_id, $version_id);
            }
        } else {
            $stops = ($keyword !== '') ? search_stops($keyword, $version_id) : get_all_stops($version_id);
        }
    } elseif ($tab === 'trips') {
        $trip_id = $_GET['trip_id'] ?? '';
        if ($trip_id !== '') {
            $stmt = $pdo->prepare("SELECT t.*, r.route_short_name, r.route_long_name, r.route_type 
                                   FROM gtfs_trips t 
                                   JOIN gtfs_routes r ON t.feed_version_id = r.feed_version_id AND t.route_id = r.route_id 
                                   WHERE t.feed_version_id = ? AND t.trip_id = ?");
            $stmt->execute([$version_id, $trip_id]);
            $selected_trip = $stmt->fetch();
            if ($selected_trip) {
                $stop_times = get_stop_times($trip_id, $version_id);
            }
        } else {
            $route_filter = $_GET['route_filter'] ?? '';
            $trips = search_trips(['route_id' => $route_filter, 'keyword' => $keyword], $version_id);
            $all_routes = get_all_routes($version_id);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tra Cứu Tuyến, Trạm, Chuyến & Lần Dừng - GTFS</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .top-navbar {
            background: #084298;
        }
        .main-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #fff;
        }
        .nav-tabs .nav-link {
            font-weight: 600;
            color: #64748b;
            border: none;
            padding: 12px 20px;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }
        .route-badge {
            font-size: 0.95rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="../index.php">
                <i class="bi bi-bus-front-fill text-warning"></i> Tra Cứu Giao Thông Công Cộng
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="../index.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-house-door me-1"></i> Trang chủ
                </a>
                <a href="gtfs_browse.php" class="btn btn-sm btn-warning fw-semibold">
                    <i class="bi bi-compass me-1"></i> Tuyến & Trạm
                </a>
                <a href="next_trip.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-clock-history me-1"></i> Chuyến Kế Tiếp
                </a>
                <?php if ($user): ?>
                    <a href="saved_queries.php" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-bookmark-star-fill me-1"></i> Truy Vấn Đã Lưu
                    </a>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a href="../admin/gtfs.php" class="btn btn-sm btn-light fw-bold text-dark">
                            <i class="bi bi-speedometer2"></i> Quản Trị
                        </a>
                    <?php endif; ?>
                    <a href="../auth/logout.php" class="btn btn-sm btn-outline-light">Đăng xuất</a>
                <?php else: ?>
                    <a href="../auth/login.php" class="btn btn-sm btn-light fw-semibold">Đăng nhập</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container py-4">

        <!-- Header thông tin phiên bản đang duyệt -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-search text-primary me-2"></i>Tra Cứu Chi Tiết Lịch Trình GTFS
                </h3>
                <p class="text-muted mb-0">Tìm kiếm tuyến, trạm dừng, chuyến xe và thứ tự các lần dừng theo chuẩn GTFS</p>
            </div>
            <div>
                <?php if ($current_version): ?>
                    <span class="badge bg-primary fs-6 px-3 py-2">
                        <i class="bi bi-layers-fill me-1"></i> Phiên bản: <?php echo htmlspecialchars($current_version['version_name']); ?>
                        <?php echo ($current_version['status'] === 'active') ? ' (Active)' : ''; ?>
                    </span>
                <?php else: ?>
                    <span class="badge bg-danger fs-6 px-3 py-2">Chưa có phiên bản nào</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$current_version): ?>
            <div class="alert alert-warning text-center py-5 rounded-4">
                <i class="bi bi-exclamation-octagon display-4 d-block mb-3 text-warning"></i>
                <h5 class="fw-bold">Chưa có dữ liệu lịch trình trong hệ thống!</h5>
                <p class="text-muted">Vui lòng đăng nhập với tài khoản Quản Trị Viên (Admin) và nhập tệp GTFS vào hệ thống.</p>
                <a href="../admin/gtfs.php" class="btn btn-primary">Đến trang Quản Trị GTFS</a>
            </div>
        <?php else: ?>

            <!-- Nav Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($tab === 'routes') ? 'active' : ''; ?>" href="gtfs_browse.php?tab=routes&version_id=<?php echo $version_id; ?>">
                        <i class="bi bi-signpost-2-fill me-1"></i> 1. Tuyến (Routes)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($tab === 'stops') ? 'active' : ''; ?>" href="gtfs_browse.php?tab=stops&version_id=<?php echo $version_id; ?>">
                        <i class="bi bi-geo-alt-fill me-1"></i> 2. Trạm Dừng (Stops)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($tab === 'trips') ? 'active' : ''; ?>" href="gtfs_browse.php?tab=trips&version_id=<?php echo $version_id; ?>">
                        <i class="bi bi-bus-front-fill me-1"></i> 3. Chuyến Xe & Lần Dừng (Trips & Stop Times)
                    </a>
                </li>
            </ul>

            <!-- ========================================== -->
            <!-- TAB 1: TUYẾN (ROUTES) -->
            <!-- ========================================== -->
            <?php if ($tab === 'routes'): ?>

                <?php if ($selected_route): ?>
                    <!-- Chi tiết 1 tuyến và danh sách các chuyến -->
                    <div class="mb-3">
                        <a href="gtfs_browse.php?tab=routes&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Quay lại danh sách tuyến
                        </a>
                    </div>

                    <div class="card main-card p-4 mb-4 border-start border-4 border-primary">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-primary route-badge mb-2">
                                    <i class="bi <?php echo route_type_icon($selected_route['route_type']); ?> me-1"></i>
                                    Tuyến <?php echo htmlspecialchars($selected_route['route_short_name']); ?>
                                </span>
                                <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($selected_route['route_long_name']); ?></h4>
                                <div class="text-muted small">
                                    Mã tuyến: <code><?php echo htmlspecialchars($selected_route['route_id']); ?></code> | 
                                    Loại: <strong><?php echo route_type_name($selected_route['route_type']); ?></strong>
                                    <?php if (!empty($selected_route['agency_name'])): ?>
                                        | Đơn vị vận hành: <strong><?php echo htmlspecialchars($selected_route['agency_name']); ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card main-card p-4">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-list-nested text-primary me-1"></i> Các Chuyến Xe Thuộc Tuyến Này (<?php echo count($trips); ?> chuyến)
                        </h5>
                        <?php if (empty($trips)): ?>
                            <p class="text-muted">Chưa có chuyến xe nào được ghi nhận cho tuyến này.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã Chuyến (trip_id)</th>
                                            <th>Hướng Tuyến (Headsign)</th>
                                            <th>Lịch Hoạt Động (service_id)</th>
                                            <th class="text-end">Chi Tiết</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($trips as $t): ?>
                                            <tr>
                                                <td class="fw-bold"><code><?php echo htmlspecialchars($t['trip_id']); ?></code></td>
                                                <td><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></td>
                                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t['service_id']); ?></span></td>
                                                <td class="text-end">
                                                    <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                                        <i class="bi bi-clock-history"></i> Xem các lần dừng
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Tìm kiếm & Danh sách tuyến -->
                    <div class="card main-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="routes">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">
                            <div class="col-md-10">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-white"><i class="bi bi-search text-primary"></i></span>
                                    <input type="text" name="keyword" class="form-control" 
                                           placeholder="Nhập tên tuyến hoặc mã tuyến (VD: 01, Bến Thành, Tuyến Metro...)" 
                                           value="<?php echo htmlspecialchars($keyword); ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Tìm Tuyến</button>
                            </div>
                        </form>
                    </div>

                    <div class="row g-3">
                        <?php if (empty($routes)): ?>
                            <div class="col-12">
                                <div class="alert alert-warning text-center py-4">
                                    Không tìm thấy tuyến xe nào phù hợp với từ khóa!
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($routes as $r): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card main-card h-100 p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge bg-primary route-badge">
                                                <i class="bi <?php echo route_type_icon($r['route_type']); ?> me-1"></i>
                                                Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?>
                                            </span>
                                            <span class="badge bg-light text-secondary border">Mã: <?php echo htmlspecialchars($r['route_id']); ?></span>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($r['route_long_name']); ?></h6>
                                        <p class="text-muted small mb-3">
                                            Loại phương tiện: <strong><?php echo route_type_name($r['route_type']); ?></strong>
                                        </p>
                                        <div class="mt-auto">
                                            <a href="gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                                                <i class="bi bi-eye"></i> Xem danh sách chuyến
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <!-- ========================================== -->
            <!-- TAB 2: TRẠM DỪNG (STOPS) -->
            <!-- ========================================== -->
            <?php elseif ($tab === 'stops'): ?>

                <?php if ($selected_stop): ?>
                    <!-- Chi tiết 1 trạm và các tuyến đi qua -->
                    <div class="mb-3">
                        <a href="gtfs_browse.php?tab=stops&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Quay lại danh sách trạm
                        </a>
                    </div>

                    <div class="card main-card p-4 mb-4 border-start border-4 border-danger">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-danger fs-6 mb-2">
                                    <i class="bi bi-geo-alt-fill me-1"></i> Mã Trạm: <?php echo htmlspecialchars($selected_stop['stop_id']); ?>
                                </span>
                                <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($selected_stop['stop_name']); ?></h3>
                                <?php if (!empty($selected_stop['stop_lat']) && !empty($selected_stop['stop_lon'])): ?>
                                    <div class="text-muted small">
                                        <i class="bi bi-pin-map"></i> Tọa độ: <?php echo $selected_stop['stop_lat']; ?>, <?php echo $selected_stop['stop_lon']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <a href="next_trip.php?stop_id=<?php echo urlencode($selected_stop['stop_id']); ?>" class="btn btn-primary fw-bold shadow-sm">
                                    <i class="bi bi-clock-history me-1"></i> Tìm Chuyến Kế Tiếp Tại Trạm Này
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card main-card p-4">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-signpost-split text-primary me-1"></i> Các Tuyến Đi Qua Trạm Này (<?php echo count($routes_at_stop); ?> tuyến)
                        </h5>
                        <?php if (empty($routes_at_stop)): ?>
                            <p class="text-muted">Không có tuyến nào đi qua trạm này trong bộ dữ liệu.</p>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($routes_at_stop as $r): ?>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded-3 bg-light d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge bg-primary me-2">Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?></span>
                                                <strong class="text-dark"><?php echo htmlspecialchars($r['route_long_name']); ?></strong>
                                            </div>
                                            <a href="gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary">
                                                Xem
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Tìm kiếm trạm dừng -->
                    <div class="card main-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="stops">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">
                            <div class="col-md-10">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-white"><i class="bi bi-geo-alt text-danger"></i></span>
                                    <input type="text" name="keyword" class="form-control" 
                                           placeholder="Nhập tên trạm hoặc mã trạm (VD: Bến Thành, Chợ Lớn, STOP_01...)" 
                                           value="<?php echo htmlspecialchars($keyword); ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Tìm Trạm</button>
                            </div>
                        </form>
                    </div>

                    <div class="card main-card p-4">
                        <h6 class="fw-bold text-dark mb-3">Danh Sách Trạm Dừng (Tìm thấy <?php echo count($stops); ?> trạm)</h6>
                        <div class="table-responsive">
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
                                            <td><code><?php echo htmlspecialchars($s['stop_id']); ?></code></td>
                                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($s['stop_name']); ?></td>
                                            <td class="text-muted small">
                                                <?php echo ($s['stop_lat'] && $s['stop_lon']) ? $s['stop_lat'] . ', ' . $s['stop_lon'] : 'N/A'; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="gtfs_browse.php?tab=stops&stop_id=<?php echo urlencode($s['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-secondary me-1">
                                                    <i class="bi bi-info-circle"></i> Chi tiết
                                                </a>
                                                <a href="next_trip.php?stop_id=<?php echo urlencode($s['stop_id']); ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-clock-history"></i> Tìm Chuyến
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- ========================================== -->
            <!-- TAB 3: CHUYẾN XE & LẦN DỪNG (TRIPS & STOP TIMES) -->
            <!-- ========================================== -->
            <?php elseif ($tab === 'trips'): ?>

                <?php if ($selected_trip): ?>
                    <!-- Xem danh sách stop_times của 1 chuyến -->
                    <div class="mb-3">
                        <a href="gtfs_browse.php?tab=trips&version_id=<?php echo $version_id; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Quay lại danh sách chuyến
                        </a>
                    </div>

                    <div class="card main-card p-4 mb-4 border-start border-4 border-success">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-primary me-2">Tuyến <?php echo htmlspecialchars($selected_trip['route_short_name']); ?></span>
                                <span class="badge bg-secondary">Chuyến #<?php echo htmlspecialchars($selected_trip['trip_id']); ?></span>
                                <h4 class="fw-bold text-dark mt-2 mb-1">
                                    <?php echo htmlspecialchars($selected_trip['route_long_name']); ?>
                                </h4>
                                <div class="text-muted small">
                                    Hướng: <strong><?php echo htmlspecialchars($selected_trip['trip_headsign'] ?: 'Theo lộ trình'); ?></strong> | 
                                    Lịch dịch vụ (service_id): <code><?php echo htmlspecialchars($selected_trip['service_id']); ?></code>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card main-card p-4">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-clock-fill text-primary me-2"></i>Thứ Tự & Giờ Dừng Tại Từng Trạm (stop_times)
                        </h5>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 70px;">Thứ Tự</th>
                                        <th>Mã Trạm</th>
                                        <th>Tên Trạm Dừng</th>
                                        <th>Giờ Đến (Arrival)</th>
                                        <th>Giờ Khởi Hành (Departure)</th>
                                        <th>Ghi Chú Giờ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stop_times as $st): 
                                        $dep_secs = gtfs_time_to_seconds($st['departure_time']);
                                        $is_over_24 = ($dep_secs >= 86400);
                                    ?>
                                        <tr class="<?php echo $is_over_24 ? 'table-warning bg-opacity-25' : ''; ?>">
                                            <td class="fw-bold text-center">#<?php echo $st['stop_sequence']; ?></td>
                                            <td><code><?php echo htmlspecialchars($st['stop_id']); ?></code></td>
                                            <td class="fw-semibold text-dark">
                                                <a href="gtfs_browse.php?tab=stops&stop_id=<?php echo urlencode($st['stop_id']); ?>&version_id=<?php echo $version_id; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($st['stop_name']); ?>
                                                </a>
                                            </td>
                                            <td class="fw-bold text-secondary"><?php echo htmlspecialchars($st['arrival_time']); ?></td>
                                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($st['departure_time']); ?></td>
                                            <td>
                                                <?php if ($is_over_24): ?>
                                                    <span class="badge bg-purple text-white" style="background-color: #6f42c1;">
                                                        <i class="bi bi-moon-stars"></i> Giờ qua 24h (Đồng hồ: <?php echo gtfs_time_to_clock($st['departure_time']); ?> sáng hôm sau)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">Trong ngày</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Bộ lọc danh sách chuyến -->
                    <div class="card main-card p-4 mb-4">
                        <form method="GET" action="gtfs_browse.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="trips">
                            <input type="hidden" name="version_id" value="<?php echo $version_id; ?>">

                            <div class="col-md-5">
                                <label class="form-label small fw-bold">Lọc theo Tuyến:</label>
                                <select name="route_filter" class="form-select">
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
                                <label class="form-label small fw-bold">Từ khóa chuyến hoặc hướng đi:</label>
                                <input type="text" name="keyword" class="form-control" 
                                       placeholder="Mã chuyến hoặc hướng đi..." 
                                       value="<?php echo htmlspecialchars($keyword); ?>">
                            </div>

                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100 fw-bold" style="height: 42px;">
                                    <i class="bi bi-filter"></i> Lọc Chuyến
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="card main-card p-4">
                        <h6 class="fw-bold text-dark mb-3">Danh Sách Chuyến Xe (Tìm thấy <?php echo count($trips); ?> chuyến)</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Mã Chuyến (trip_id)</th>
                                        <th>Tuyến Xe</th>
                                        <th>Hướng Tuyến (Headsign)</th>
                                        <th>Lịch Dịch Vụ</th>
                                        <th class="text-end">Hành Động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trips as $t): ?>
                                        <tr>
                                            <td class="fw-bold"><code><?php echo htmlspecialchars($t['trip_id']); ?></code></td>
                                            <td>
                                                <span class="badge bg-primary me-1">Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?></span>
                                                <span class="text-muted small"><?php echo htmlspecialchars($t['route_long_name']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t['service_id']); ?></span></td>
                                            <td class="text-end">
                                                <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>&version_id=<?php echo $version_id; ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                                    <i class="bi bi-clock-history"></i> Xem Lần Dừng
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
