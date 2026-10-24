<?php
// admin_dashboard.php - Bảng điều khiển Quản trị viên (Chỉ Admin mới có quyền truy cập)
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/auth.php';

// Áp dụng Middleware bảo vệ trang Admin
require_admin();

$admin_user = current_user();
$trips = get_all_trips();

// Tính toán các chỉ số thống kê
$total_trips = count($trips);
$total_all_seats = 0;
$total_booked_seats = 0;
$estimated_revenue = 0;

foreach ($trips as $t) {
    $total_all_seats += $t['total_seats'];
    $total_booked_seats += $t['booked_seats'];
    $estimated_revenue += ($t['booked_seats'] * $t['price']);
}

$total_available_seats = $total_all_seats - $total_booked_seats;

$page_title = 'Admin Dashboard - TransitHub';
require_once __DIR__ . '/components/header.php';
?>

<!-- Admin Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark admin-nav py-3">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="admin_dashboard.php">
            <i class="bi bi-speedometer2 text-warning fs-4"></i> Trang Quản Trị Hệ Thống
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-light small d-none d-md-inline">
                <i class="bi bi-person-circle text-warning me-1"></i> Xin chào, <strong><?php echo htmlspecialchars($admin_user['fullname'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="badge bg-danger ms-1">Admin</span>
            </span>
            <a href="index.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-eye"></i> Xem Trang chủ
            </a>
            <a href="logout.php" class="btn btn-sm btn-danger" data-confirm="Bạn có chắc chắn muốn đăng xuất tài khoản quản trị?">
                <i class="bi bi-box-arrow-right"></i> Đăng xuất
            </a>
        </div>
    </div>
</nav>

<main class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">Tổng Quan Lịch Trình & Tình Trạng Vé</h3>
            <p class="text-muted mb-0">Theo dõi thời gian thực số lượng ghế và tình trạng các chuyến vận tải</p>
        </div>
        <div>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                <i class="bi bi-circle-fill me-1 small"></i> Hệ thống hoạt động bình thường
            </span>
        </div>
    </div>

    <!-- 4 Thẻ Thống Kê Nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Tổng Chuyến Xe</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo $total_trips; ?></h3>
                    </div>
                    <div class="bg-primary-subtle p-3 rounded-circle text-primary">
                        <i class="bi bi-bus-front fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Tổng Số Ghế</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo $total_all_seats; ?></h3>
                    </div>
                    <div class="bg-info-subtle p-3 rounded-circle text-info">
                        <i class="bi bi-grid-3x3-gap fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Vé Đã Bán</div>
                        <h3 class="fw-bold mb-0 text-success"><?php echo $total_booked_seats; ?></h3>
                    </div>
                    <div class="bg-success-subtle p-3 rounded-circle text-success">
                        <i class="bi bi-ticket-perforated fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Doanh Thu Tạm Tính</div>
                        <h3 class="fw-bold mb-0 text-danger"><?php echo number_format($estimated_revenue, 0, ',', '.'); ?>đ</h3>
                    </div>
                    <div class="bg-danger-subtle p-3 rounded-circle text-danger">
                        <i class="bi bi-cash-stack fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bảng Chi Tiết Tình Trạng Các Tuyến -->
    <div class="card table-card p-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="bi bi-table text-primary me-1"></i> Danh Sách Chi Tiết Tình Trạng Chuyến Xe
        </h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Mã</th>
                        <th>Tên Tuyến</th>
                        <th>Loại</th>
                        <th>Lộ Trình</th>
                        <th>Giờ Xuất Phát</th>
                        <th>Giá Vé</th>
                        <th>Tỉ Lệ Đặt Chỗ</th>
                        <th>Còn Lại</th>
                        <th>Trạng Thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trips as $t): 
                        $avail = $t['total_seats'] - $t['booked_seats'];
                        $pct = ($t['total_seats'] > 0) ? round(($t['booked_seats'] / $t['total_seats']) * 100) : 0;
                    ?>
                        <tr>
                            <td class="fw-bold">#<?php echo (int)$t['id']; ?></td>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($t['type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($t['from'] . ' ➔ ' . $t['to'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($t['departure_time'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format($t['price'], 0, ',', '.'); ?> đ</td>
                            <td style="min-width: 130px;">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><?php echo $t['booked_seats']; ?>/<?php echo $t['total_seats']; ?></span>
                                    <span><?php echo $pct; ?>%</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar <?php echo ($pct >= 100) ? 'bg-danger' : 'bg-success'; ?>" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </td>
                            <td>
                                <strong class="<?php echo ($avail <= 0) ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $avail; ?> ghế
                                </strong>
                            </td>
                            <td>
                                <?php if ($avail <= 0): ?>
                                    <span class="badge bg-danger">Hết Vé</span>
                                <?php elseif ($avail <= 5): ?>
                                    <span class="badge bg-warning text-dark">Gần Đầy</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Đang Mở Bán</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/components/footer.php'; ?>
