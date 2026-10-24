<?php
// components/navbar.php - Thanh điều hướng dùng chung cho các trang
if (function_exists('current_user')) {
    $nav_user = current_user();
} else {
    $nav_user = null;
}
?>
<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand navbar-dark top-navbar py-2">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="index.php" class="navbar-brand text-white d-flex align-items-center gap-2 mb-0 text-decoration-none">
            <i class="bi bi-bus-front-fill text-warning fs-5"></i> <span>TransitHub</span>
        </a>
        <span class="navbar-text text-white-50 d-none d-md-inline small">
            <i class="bi bi-shield-lock-fill text-warning me-1"></i> Hệ thống xác thực & phân quyền
        </span>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <?php if ($nav_user): ?>
                <span class="text-white me-2 small">
                    <i class="bi bi-person-circle text-warning"></i> Chào, <strong><?php echo htmlspecialchars($nav_user['fullname'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge <?php echo ($nav_user['role'] === 'admin') ? 'bg-danger' : 'bg-info text-dark'; ?> ms-1">
                        <?php echo strtoupper($nav_user['role']); ?>
                    </span>
                </span>
                <?php if ($nav_user['role'] === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-speedometer2"></i> Quản Trị
                    </a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-sm btn-outline-light" data-confirm="Bạn có chắc chắn muốn đăng xuất không?">
                    <i class="bi bi-box-arrow-right"></i> Đăng xuất
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-sm btn-light fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Đăng Nhập
                </a>
                <a href="register_account.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-person-plus me-1"></i> Đăng Ký
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>
