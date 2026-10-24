<?php
// reset_password.php - Script 1-click cập nhật lại mật khẩu chuẩn cho Admin và User
require_once __DIR__ . '/config/db.php';

$admin_pass = password_hash('admin123', PASSWORD_BCRYPT);
$user_pass  = password_hash('password123', PASSWORD_BCRYPT);

try {
    // 1. Cập nhật hoặc tạo tài khoản admin
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $up = $pdo->prepare("UPDATE users SET password = ?, role = 'admin' WHERE username = 'admin'");
        $up->execute([$admin_pass]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (username, password, fullname, email, role) VALUES ('admin', ?, 'Quản Trị Viên', 'admin@transit.local', 'admin')");
        $ins->execute([$admin_pass]);
    }

    // 2. Cập nhật hoặc tạo tài khoản hanhkhach1
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'hanhkhach1'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $up = $pdo->prepare("UPDATE users SET password = ?, role = 'user' WHERE username = 'hanhkhach1'");
        $up->execute([$user_pass]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (username, password, fullname, email, role) VALUES ('hanhkhach1', ?, 'Nguyễn Văn A', 'hanhkhach1@example.com', 'user')");
        $ins->execute([$user_pass]);
    }

    $success = true;
} catch (Exception $e) {
    $error = $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cập Nhật Mật Khẩu Thành Công</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
    <div class="container text-center py-5">
        <div class="card p-4 p-md-5 mx-auto shadow-sm border-0 rounded-4" style="max-width: 550px;">
            <?php if ($success): ?>
                <div class="text-success mb-3" style="font-size: 3rem;">✅</div>
                <h3 class="fw-bold text-dark mb-3">Đã Cập Nhật Mật Khẩu Mẫu!</h3>
                <p class="text-muted">Các tài khoản đã được thiết lập mật khẩu chuẩn Bcrypt:</p>
                <div class="bg-light p-3 rounded-3 text-start mb-4 border">
                    <div>• <strong>Admin:</strong> <code>admin</code> | Pass: <strong><code>admin123</code></strong></div>
                    <div>• <strong>User:</strong> <code>hanhkhach1</code> | Pass: <strong><code>password123</code></strong></div>
                </div>
                <a href="login.php" class="btn btn-primary btn-lg fw-bold w-100">
                    👉 Đến Trang Đăng Nhập Ngay
                </a>
            <?php else: ?>
                <div class="text-danger mb-3" style="font-size: 3rem;">⚠️</div>
                <h4 class="fw-bold text-danger mb-2">Lỗi Kết Nối Cơ Sở Dữ Liệu</h4>
                <p class="text-muted"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
