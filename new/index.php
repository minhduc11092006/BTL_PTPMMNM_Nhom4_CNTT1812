<?php
// index.php - Tra cứu lịch trình và tìm kiếm chuyến xe
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();

// Xử lý tìm kiếm qua tham số GET
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$trips = get_all_trips();

// Lọc kết quả nếu có từ khóa tìm kiếm (theo Điểm đi hoặc Điểm đến)
if ($keyword !== '') {
    $trips = array_filter($trips, function ($trip) use ($keyword) {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $from = mb_strtolower($trip['from'], 'UTF-8');
        $to = mb_strtolower($trip['to'], 'UTF-8');
        return (strpos($from, $kw) !== false) || (strpos($to, $kw) !== false);
    });
}

$page_title = 'Lịch Trình Giao Thông Công Cộng - TransitHub';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/navbar.php';
?>

<!-- Hero Header -->
<header class="hero-banner text-center">
    <div class="container">
        <h1 class="fw-bold mb-2"><i class="bi bi-bus-front"></i> Lịch Trình Giao Thông Công Cộng</h1>
        <p class="lead mb-0 text-white-50">Tra cứu nhanh chóng - Đặt vé tiện lợi - Di chuyển an toàn</p>
    </div>
</header>

<main class="container pb-5">
    <!-- Search Bar Section (GET Method) -->
    <div class="row justify-content-center mb-4">
        <div class="col-lg-10">
            <div class="card search-card p-4">
                <form method="GET" action="index.php" class="row g-2 align-items-center">
                    <div class="col-md-9">
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-geo-alt-fill text-danger"></i>
                            </span>
                            <!-- Chống XSS bằng htmlspecialchars cho từ khóa tìm kiếm -->
                            <input type="text" name="keyword" class="form-control border-start-0 ps-0" 
                                   placeholder="Nhập Điểm đi hoặc Điểm đến (VD: Bến Thành, Chợ Lớn, Nha Trang...)" 
                                   value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                            <i class="bi bi-search"></i> Tìm kiếm
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

    <!-- Thông báo kết quả tìm kiếm -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-secondary mb-0">
            <i class="bi bi-calendar3"></i> Danh sách chuyến xe
            <?php if ($keyword !== ''): ?>
                <span class="text-primary fs-6 fw-normal">
                    (Kết quả tìm kiếm cho: "<strong><?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?></strong>" - <?php echo count($trips); ?> chuyến)
                </span>
            <?php endif; ?>
        </h5>
        <span class="text-muted small">Cập nhật theo thời gian thực</span>
    </div>

    <!-- Danh sách chuyến xe -->
    <?php if (empty($trips)): ?>
        <div class="alert alert-warning text-center py-5 shadow-sm rounded-4">
            <i class="bi bi-exclamation-circle display-4 d-block mb-3 text-warning"></i>
            <h5 class="fw-bold">Không tìm thấy chuyến xe nào phù hợp!</h5>
            <p class="mb-3 text-muted">Vui lòng thử tìm kiếm với điểm đi hoặc điểm đến khác.</p>
            <a href="index.php" class="btn btn-outline-primary">Xem toàn bộ lịch trình</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($trips as $trip): 
                $available_seats = $trip['total_seats'] - $trip['booked_seats'];
                $is_full = ($available_seats <= 0);
                $percent = ($trip['total_seats'] > 0) ? round(($trip['booked_seats'] / $trip['total_seats']) * 100) : 0;
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card trip-card h-100 p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle badge-type">
                                <i class="bi bi-tag-fill me-1"></i><?php echo htmlspecialchars($trip['type'], ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int)$trip['id']; ?>
                            </span>
                            <?php if ($is_full): ?>
                                <span class="badge bg-danger">Hết chỗ</span>
                            <?php elseif ($available_seats <= 5): ?>
                                <span class="badge bg-warning text-dark">Sắp hết chỗ</span>
                            <?php else: ?>
                                <span class="badge bg-success">Còn vé</span>
                            <?php endif; ?>
                        </div>

                        <h5 class="fw-bold text-dark mb-3">
                            <?php echo htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h5>

                        <!-- Lộ trình -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <span class="station-badge w-100">
                                    <i class="bi bi-record-circle text-success"></i>
                                    <span class="text-truncate"><strong>Đi:</strong> <?php echo htmlspecialchars($trip['from'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="station-badge w-100">
                                    <i class="bi bi-geo-alt-fill text-danger"></i>
                                    <span class="text-truncate"><strong>Đến:</strong> <?php echo htmlspecialchars($trip['to'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            </div>
                        </div>

                        <!-- Thời gian & Giá vé -->
                        <div class="d-flex justify-content-between align-items-center mb-3 text-muted small">
                            <div>
                                <i class="bi bi-clock-fill text-primary"></i> Khởi hành: 
                                <strong class="text-dark fs-6"><?php echo htmlspecialchars($trip['departure_time'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div>
                                Giá vé: <strong class="text-danger fs-6"><?php echo number_format($trip['price'], 0, ',', '.'); ?> đ</strong>
                            </div>
                        </div>

                        <!-- Trạng thái ghế -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Ghế trống: <strong class="<?php echo $is_full ? 'text-danger' : 'text-success'; ?>"><?php echo $available_seats; ?></strong> / <?php echo $trip['total_seats']; ?></span>
                                <span>Đã đặt: <?php echo $trip['booked_seats']; ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar <?php echo $is_full ? 'bg-danger' : 'bg-primary'; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $percent; ?>%" 
                                     aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>

                        <!-- Nút đặt vé -->
                        <div class="mt-auto">
                            <?php if ($is_full): ?>
                                <button class="btn btn-secondary w-100 disabled" disabled>
                                    <i class="bi bi-x-circle"></i> Đã hết vé
                                </button>
                            <?php else: ?>
                                <a href="book_ticket.php?trip_id=<?php echo (int)$trip['id']; ?>" class="btn btn-outline-primary w-100 fw-semibold">
                                    <i class="bi bi-ticket-perforated-fill me-1"></i> Đặt vé ngay
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/components/footer.php'; ?>
