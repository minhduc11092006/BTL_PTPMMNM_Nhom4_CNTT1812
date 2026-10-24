<?php
// saved_queries.php - Quản lý và chạy lại các truy vấn mẫu đã lưu trên phiên bản lịch hiện tại
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gtfs_functions.php';

require_login('saved_queries.php');
$user = current_user();
$active_version = get_active_feed_version();

$msg = '';
$error = '';

// Xử lý Xóa truy vấn
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $qid = (int)($_POST['query_id'] ?? 0);
    if (delete_saved_query($qid, $user['id'])) {
        $msg = 'Đã xóa truy vấn mẫu thành công.';
    } else {
        $error = 'Không thể xóa truy vấn này.';
    }
}

// Xử lý Chạy lại truy vấn
$run_qid = isset($_GET['run']) ? (int)$_GET['run'] : 0;
$query_to_run = null;
$run_results = null;

if ($run_qid > 0) {
    $query_to_run = get_saved_query($run_qid);
    if ($query_to_run && (int)$query_to_run['user_id'] === (int)$user['id']) {
        if ($active_version) {
            $p = $query_to_run['parameters'];
            if ($query_to_run['query_type'] === 'next_trip' && !empty($p['stop_id'])) {
                $stop = get_stop($p['stop_id'], $active_version['id']);
                $date = $p['date'] ?? date('Y-m-d');
                $time = $p['time'] ?? date('H:i');
                $run_results = [
                    'stop' => $stop,
                    'date' => $date,
                    'time' => $time,
                    'trips' => find_next_trips($p['stop_id'], $date, $time, $active_version['id'], 15)
                ];
            }
        }
    }
}

