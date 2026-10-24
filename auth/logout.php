<?php
// logout.php - Đăng xuất tài khoản và dọn dẹp Session/Cookie
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Hủy bỏ toàn bộ biến trong mảng Session
$_SESSION = [];

// 2. Xóa Cookie Session khỏi trình duyệt của người dùng nếu có
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Phá hủy Session trên Server
session_destroy();

// 4. Khởi tạo lại session mới để gửi Flash message thông báo đăng xuất
session_start();
$_SESSION['auth_success'] = 'Bạn đã đăng xuất khỏi hệ thống thành công.';

// Điều hướng về trang đăng nhập
header('Location: login.php');
exit;
