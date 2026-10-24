<?php
// register_account.php - Đăng ký tài khoản hành khách
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Nếu người dùng đã đăng nhập rồi thì chuyển hướng
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$fullname = '';
$username = '';
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname         = trim($_POST['fullname'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Kiểm tra các trường không được để trống
    if ($fullname === '') {
        $errors['fullname'] = 'Vui lòng nhập họ và tên.';
    }

    if ($username === '') {
        $errors['username'] = 'Vui lòng nhập tên đăng nhập.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors['username'] = 'Tên đăng nhập từ 3-30 ký tự, chỉ gồm chữ, số và dấu gạch dưới (_).';
    }

    if ($email === '') {
        $errors['email'] = 'Vui lòng nhập địa chỉ email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Địa chỉ email không hợp lệ.';
    }

    if ($password === '') {
        $errors['password'] = 'Vui lòng nhập mật khẩu.';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Mật khẩu phải có độ dài tối thiểu từ 6 ký tự.';
    }

    // 2. Kiểm tra Mật khẩu và Nhập lại mật khẩu phải khớp nhau
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Mật khẩu xác nhận không khớp.';
    }

    // 3. Kiểm tra Tên đăng nhập hoặc Email đã tồn tại trong CSDL chưa
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $email]);
            $existing_user = $stmt->fetch();

            if ($existing_user) {
                if (strtolower($existing_user['username']) === strtolower($username)) {
                    $errors['username'] = 'Tên đăng nhập này đã được sử dụng. Vui lòng chọn tên khác.';
                }
                if (strtolower($existing_user['email']) === strtolower($email)) {
                    $errors['email'] = 'Địa chỉ email này đã được đăng ký tài khoản.';
                }
            }
        } catch (PDOException $e) {
            $errors['system'] = 'Lỗi truy vấn cơ sở dữ liệu: ' . $e->getMessage();
        }
    }

    // 4. Băm mật khẩu và lưu vào CSDL nếu không có lỗi
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'user'; // Mặc định tài khoản mới luôn là 'user'

        try {
            $insert_stmt = $pdo->prepare('INSERT INTO users (username, password, fullname, email, role) VALUES (?, ?, ?, ?, ?)');
            $insert_stmt->execute([$username, $hashed_password, $fullname, $email, $role]);

            // Thiết lập thông báo thành công vào Session và chuyển sang login.php
            $_SESSION['auth_success'] = 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay bây giờ.';
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            $errors['system'] = 'Không thể đăng ký tài khoản: ' . $e->getMessage();
        }
    }
}

$page_title = 'Đăng Ký Tài Khoản - TransitHub';
require_once __DIR__ . '/components/header.php';
?>

<main class="d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">

                <!-- Logo & Link trang chủ -->
                <div class="text-center mb-4">
                    <a href="index.php" class="text-decoration-none d-inline-flex align-items-center gap-2 fs-4 fw-bold text-primary">
                        <i class="bi bi-bus-front-fill fs-2"></i> TransitHub
                    </a>
                    <p class="text-muted mt-1">Tạo tài khoản hành khách để tra cứu và đặt vé nhanh chóng</p>
                </div>

                <div class="card auth-card p-4 p-md-5">
                    <h3 class="fw-bold text-dark text-center mb-4">Đăng Ký Tài Khoản</h3>

                    <?php if (isset($errors['system'])): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
                            <div><?php echo htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="register_account.php" method="POST" novalidate>
                        <!-- Họ và tên -->
                        <div class="mb-3">
                            <label for="fullname" class="form-label fw-bold">Họ và tên <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-secondary"></i></span>
                                <input type="text" class="form-control border-start-0 <?php echo isset($errors['fullname']) ? 'is-invalid' : ''; ?>" 
                                       id="fullname" name="fullname" placeholder="Nguyễn Văn A" 
                                       value="<?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>" required>
                                <?php if (isset($errors['fullname'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['fullname'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tên đăng nhập -->
                        <div class="mb-3">
                            <label for="username" class="form-label fw-bold">Tên đăng nhập <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person-badge text-secondary"></i></span>
                                <input type="text" class="form-control border-start-0 <?php echo isset($errors['username']) ? 'is-invalid' : ''; ?>" 
                                       id="username" name="username" placeholder="Ví dụ: nguyenvana123" 
                                       value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" required>
                                <?php if (isset($errors['username'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="mb-3">
                            <label for="email" class="form-label fw-bold">Địa chỉ Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-secondary"></i></span>
                                <input type="email" class="form-control border-start-0 <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                       id="email" name="email" placeholder="name@example.com" 
                                       value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                                <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mật khẩu -->
                        <div class="mb-3">
                            <label for="password" class="form-label fw-bold">Mật khẩu (Tối thiểu 6 ký tự) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-secondary"></i></span>
                                <input type="password" class="form-control border-start-0 <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                       id="password" name="password" placeholder="••••••••" required>
                                <?php if (isset($errors['password'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Nhập lại mật khẩu -->
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-bold">Nhập lại mật khẩu <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-shield-check text-secondary"></i></span>
                                <input type="password" class="form-control border-start-0 <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                       id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                                <?php if (isset($errors['confirm_password'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Nút Submit -->
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-3">
                            <i class="bi bi-person-plus-fill me-1"></i> Đăng Ký Tài Khoản
                        </button>

                        <div class="text-center text-muted small">
                            Đã có tài khoản? <a href="login.php" class="text-primary fw-bold text-decoration-none">Đăng nhập ngay</a>
                        </div>
                    </form>
                </div>

                <div class="text-center mt-4 text-muted small">
                    <a href="index.php" class="text-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> Quay lại trang chủ</a>
                </div>

            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/components/footer.php'; ?>
