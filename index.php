<?php
// index.php - Trang chủ tra cứu lịch trình giao thông công cộng chuẩn GTFS
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$gtfs_routes = [];
$total_gtfs_trips = 0;
$total_gtfs_stops = 0;

if ($active_version) {
    if ($keyword !== '') {
        $gtfs_routes = search_routes($keyword, $active_version['id']);
    } else {
        $gtfs_routes = get_all_routes($active_version['id']);
    }
    $stats = get_feed_stats($active_version['id']);
    $total_gtfs_trips = $stats['trips'] ?? 0;
    $total_gtfs_stops = $stats['stops'] ?? 0;
} else {
    // Fallback sang mock data nếu chưa kích hoạt bản GTFS nào
    $trips = get_all_trips();
    if ($keyword !== '') {
        $trips = array_filter($trips, function ($trip) use ($keyword) {
            $kw = mb_strtolower($keyword, 'UTF-8');
            $from = mb_strtolower($trip['from'], 'UTF-8');
            $to = mb_strtolower($trip['to'], 'UTF-8');
            return (strpos($from, $kw) !== false) || (strpos($to, $kw) !== false);
        });
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Tra Cứu Lịch Trình Giao Thông Công Cộng GTFS</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .top-navbar {
            background: #084298;
            font-size: 0.9rem;
        }
        .hero-banner {
            background: linear-gradient(135deg, #0d6efd 0%, #084298 100%);
            color: #fff;
            padding: 45px 0 65px;
            margin-bottom: -30px;
            border-radius: 0 0 25px 25px;
        }
        .search-card {
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border: none;
            background: #fff;
        }
        .route-card {
            border: none;
            border-radius: 16px;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
            background: #fff;
            overflow: hidden;
        }
        .route-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }
        .route-pill {
            font-size: 0.85rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 8px;
        }
        .quick-action-card {
            border: none;
            border-radius: 16px;
            transition: transform 0.2s;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .quick-action-card:hover {
            transform: translateY(-3px);
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-bus-front-fill text-warning fs-5"></i> Tra Cứu Lịch Trình GTFS
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="index.php" class="btn btn-sm btn-light fw-bold text-primary">
                    <i class="bi bi-house-door-fill me-1"></i> Trang chủ
                </a>
                <a href="views/gtfs_browse.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-compass me-1"></i> Tuyến & Trạm
                </a>
                <a href="views/next_trip.php" class="btn btn-sm btn-warning fw-semibold">
                    <i class="bi bi-clock-history me-1"></i> Chuyến Kế Tiếp
                </a>
                <?php if ($user): ?>
                    <a href="views/saved_queries.php" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-bookmark-star-fill me-1"></i> Truy Vấn Đã Lưu
                    </a>
                    <span class="text-white small ms-2 d-none d-md-inline">
                        <i class="bi bi-person-circle text-warning"></i> <?php echo htmlspecialchars($user['fullname']); ?>
                    </span>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a href="admin/gtfs.php" class="btn btn-sm btn-warning fw-bold ms-1">
                            <i class="bi bi-speedometer2"></i> Quản Trị GTFS
                        </a>
                    <?php endif; ?>
                    <a href="auth/logout.php" class="btn btn-sm btn-outline-light ms-1">Đăng xuất</a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn btn-sm btn-light fw-semibold ms-2">Đăng Nhập</a>
                    <a href="auth/register.php" class="btn btn-sm btn-outline-light">Đăng Ký</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Header -->
    <header class="hero-banner text-center">
        <div class="container">
            <h1 class="fw-bold mb-2"><i class="bi bi-bus-front"></i> Quản Lý & Tra Cứu Lịch Trình Giao Thông Công Cộng</h1>
            <p class="lead mb-3 text-white-50">Tra cứu tuyến, trạm, chuyến xe và thời gian di chuyển theo chuẩn dữ liệu GTFS tĩnh</p>
            
            <?php if ($active_version): ?>
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 bg-white bg-opacity-10 rounded-pill small border border-light border-opacity-25">
                    <i class="bi bi-broadcast text-warning"></i>
                    <span>Phiên bản lịch đang áp dụng: <strong><?php echo htmlspecialchars($active_version['version_name']); ?></strong></span>
                    <span class="badge bg-success">Active</span>
                </div>
            <?php else: ?>
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 bg-warning bg-opacity-25 rounded-pill small text-warning border border-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Chưa có phiên bản GTFS nào được kích hoạt. Đang hiển thị dữ liệu mẫu.</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Search Bar Section -->
        <div class="row justify-content-center mb-4">
            <div class="col-lg-10">
                <div class="card search-card p-4">
                    <form method="GET" action="index.php" class="row g-2 align-items-center">
                        <div class="col-md-9">
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-search text-primary"></i>
                                </span>
                                <input type="text" name="keyword" class="form-control border-start-0 ps-0" 
                                       placeholder="Tìm kiếm tuyến (VD: 01, Tuyến Metro, Chợ Lớn, Tân Vạn...)" 
                                       value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                                <i class="bi bi-search"></i> Tra Cứu
                            </button>
                            <?php if ($keyword !== ''): ?>
                                <a href="index.php" class="btn btn-outline-secondary btn-lg" title="Đặt lại bộ lọc">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 3 Nút Chức Năng Chính Nổi Bật -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <a href="views/next_trip.php" class="text-decoration-none">
                    <div class="card quick-action-card p-3 bg-white h-100 border-start border-4 border-primary">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                                <i class="bi bi-clock-history fs-3"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Tìm Chuyến Kế Tiếp</h6>
                                <p class="text-muted small mb-0">Xử lý ngày hoạt động, ngày ngoại lệ và giờ đêm vượt 24h</p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="views/gtfs_browse.php" class="text-decoration-none">
                    <div class="card quick-action-card p-3 bg-white h-100 border-start border-4 border-success">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-success-subtle text-success p-3 rounded-circle">
                                <i class="bi bi-compass fs-3"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Tra Cứu Tuyến & Trạm</h6>
                                <p class="text-muted small mb-0">Xem danh sách trạm dừng, chuyến xe và thứ tự các lần dừng</p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="<?php echo $user ? 'views/saved_queries.php' : 'auth/login.php'; ?>" class="text-decoration-none">
                    <div class="card quick-action-card p-3 bg-white h-100 border-start border-4 border-warning">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                                <i class="bi bi-bookmark-star fs-3"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Truy Vấn Mẫu Đã Lưu</h6>
                                <p class="text-muted small mb-0">Chạy lại các tra cứu thường dùng trên phiên bản lịch mới</p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- DANH SÁCH TUYẾN GTFS (KHI CÓ BẢN ACTIVE) -->
        <!-- ============================================== -->
        <?php if ($active_version): ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-signpost-2-fill text-primary me-2"></i>Danh Sách Tuyến Vận Tải Công Cộng
                    <?php if ($keyword !== ''): ?>
                        <span class="text-primary fs-6 fw-normal">(Kết quả tìm kiếm cho: "<strong><?php echo htmlspecialchars($keyword); ?></strong>" - <?php echo count($gtfs_routes); ?> tuyến)</span>
                    <?php else: ?>
                        <span class="text-muted fs-6 fw-normal">(Tổng cộng <?php echo count($gtfs_routes); ?> tuyến, <?php echo $total_gtfs_stops; ?> trạm, <?php echo $total_gtfs_trips; ?> chuyến)</span>
                    <?php endif; ?>
                </h5>
                <a href="views/gtfs_browse.php?tab=stops" class="btn btn-outline-primary btn-sm fw-semibold">
                    <i class="bi bi-geo-alt"></i> Xem toàn bộ trạm dừng
                </a>
            </div>

            <?php if (empty($gtfs_routes)): ?>
                <div class="alert alert-warning text-center py-5 rounded-4 shadow-sm">
                    <i class="bi bi-search display-4 text-warning d-block mb-3"></i>
                    <h5 class="fw-bold">Không tìm thấy tuyến nào phù hợp!</h5>
                    <p class="text-muted mb-3">Vui lòng thử tìm kiếm bằng mã tuyến hoặc tên đường khác.</p>
                    <a href="index.php" class="btn btn-primary">Xem tất cả các tuyến</a>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($gtfs_routes as $r): 
                        $routeTrips = search_trips(['route_id' => $r['route_id']], $active_version['id']);
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card route-card h-100 p-4">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge bg-primary route-pill">
                                        <i class="bi <?php echo route_type_icon($r['route_type']); ?> me-1"></i>
                                        Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?>
                                    </span>
                                    <span class="badge bg-light text-secondary border">Mã: <?php echo htmlspecialchars($r['route_id']); ?></span>
                                </div>

                                <h5 class="fw-bold text-dark mb-2">
                                    <?php echo htmlspecialchars($r['route_long_name']); ?>
                                </h5>

                                <p class="text-muted small mb-3">
                                    Loại phương tiện: <strong><?php echo route_type_name($r['route_type']); ?></strong>
                                    <?php if (!empty($r['agency_name'])): ?>
                                        <br>Vận hành: <strong><?php echo htmlspecialchars($r['agency_name']); ?></strong>
                                    <?php endif; ?>
                                </p>

                                <div class="d-flex justify-content-between align-items-center text-muted small p-2 bg-light rounded-3 mb-3">
                                    <span><i class="bi bi-bus-front text-primary me-1"></i> Số chuyến hoạt động:</span>
                                    <strong class="text-dark fs-6"><?php echo count($routeTrips); ?> chuyến</strong>
                                </div>

                                <div class="mt-auto d-flex gap-2">
                                    <a href="views/gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>" class="btn btn-outline-primary w-100 fw-semibold">
                                        <i class="bi bi-clock-history me-1"></i> Xem lịch chuyến
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- ============================================== -->
        <!-- FALLBACK HIỂN THỊ MOCK TRIPS (NẾU CHƯA CÓ GTFS ACTIVE) -->
        <!-- ============================================== -->
        <?php else: ?>

            <div class="alert alert-info d-flex justify-content-between align-items-center mb-4 p-3 rounded-4">
                <div>
                    <h6 class="fw-bold mb-1"><i class="bi bi-info-circle-fill me-1"></i> Hệ thống chưa có dữ liệu GTFS hoạt động</h6>
                    <p class="mb-0 small text-secondary">Đang tạm thời hiển thị dữ liệu mẫu. Quản trị viên vui lòng tải lên tệp zip GTFS để tra cứu đầy đủ.</p>
                </div>
                <?php if ($user && $user['role'] === 'admin'): ?>
                    <a href="admin/gtfs.php?tab=import" class="btn btn-primary btn-sm fw-bold text-nowrap">
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Tải lên GTFS
                    </a>
                <?php endif; ?>
            </div>

            <div class="row g-4">
                <?php foreach ($trips as $trip): 
                    $available_seats = $trip['total_seats'] - $trip['booked_seats'];
                    $is_full = ($available_seats <= 0);
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card route-card h-100 p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-primary route-pill">
                                    <i class="bi bi-tag-fill me-1"></i><?php echo htmlspecialchars($trip['type']); ?> #<?php echo (int)$trip['id']; ?>
                                </span>
                                <?php if ($is_full): ?>
                                    <span class="badge bg-danger">Hết chỗ</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Còn vé</span>
                                <?php endif; ?>
                            </div>

                            <h5 class="fw-bold text-dark mb-3">
                                <?php echo htmlspecialchars($trip['name']); ?>
                            </h5>

                            <div class="mb-3 small">
                                <div><i class="bi bi-record-circle text-success me-1"></i> <strong>Đi:</strong> <?php echo htmlspecialchars($trip['from']); ?></div>
                                <div><i class="bi bi-geo-alt-fill text-danger me-1"></i> <strong>Đến:</strong> <?php echo htmlspecialchars($trip['to']); ?></div>
                                <div class="mt-2"><i class="bi bi-clock-fill text-primary me-1"></i> Khởi hành: <strong><?php echo htmlspecialchars($trip['departure_time']); ?></strong></div>
                            </div>

                            <div class="mt-auto">
                                <a href="book_ticket.php?trip_id=<?php echo (int)$trip['id']; ?>" class="btn btn-outline-primary w-100 fw-semibold">
                                    <i class="bi bi-ticket-perforated-fill me-1"></i> Đặt vé
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </main>

    <footer class="text-center py-4 text-muted small border-top bg-white">
        &copy; <?php echo date('Y'); ?> Hệ Thống Quản Lý & Tra Cứu Lịch Trình Giao Thông Công Cộng (Chuẩn GTFS tĩnh). Xây dựng bằng PHP & Bootstrap 5.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