$saved_queries = get_saved_queries($user['id']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Truy Vấn Mẫu Đã Lưu - GTFS</title>
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
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-bus-front-fill text-warning"></i> Tra Cứu Giao Thông Công Cộng
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="index.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-house-door me-1"></i> Trang chủ
                </a>
                <a href="gtfs_browse.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-compass me-1"></i> Tuyến & Trạm
                </a>
                <a href="next_trip.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-clock-history me-1"></i> Chuyến Kế Tiếp
                </a>
                <a href="saved_queries.php" class="btn btn-sm btn-warning fw-semibold">
                    <i class="bi bi-bookmark-star-fill me-1"></i> Truy Vấn Đã Lưu
                </a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin/gtfs.php" class="btn btn-sm btn-light fw-bold text-dark">
                        <i class="bi bi-speedometer2"></i> Quản Trị
                    </a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-sm btn-outline-light">Đăng xuất</a>
            </div>
        </div>
    </nav>

    <div class="container py-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-bookmark-star-fill text-warning me-2"></i>Truy Vấn Mẫu Đã Lưu
                </h3>
                <p class="text-muted mb-0">Lưu lại lộ trình và bộ lọc yêu thích; dễ dàng chạy lại khi đổi sang phiên bản lịch mới</p>
            </div>
            <div>
                <a href="next_trip.php" class="btn btn-primary fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i> Tra Cứu & Lưu Truy Vấn Mới
                </a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-x-circle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- KẾT QUẢ CHẠY LẠI TRUY VẤN (NẾU ĐƯỢC CHỌN) -->
        <?php if ($run_results): ?>
            <div class="card main-card p-4 mb-4 border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <span class="badge bg-success mb-2"><i class="bi bi-check-circle-fill me-1"></i> Kết quả chạy lại trên bản: <?php echo htmlspecialchars($active_version['version_name']); ?></span>
                        <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($query_to_run['query_name']); ?></h4>
                        <div class="text-muted small">
                            Trạm: <strong><?php echo htmlspecialchars($run_results['stop']['stop_name'] ?? 'N/A'); ?></strong> | 
                            Ngày: <strong><?php echo date('d/m/Y', strtotime($run_results['date'])); ?></strong> | 
                            Giờ từ: <strong><?php echo htmlspecialchars($run_results['time']); ?></strong>
                        </div>
                    </div>
                    <a href="saved_queries.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i> Đóng kết quả
                    </a>
                </div>

                <?php if (empty($run_results['trips'])): ?>
                    <div class="alert alert-warning text-center py-4 mb-0">
                        <i class="bi bi-exclamation-triangle-fill fs-4 text-warning me-2"></i>
                        Không có chuyến xe nào tiếp theo cho truy vấn này trên phiên bản lịch hiện tại.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tuyến</th>
                                    <th>Chuyến (trip_id)</th>
                                    <th>Hướng Tuyến (Headsign)</th>
                                    <th>Giờ Khởi Hành</th>
                                    <th>Trạng Thái</th>
                                    <th class="text-end">Chi Tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($run_results['trips'] as $t): ?>
                                    <tr>
                                        <td><span class="badge bg-primary">Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?></span></td>
                                        <td><code><?php echo htmlspecialchars($t['trip_id']); ?></code></td>
                                        <td><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></td>
                                        <td class="fw-bold text-primary fs-6"><?php echo htmlspecialchars($t['clock_time']); ?></td>
                                        <td>
                                            <?php if (!empty($t['is_overnight'])): ?>
                                                <span class="badge bg-purple text-white" style="background-color: #6f42c1;">
                                                    Chuyến Đêm (>24h)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Đúng Lịch</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>" class="btn btn-sm btn-outline-primary">
                                                Xem Lần Dừng
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- DANH SÁCH CÁC TRUY VẤN MẪU -->
        <div class="card main-card p-4">
            <h5 class="fw-bold text-dark mb-3">
                <i class="bi bi-list-stars text-primary me-2"></i>Danh Sách Các Truy Vấn Đã Lưu Của Bạn
            </h5>

            <?php if (empty($saved_queries)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-bookmark-plus display-4 d-block mb-3 text-secondary"></i>
                    <h5>Bạn chưa có truy vấn mẫu nào!</h5>
                    <p class="mb-3">Khi tra cứu chuyến tiếp theo ở trang "Tìm Chuyến Kế Tiếp", nhấn nút "Lưu Truy Vấn Này" để lưu lại.</p>
                    <a href="next_trip.php" class="btn btn-primary">Đến trang Tìm Chuyến Kế Tiếp</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tên Truy Vấn</th>
                                <th>Loại</th>
                                <th>Tham Số Tra Cứu</th>
                                <th>Thời Gian Lưu</th>
                                <th class="text-end">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($saved_queries as $q): 
                                $params = json_decode($q['parameters'], true);
                            ?>
                                <tr>
                                    <td class="fw-bold text-dark">
                                        <i class="bi bi-bookmark-fill text-warning me-1"></i>
                                        <?php echo htmlspecialchars($q['query_name']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info text-dark"><?php echo strtoupper($q['query_type']); ?></span>
                                    </td>
                                    <td class="small text-muted">
                                        <?php if (!empty($params['stop_id'])): ?>
                                            Trạm: <code><?php echo htmlspecialchars($params['stop_id']); ?></code> |
                                        <?php endif; ?>
                                        Giờ: <strong><?php echo htmlspecialchars($params['time'] ?? 'N/A'); ?></strong> |
                                        Ngày: <strong><?php echo htmlspecialchars($params['date'] ?? 'N/A'); ?></strong>
                                    </td>
                                    <td class="small"><?php echo date('d/m/Y H:i', strtotime($q['created_at'])); ?></td>
                                    <td class="text-end">
                                        <!-- Nút Chạy lại -->
                                        <a href="saved_queries.php?run=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary fw-semibold me-1" title="Chạy lại truy vấn này trên phiên bản lịch active hiện tại">
                                            <i class="bi bi-arrow-repeat"></i> Chạy Lại
                                        </a>

                                        <!-- Nút Mở trang next_trip -->
                                        <?php if ($q['query_type'] === 'next_trip' && !empty($params['stop_id'])): ?>
                                            <a href="next_trip.php?stop_id=<?php echo urlencode($params['stop_id']); ?>&date=<?php echo urlencode($params['date'] ?? date('Y-m-d')); ?>&time=<?php echo urlencode($params['time'] ?? date('H:i')); ?>" class="btn btn-sm btn-outline-secondary me-1" title="Mở trong trang tìm kiếm">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Nút Xóa -->
                                        <form method="POST" action="saved_queries.php" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa truy vấn này không?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="query_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
