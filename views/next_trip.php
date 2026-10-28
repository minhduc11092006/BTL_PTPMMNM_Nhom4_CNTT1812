<?php
// next_trip.php - Tìm chuyến tiếp theo theo ngày hoạt động, ngày ngoại lệ và giờ vượt 24h
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

$stop_id = $_GET['stop_id'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$time = $_GET['time'] ?? date('H:i');
$save_success = '';
$save_error = '';

// Xử lý Lưu truy vấn mẫu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_query') {
    if (!$user) {
        $save_error = 'Vui lòng đăng nhập để lưu truy vấn mẫu này.';
    } else {
        $q_name = trim($_POST['query_name'] ?? '');
        if ($q_name === '') {
            $q_name = "Tra cứu trạm " . $stop_id . " lúc " . $time . " ngày " . $date;
        }
        $params = [
            'stop_id' => $stop_id,
            'date' => $date,
            'time' => $time
        ];
        if (save_query($user['id'], $q_name, 'next_trip', $params)) {
            $save_success = "Đã lưu truy vấn mẫu: \"<strong>" . htmlspecialchars($q_name) . "</strong>\" thành công!";
        } else {
            $save_error = "Không thể lưu truy vấn mẫu.";
        }
    }
}

// Lấy danh sách trạm dừng nếu có bản active
$stops = [];
$selected_stop = null;
$next_trips = [];
$searched = false;

