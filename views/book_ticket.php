<?php
// book_ticket.php - Form đăng ký đặt vé và cơ chế phục hồi bản nháp từ Session/Cookie
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/auth.php';

// 1. Kiểm tra tham số chuyến xe
$trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;

// BẢO VỆ LUỒNG TRUY CẬP: Bắt buộc người dùng phải đăng nhập mới được vào trang đặt vé
// Nếu chưa đăng nhập, tự động chuyển hướng về login.php kèm thông báo
require_login("book_ticket.php?trip_id={$trip_id}");

$trip = get_trip_by_id($trip_id);

// Nếu không tìm thấy chuyến xe hoặc không truyền trip_id, điều hướng về trang chủ
if (!$trip) {
    header('Location: index.php');
    exit;
}

$available_seats = $trip['total_seats'] - $trip['booked_seats'];

// 2. Cơ chế phục hồi dữ liệu bản nháp (Drafting): Ưu tiên Session -> Cookie -> Mặc định
$draft_source = ''; // Ghi nhận nguồn phục hồi dữ liệu để thông báo cho người dùng
$form_data = [
    'fullname' => '',
    'phone' => '',
    'seats' => 1
];

// Kiểm tra Session trước (người dùng vừa submit lỗi hoặc F5 trang)
if (!empty($_SESSION['booking_draft'])) {
    $form_data['fullname'] = $_SESSION['booking_draft']['fullname'] ?? '';
    $form_data['phone']    = $_SESSION['booking_draft']['phone'] ?? '';
    $form_data['seats']    = $_SESSION['booking_draft']['seats'] ?? 1;
    $draft_source = 'session';
}
// Nếu Session không có, kiểm tra Cookie lưu trong 24h (khi người dùng quay lại sau)
elseif (!empty($_COOKIE['booking_draft'])) {
    $cookie_data = json_decode($_COOKIE['booking_draft'], true);
    if (is_array($cookie_data)) {
        $form_data['fullname'] = $cookie_data['fullname'] ?? '';
        $form_data['phone']    = $cookie_data['phone'] ?? '';
        $form_data['seats']    = $cookie_data['seats'] ?? 1;
        $draft_source = 'cookie';
    }
}

// Nếu chưa có trong bản nháp, tự động điền sẵn Họ tên từ tài khoản đang đăng nhập
if ($form_data['fullname'] === '' && !empty($_SESSION['fullname'])) {
    $form_data['fullname'] = $_SESSION['fullname'];
}

// 3. Lấy và xóa flash messages (thông báo lỗi và thông báo trạng thái)
$errors = $_SESSION['booking_errors'] ?? [];
unset($_SESSION['booking_errors']); // Flash message: chỉ hiển thị 1 lần

