<?php
// login.php - Đăng nhập tài khoản & Phân luồng theo Role (Admin / User)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Nếu đã đăng nhập trước đó thì phân luồng trực tiếp
if (is_logged_in()) {
    if (is_admin()) {
        header('Location: ../admin/gtfs.php');
    } else {
        header('Location: ../index.php');
    }
    exit;
}

$error = '';
$username = '';
$redirect_target = $_GET['redirect'] ?? '';

// Nhận các Flash message từ Session
$auth_warning = $_SESSION['auth_warning'] ?? '';
unset($_SESSION['auth_warning']);

$auth_success = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username        = trim($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';
    $redirect_target = trim($_POST['redirect'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ Tên đăng nhập và Mật khẩu.';
    } else {
        try {
            // 1. Truy vấn tài khoản theo username dùng Prepared Statement
            $stmt = $pdo->prepare('SELECT id, username, password, fullname, email, role FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // 2. Sử dụng password_verify() để đối chiếu mật khẩu người dùng nhập với mật khẩu đã mã hóa trong CSDL
            if ($user && password_verify($password, $user['password'])) {
                // Đăng nhập thành công -> Tạo Session định danh người dùng
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['email']    = $user['email'];
                $_SESSION['role']     = $user['role']; // 'admin' hoặc 'user'

                // 3. Phân luồng điều hướng (Role-based Routing)
                if ($user['role'] === 'admin') {
                    // Nếu là Admin -> Chuyển hướng sang trang Quản lý GTFS
                    header('Location: ../admin/gtfs.php');
                    exit;
                } else {
                    // Nếu là User thường:
                    // Kiểm tra nếu có yêu cầu quay lại trang trước (VD: đang đặt vé ở book_ticket.php)
                    if (!empty($redirect_target) && filter_var($redirect_target, FILTER_VALIDATE_URL) === false && strpos($redirect_target, 'login.php') === false) {
                        if (strpos($redirect_target, '../') === 0) {
                            $dest = $redirect_target;
                        } elseif (strpos($redirect_target, 'admin/') === 0 || strpos($redirect_target, 'views/') === 0 || strpos($redirect_target, 'auth/') === 0) {
                            $dest = '../' . $redirect_target;
                        } elseif (preg_match('/^(saved_queries|next_trip|gtfs_browse|book_ticket|process_booking)\.php/', $redirect_target)) {
                            $dest = '../views/' . $redirect_target;
                        } elseif (preg_match('/^(index|export)\.php/', $redirect_target)) {
                            $dest = '../' . $redirect_target;
                        } else {
                            $dest = '../' . ltrim($redirect_target, '/');
                        }
                        header("Location: {$dest}");
                        exit;
                    }
                    // Mặc định chuyển về trang chủ tra cứu và đặt vé
                    header('Location: ../index.php');
                    exit;
                }
            } else {
                $error = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - Hệ Thống Đặt Vé</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #e0f2fe 0%, #f1f5f9 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .auth-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            background: #fff;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.9rem;
            color: #374151;
        }
        .demo-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body class="d-flex align-items-center py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">

                <!-- Header Logo -->
                <div class="text-center mb-4">
                    <a href="../index.php" class="text-decoration-none d-inline-flex align-items-center gap-2 fs-4 fw-bold text-primary">
                        <i class="bi bi-bus-front-fill fs-2"></i> Hệ Thống Đặt Vé Công Cộng
                    </a>
                    <p class="text-muted mt-1">Vui lòng đăng nhập để tiếp tục sử dụng hệ thống</p>
                </div>

                <div class="card auth-card p-4 p-md-5">
                    <h3 class="fw-bold text-dark text-center mb-4">Đăng Nhập</h3>

                    <!-- Cảnh báo bắt buộc đăng nhập (Ví dụ khi click Đặt vé) -->
                    <?php if (!empty($auth_warning)): ?>
                        <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                            <div><?php echo htmlspecialchars($auth_warning, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Thông báo thành công (Ví dụ vừa đăng ký xong) -->
                    <?php if (!empty($auth_success)): ?>
                        <div class="alert alert-success d-flex align-items-center mb-3" role="alert">
                            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                            <div><?php echo htmlspecialchars($auth_success, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Thông báo lỗi đăng nhập -->
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                            <i class="bi bi-x-circle-fill fs-5 me-2"></i>
                            <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="login.php" method="POST" novalidate>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_target, ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- Username -->
                        <div class="mb-3">
                            <label for="username" class="form-label">Tên đăng nhập</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-secondary"></i></span>
                                <input type="text" class="form-control border-start-0" id="username" name="username" 
                                       placeholder="Nhập username" 
                                       value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="mb-4">
                            <label for="password" class="form-label">Mật khẩu</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-secondary"></i></span>
                                <input type="password" class="form-control border-start-0" id="password" name="password" 
                                       placeholder="Nhập mật khẩu" required>
                            </div>
                        </div>

                        <!-- Submit button -->
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Đăng Nhập
                        </button>

                        <div class="text-center text-muted small">
                            Chưa có tài khoản? <a href="register.php" class="text-primary fw-bold text-decoration-none">Đăng ký ngay</a>
                        </div>
                    </form>

                    <!-- Hộp gợi ý tài khoản mẫu để chấm bài / kiểm thử -->
                    <div class="demo-box p-3 mt-4 text-muted">
                        <strong class="d-block text-dark mb-1"><i class="bi bi-info-circle"></i> Tài khoản thử nghiệm có sẵn:</strong>
                        <div>• <strong>Admin:</strong> <code>admin</code> | Pass: <code>admin123</code> (vào Quản Trị)</div>
                        <div>• <strong>User:</strong> <code>hanhkhach1</code> | Pass: <code>password123</code> (vào Tra cứu)</div>
                        <div class="mt-2 pt-2 border-top small">
                            <a href="reset_password.php" class="text-primary text-decoration-none fw-semibold">
                                🔄 Bấm vào đây để tự động cập nhật lại mật khẩu mẫu
                            </a>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4 text-muted small">
                    <a href="../index.php" class="text-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> Quay lại trang chủ tra cứu</a>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