if ($active_version) {
    $stops = get_all_stops($active_version['id']);

    if ($stop_id !== '') {
        $selected_stop = get_stop($stop_id, $active_version['id']);
        if ($selected_stop) {
            $searched = true;
            $next_trips = find_next_trips($stop_id, $date, $time, $active_version['id'], 15);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tìm Chuyến Kế Tiếp - Tra Cứu Lịch Trình GTFS</title>
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
        }
        .search-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            background: #fff;
        }
        .trip-result-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #fff;
            transition: all 0.2s ease-in-out;
            border-left: 5px solid #0d6efd;
        }
        .trip-result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.09);
        }
        .trip-overnight {
            border-left-color: #6f42c1 !important;
            background: #fdfaff;
        }
        .time-badge {
            font-size: 1.1rem;
            font-weight: 700;
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
                <a href="gtfs_browse.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-compass me-1"></i> Tuyến & Trạm
                </a>
                <a href="map.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-map-fill me-1"></i> Bản Đồ
                </a>
                <a href="next_trip.php" class="btn btn-sm btn-warning fw-semibold">
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

        <!-- Header -->
        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark mb-1">
                <i class="bi bi-clock-history text-primary me-2"></i>Tìm Chuyến Kế Tiếp Tại Trạm
            </h2>
            <p class="text-muted">
                Xử lý chính xác theo <strong>ngày hoạt động</strong>, <strong>lịch ngoại lệ (thêm / hủy dịch vụ)</strong> và <strong>giờ chạy qua nửa đêm (> 24:00:00)</strong>
            </p>
            <?php if ($active_version): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                    <i class="bi bi-check-circle-fill me-1"></i> Dữ liệu lịch trình: <strong><?php echo htmlspecialchars($active_version['version_name']); ?></strong>
                </span>
            <?php else: ?>
                <div class="alert alert-warning mt-2 mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Chưa có phiên bản lịch trình nào được kích hoạt. Quản trị viên cần kích hoạt một bản GTFS để tra cứu.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($save_success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo $save_success; ?>
                <a href="saved_queries.php" class="ms-2 fw-semibold text-decoration-none">Xem danh sách truy vấn đã lưu</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($save_error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-x-circle-fill me-2"></i> <?php echo $save_error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form Tra Cứu -->
        <div class="card search-card p-4 p-md-4 mb-4">
            <form method="GET" action="next_trip.php" class="row g-3 align-items-end">

                <!-- 1. Chọn Trạm -->
                <div class="col-md-5">
                    <label for="stop_id" class="form-label fw-bold">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i> Chọn Trạm Dừng <span class="text-danger">*</span>
                    </label>
                    <select class="form-select form-select-lg" id="stop_id" name="stop_id" required>
                        <option value="">-- Chọn hoặc tìm trạm dừng --</option>
                        <?php foreach ($stops as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['stop_id']); ?>" <?php echo ($stop_id === $s['stop_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['stop_name']); ?> (Mã: <?php echo htmlspecialchars($s['stop_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Chọn Ngày -->
                <div class="col-md-3">
                    <label for="date" class="form-label fw-bold">
                        <i class="bi bi-calendar-event-fill text-primary me-1"></i> Ngày Tra Cứu <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control form-control-lg" id="date" name="date" 
                           value="<?php echo htmlspecialchars($date); ?>" required>
                </div>

                <!-- 3. Chọn Giờ -->
                <div class="col-md-2">
                    <label for="time" class="form-label fw-bold">
                        <i class="bi bi-clock-fill text-warning me-1"></i> Giờ Bắt Đầu <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg" id="time" name="time" 
                           placeholder="HH:MM" value="<?php echo htmlspecialchars($time); ?>" required>
                    <div class="form-text small">Hỗ trợ 00:00 - 26:00</div>
                </div>

                <!-- 4. Nút Tìm Kiếm -->
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                        <i class="bi bi-search me-1"></i> Tìm Chuyến
                    </button>
                </div>
            </form>
        </div>

        <!-- KẾT QUẢ TÌM KIẾM -->
        <?php if ($searched && $selected_stop): ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-pin-map-fill text-danger me-1"></i> Các chuyến sắp đến tại: 
                        <span class="text-primary"><?php echo htmlspecialchars($selected_stop['stop_name']); ?></span>
                    </h5>
                    <small class="text-muted">
                        Từ ngày: <strong><?php echo date('d/m/Y', strtotime($date)); ?></strong> lúc <strong><?php echo htmlspecialchars($time); ?></strong>
                        (Tìm thấy <?php echo count($next_trips); ?> chuyến kế tiếp)
                    </small>
                </div>

                <!-- Nút Lưu Truy Vấn Mẫu -->
                <?php if ($user): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#saveQueryModal">
                        <i class="bi bi-bookmark-plus-fill me-1"></i> Lưu Truy Vấn Này
                    </button>
                <?php endif; ?>
            </div>

            <!-- NẾU KHÔNG CÓ CHUYẾN: YÊU CẦU ĐỀ BÀI BÁO RÕ RÀNG -->
            <?php if (empty($next_trips)): ?>
                <div class="alert alert-warning text-center py-5 rounded-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill display-3 text-warning d-block mb-3"></i>
                    <h4 class="fw-bold text-dark">Không có chuyến nào tiếp theo!</h4>
                    <p class="text-muted mb-3" style="max-width: 600px; margin: 0 auto;">
                        Không tìm thấy chuyến xe nào khởi hành tại trạm <strong><?php echo htmlspecialchars($selected_stop['stop_name']); ?></strong> 
                        sau thời điểm <strong><?php echo htmlspecialchars($time); ?></strong> ngày <strong><?php echo date('d/m/Y', strtotime($date)); ?></strong>.
                    </p>
                    <div class="small text-secondary mb-3">
                        <p class="mb-1"><strong>Nguyên nhân có thể là:</strong></p>
                        <ul class="list-unstyled mb-0">
                            <li>• Các chuyến trong ngày đã kết thúc hoặc giờ tra cứu quá muộn.</li>
                            <li>• Tuyến không hoạt động vào thứ này theo lịch (<code>calendar.txt</code>).</li>
                            <li>• Ngày này có lịch nghỉ / hủy dịch vụ đặc biệt (<code>calendar_dates.txt</code>).</li>
                        </ul>
                    </div>
                    <a href="next_trip.php?stop_id=<?php echo urlencode($stop_id); ?>&date=<?php echo $date; ?>&time=06:00" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Thử tra cứu từ đầu ngày (06:00)
                    </a>
                </div>

            <!-- NẾU CÓ CHUYẾN -->
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($next_trips as $t): 
                        $is_overnight = !empty($t['is_overnight']);
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card trip-result-card h-100 p-3 <?php echo $is_overnight ? 'trip-overnight' : ''; ?>">
                                
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">
                                        <i class="bi <?php echo route_type_icon($t['route_type']); ?> me-1"></i>
                                        Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?>
                                    </span>

                                    <?php if ($is_overnight): ?>
                                        <span class="badge bg-purple text-white" style="background-color: #6f42c1;">
                                            <i class="bi bi-moon-stars-fill me-1"></i> Chuyến Đêm (>24h)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Đúng Lịch</span>
                                    <?php endif; ?>
                                </div>

                                <h6 class="fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($t['route_long_name']); ?>
                                </h6>

                                <div class="text-secondary small mb-3">
                                    <i class="bi bi-compass"></i> Hướng: <strong><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></strong>
                                    (Chuyến #<?php echo htmlspecialchars($t['trip_id']); ?>)
                                </div>

                                <!-- THỜI GIAN KHỞI HÀNH -->
                                <div class="p-2 rounded bg-light mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted small">Giờ khởi hành tại trạm:</span>
                                        <span class="text-primary time-badge">
                                            <i class="bi bi-clock-fill me-1"></i> <?php echo htmlspecialchars($t['clock_time']); ?>
                                        </span>
                                    </div>

                                    <?php if ($is_overnight): ?>
                                        <div class="d-flex justify-content-between align-items-center text-muted small mt-1 border-top pt-1">
                                            <span>Giờ định dạng GTFS:</span>
                                            <code class="text-danger fw-bold"><?php echo htmlspecialchars($t['departure_time']); ?></code>
                                        </div>
                                        <div class="small text-purple mt-1" style="color: #6f42c1; font-size: 0.8rem;">
                                            <i class="bi bi-info-circle-fill"></i> Lịch dịch vụ ngày <?php echo $t['service_date'] ?? $t['actual_date']; ?>, chạy rạng sáng ngày <?php echo $t['actual_date']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-auto d-flex gap-2">
                                    <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                                        <i class="bi bi-list-ol me-1"></i> Xem các lần dừng
                                    </a>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($searched && !$selected_stop): ?>
            <div class="alert alert-danger text-center">
                Mã trạm không hợp lệ hoặc không tồn tại trong phiên bản lịch này.
            </div>
        <?php else: ?>
            <div class="card p-5 text-center text-muted border-0 bg-white rounded-4 shadow-sm">
                <i class="bi bi-bus-front display-4 text-primary mb-3"></i>
                <h5 class="fw-bold text-dark">Hãy chọn một trạm dừng để bắt đầu tra cứu chuyến tiếp theo</h5>
                <p class="mb-0">Hệ thống sẽ tính toán ngày trong tuần, ngày ngoại lệ và các chuyến chạy qua 24h để hiển thị kết quả chính xác nhất.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL LƯU TRUY VẤN MẪU -->
    <?php if ($user && $searched && $selected_stop): ?>
        <div class="modal fade" id="saveQueryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="next_trip.php?stop_id=<?php echo urlencode($stop_id); ?>&date=<?php echo urlencode($date); ?>&time=<?php echo urlencode($time); ?>">
                        <input type="hidden" name="action" value="save_query">
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold"><i class="bi bi-bookmark-star-fill text-warning me-1"></i> Lưu Truy Vấn Mẫu</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3">Lưu lại bộ lọc này để chạy lại bất kỳ lúc nào hoặc tự động kiểm tra khi có phiên bản lịch mới.</p>
                            <div class="mb-3">
                                <label for="query_name" class="form-label fw-bold">Đặt tên cho truy vấn mẫu này:</label>
                                <input type="text" class="form-control" id="query_name" name="query_name" 
                                       value="Chuyến tiếp theo tại <?php echo htmlspecialchars($selected_stop['stop_name']); ?> (<?php echo htmlspecialchars($time); ?>)" required>
                            </div>
                            <div class="p-2 bg-light rounded small text-secondary">
                                <div>• <strong>Trạm:</strong> <?php echo htmlspecialchars($selected_stop['stop_name']); ?></div>
                                <div>• <strong>Giờ:</strong> <?php echo htmlspecialchars($time); ?></div>
                                <div>• <strong>Ngày:</strong> <?php echo htmlspecialchars($date); ?></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-primary fw-bold">
                                <i class="bi bi-check2"></i> Xác Nhận Lưu
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