$flash_success = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt Vé - <?php echo htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .booking-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
            background: #fff;
        }
        .trip-header-summary {
            background: linear-gradient(135deg, #0d6efd 0%, #084298 100%);
            color: #fff;
            padding: 24px;
        }
        .form-label {
            font-weight: 600;
            color: #333;
        }
    </style>
</head>
<body class="py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">

                <!-- Nút quay lại -->
                <div class="mb-3">
                    <a href="index.php" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1">
                        <i class="bi bi-arrow-left"></i> Quay lại danh sách lịch trình
                    </a>
                </div>

                <div class="card booking-card">
                    <!-- HEADER TÓM TẮT THÔNG TIN CHUYẾN XE (Rõ tên tuyến và giờ khởi hành) -->
                    <div class="trip-header-summary">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge bg-light text-primary fw-bold">
                                <?php echo htmlspecialchars($trip['type'], ENT_QUOTES, 'UTF-8'); ?> - Tuyến #<?php echo (int)$trip['id']; ?>
                            </span>
                            <span class="badge bg-warning text-dark fw-bold">
                                Còn <?php echo $available_seats; ?> ghế trống
                            </span>
                        </div>
                        <h4 class="fw-bold mb-2"><?php echo htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="d-flex flex-wrap gap-3 small text-white-50">
                            <div><i class="bi bi-clock-fill text-warning me-1"></i> Giờ khởi hành: <strong class="text-white"><?php echo htmlspecialchars($trip['departure_time'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><i class="bi bi-geo-alt-fill text-danger me-1"></i> Lộ trình: <span class="text-white"><?php echo htmlspecialchars($trip['from'] . ' ➔ ' . $trip['to'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                            <div><i class="bi bi-cash me-1"></i> Giá: <span class="text-white"><?php echo number_format($trip['price'], 0, ',', '.'); ?> đ/vé</span></div>
                        </div>
                    </div>

                    <div class="card-body p-4 p-md-5">

                        <!-- Thông báo nguồn bản nháp (nếu có) -->
                        <?php if ($draft_source === 'cookie'): ?>
                            <div class="alert alert-info alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                                <i class="bi bi-info-circle-fill fs-5 me-2"></i>
                                <div>
                                    Đã tự động khôi phục thông tin từ <strong>bản nháp Cookie (lưu trong 24h)</strong> bạn đã lưu trước đó!
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Thông báo Flash xanh thành công (ví dụ: đã lưu cookie) -->
                        <?php if (!empty($flash_success)): ?>
                            <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
                                <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                                <div><?php echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Thông báo Flash đỏ khi có lỗi Validation -->
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger mb-4" role="alert">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2 text-danger"></i>
                                    <strong class="text-danger">Không thể hoàn tất đặt vé. Vui lòng kiểm tra các lỗi sau:</strong>
                                </div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- FORM ĐẶT VÉ (POST METHOD) -->
                        <form action="process_booking.php" method="POST" id="bookingForm" novalidate>
                            <input type="hidden" name="trip_id" value="<?php echo (int)$trip['id']; ?>">

                            <!-- 1. Họ và tên hành khách -->
                            <div class="mb-3">
                                <label for="fullname" class="form-label">
                                    <i class="bi bi-person-fill text-primary"></i> Họ và tên hành khách <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control form-control-lg <?php echo isset($errors['fullname']) ? 'is-invalid' : ''; ?>" 
                                       id="fullname" 
                                       name="fullname" 
                                       placeholder="Ví dụ: Nguyễn Văn A" 
                                       value="<?php echo htmlspecialchars($form_data['fullname'], ENT_QUOTES, 'UTF-8'); ?>"
                                       required>
                                <?php if (isset($errors['fullname'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['fullname'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- 2. Số điện thoại liên hệ -->
                            <div class="mb-3">
                                <label for="phone" class="form-label">
                                    <i class="bi bi-telephone-fill text-primary"></i> Số điện thoại liên hệ (10 chữ số) <span class="text-danger">*</span>
                                </label>
                                <input type="tel" 
                                       class="form-control form-control-lg <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                                       id="phone" 
                                       name="phone" 
                                       placeholder="Ví dụ: 0912345678" 
                                       value="<?php echo htmlspecialchars($form_data['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                       required>
                                <?php if (isset($errors['phone'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- 3. Số lượng vé đặt -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="seats" class="form-label mb-0">
                                        <i class="bi bi-ticket-perforated-fill text-primary"></i> Số lượng vé cần đặt <span class="text-danger">*</span>
                                    </label>
                                    <span class="text-muted small">Tối đa có thể đặt: <strong><?php echo $available_seats; ?></strong> vé</span>
                                </div>
                                <input type="number" 
                                       class="form-control form-control-lg <?php echo isset($errors['seats']) ? 'is-invalid' : ''; ?>" 
                                       id="seats" 
                                       name="seats" 
                                       min="1" 
                                       max="<?php echo max(1, $available_seats); ?>"
                                       value="<?php echo (int)$form_data['seats']; ?>"
                                       required>
                                <?php if (isset($errors['seats'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['seats'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Các nút thao tác -->
                            <div class="row g-2">
                                <div class="col-sm-7">
                                    <!-- Nút submit Đặt vé -->
                                    <button type="submit" name="action" value="book" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm" <?php echo ($available_seats <= 0) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-check2-circle me-1"></i> Xác nhận Đặt vé
                                    </button>
                                </div>
                                <div class="col-sm-5">
                                    <!-- Nút lưu nháp vào Cookie 24h -->
                                    <button type="submit" name="action" value="save_draft" class="btn btn-outline-secondary btn-lg w-100 fw-semibold" title="Lưu thông tin hiện tại vào Cookie trong 24h">
                                        <i class="bi bi-bookmark-plus me-1"></i> Lưu nháp điền sau
                                    </button>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>

                <!-- Chú thích hỗ trợ người dùng -->
                <div class="mt-4 p-3 bg-white rounded-3 border text-muted small">
                    <p class="mb-1"><i class="bi bi-shield-check text-success"></i> <strong>Tính năng lưu nháp thông minh:</strong></p>
                    <ul class="mb-0 ps-3">
                        <li>Dữ liệu bạn đang gõ sẽ được bảo toàn qua <strong>Session</strong> nếu bạn vô tình F5 làm mới trang.</li>
                        <li>Nhấn <strong>"Lưu nháp điền sau"</strong> để lưu thông tin vào <strong>Cookie (24 giờ)</strong>, lần sau mở lại trang bạn không cần phải gõ lại từ đầu.</li>
                    </ul>
                </div>

            </div>
        </div>
    </div>

    <!-- Script tự động cập nhật Session ngầm khi nhập dữ liệu (nâng cao trải nghiệm khi F5) -->
    <script>
        // Lắng nghe sự kiện input để lưu tạm vào sessionStorage trình duyệt phòng ngừa rủi ro
        const inputs = ['fullname', 'phone', 'seats'];
        inputs.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', () => {
                    sessionStorage.setItem('temp_' + id, el.value);
                });
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
