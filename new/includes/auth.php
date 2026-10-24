<?php
// includes/auth.php - Middleware kiểm tra quyền truy cập (Authentication & Authorization)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Kiểm tra xem người dùng đã đăng nhập chưa
 * @return bool
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Kiểm tra xem người dùng hiện tại có quyền Quản trị viên (admin) hay không
 * @return bool
 */
function is_admin(): bool {
    return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Lấy thông tin tài khoản hiện đang đăng nhập
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'fullname' => $_SESSION['fullname'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
        'role'     => $_SESSION['role'] ?? 'user',
    ];
}

/**
 * Middleware Bảo vệ: Bắt buộc người dùng phải đăng nhập
 * Dùng cho các trang như: book_ticket.php
 * @param string|null $redirect_url URL muốn quay lại sau khi đăng nhập thành công
 */
function require_login(?string $redirect_url = null): void {
    if (!is_logged_in()) {
        $_SESSION['auth_warning'] = 'Vui lòng đăng nhập để tiếp tục đặt vé.';
        $target = 'login.php';
        if ($redirect_url) {
            $target .= '?redirect=' . urlencode($redirect_url);
        }
        header("Location: {$target}");
        exit;
    }
}

/**
 * Middleware Bảo vệ: Bắt buộc phải có quyền Admin
 * Dùng cho các trang quản trị như: admin_dashboard.php
 */
function require_admin(): void {
    if (!is_logged_in()) {
        $_SESSION['auth_warning'] = 'Vui lòng đăng nhập bằng tài khoản Quản trị viên để tiếp tục.';
        header('Location: login.php?redirect=' . urlencode('admin_dashboard.php'));
        exit;
    }

    if (!is_admin()) {
        // Trả về mã lỗi HTTP 403 Forbidden nếu user bình thường cố tình truy cập
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 Access Denied - Truy cập bị từ chối</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        </head>
        <body class="bg-light d-flex align-items-center min-vh-100">
            <div class="container text-center py-5">
                <div class="card shadow-sm mx-auto p-4 p-md-5 border-0 rounded-4" style="max-width: 550px;">
                    <div class="text-danger mb-3">
                        <i class="bi bi-shield-slash-fill display-1"></i>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-2">403 - Quyền Truy Cập Bị Từ Chối</h1>
                    <p class="text-muted mb-4">
                        Tài khoản của bạn (<strong><?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>) là tài khoản hành khách và không có quyền truy cập trang quản trị này.
                    </p>
                    <div class="d-flex justify-content-center gap-2">
                        <a href="index.php" class="btn btn-primary px-4">
                            <i class="bi bi-house-door me-1"></i> Về Trang chủ
                        </a>
                        <a href="logout.php" class="btn btn-outline-danger px-4">
                            <i class="bi bi-box-arrow-right me-1"></i> Đăng xuất
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
