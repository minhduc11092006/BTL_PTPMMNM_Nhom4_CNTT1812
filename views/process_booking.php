<?php
// process_booking.php - Xử lý logic đặt vé, kiểm tra dữ liệu (Validation), quản lý Session & Cookie
require_once __DIR__ . '/../includes/data.php';

// Chỉ chấp nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// 1. Nhận và làm sạch dữ liệu đầu vào (Sanitize Input)
$trip_id   = isset($_POST['trip_id']) ? (int)$_POST['trip_id'] : 0;
$fullname  = isset($_POST['fullname']) ? trim($_POST['fullname']) : '';
$phone     = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$seats     = isset($_POST['seats']) ? (int)$_POST['seats'] : 0;
$action    = isset($_POST['action']) ? trim($_POST['action']) : 'book';

// Lấy thông tin chuyến xe từ mock data
$trip = get_trip_by_id($trip_id);
if (!$trip) {
    header('Location: ../index.php');
    exit;
}

// =========================================================================
// TRƯỜNG HỢP 1: NGƯỜI DÙNG CHỌN "LƯU NHÁP ĐIỀN SAU" (COOKIE 24H & SESSION)
// =========================================================================
if ($action === 'save_draft') {
    $draft_data = [
        'fullname' => $fullname,
        'phone'    => $phone,
        'seats'    => $seats
    ];

    // 1. Lưu bản nháp vào Session (để giữ thông tin khi F5 hoặc chuyển trang)
    $_SESSION['booking_draft'] = $draft_data;

    // 2. Lưu bản nháp vào Cookie có hạn 24 giờ (86400 giây)
    // Dữ liệu được mã hóa JSON để lưu nhiều trường thông tin
    setcookie('booking_draft', json_encode($draft_data), [
        'expires'  => time() + 86400, // 24 giờ kể từ thời điểm bấm nút
        'path'     => '/',
        'httponly' => true,           // Tăng tính bảo mật chống truy cập JS độc hại
        'samesite' => 'Lax'
    ]);

    $_SESSION['flash_message'] = 'Đã lưu bản nháp thành công vào Cookie (hiệu lực trong 24 giờ)!';
    header("Location: book_ticket.php?trip_id={$trip_id}");
    exit;
}

// =========================================================================
// TRƯỜNG HỢP 2: NGƯỜI DÙNG XÁC NHẬN "ĐẶT VÉ" (VALIDATION & BUSINESS RULES)
// =========================================================================

// Luôn cập nhật thông tin đang nhập vào Session nháp để không mất dữ liệu nếu phát sinh lỗi
$_SESSION['booking_draft'] = [
    'fullname' => $fullname,
    'phone'    => $phone,
    'seats'    => $seats
];

$errors = [];

// --- Validate 1: Họ tên không được để trống ---
if ($fullname === '') {
    $errors['fullname'] = 'Vui lòng nhập họ và tên hành khách.';
} elseif (mb_strlen($fullname, 'UTF-8') < 2) {
    $errors['fullname'] = 'Họ và tên hành khách phải có ít nhất 2 ký tự.';
}

// --- Validate 2: Số điện thoại phải đúng định dạng 10 chữ số (đầu số 0) ---
// Regex kiểm tra chuỗi bắt đầu bằng 0 và theo sau là 9 chữ số (tổng cộng 10 số)
if ($phone === '') {
    $errors['phone'] = 'Vui lòng nhập số điện thoại liên hệ.';
} elseif (!preg_match('/^0[0-9]{9}$/', $phone)) {
    $errors['phone'] = 'Số điện thoại không hợp lệ. Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.';
}

// --- Validate 3: Số lượng vé đặt ---
if ($seats <= 0) {
    $errors['seats'] = 'Số lượng vé đặt phải lớn hơn 0.';
} else {
    // --- Validate 4 (Nghiệp vụ): Không vượt quá số ghế còn trống ---
    $available_seats = $trip['total_seats'] - $trip['booked_seats'];
    if ($seats > $available_seats) {
        $errors['seats'] = "Số lượng vé yêu cầu ({$seats} vé) vượt quá số ghế còn trống hiện tại của chuyến ({$available_seats} ghế còn lại).";
    }
}

// =========================================================================
// XỬ LÝ KẾT QUẢ KIỂM TRA
// =========================================================================

// NẾU CÓ LỖI: Chuyển hướng lại trang form, hiển thị thông báo lỗi và giữ lại form data
if (!empty($errors)) {
    $_SESSION['booking_errors'] = $errors;
    header("Location: book_ticket.php?trip_id={$trip_id}");
    exit;
}

// NẾU THÀNH CÔNG:
// 1. Cập nhật số ghế đã đặt của chuyến xe trong Mock Data (Session)
$_SESSION['trips'][$trip_id]['booked_seats'] += $seats;

// 2. XÓA BẢN NHÁP:
// - Xóa Session nháp và Session lỗi
unset($_SESSION['booking_draft']);
unset($_SESSION['booking_errors']);

// - Xóa Cookie bản nháp (set thời gian hết hạn về quá khứ)
if (isset($_COOKIE['booking_draft'])) {
    setcookie('booking_draft', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Tính tổng tiền vé
$total_price = $seats * $trip['price'];
$booking_code = 'TKT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt Vé Thành Công - Hệ Thống Giao Thông Công Cộng</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .success-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.08);
            background: #fff;
            overflow: hidden;
        }
        .success-header {
            background: linear-gradient(135deg, #198754 0%, #157347 100%);
            color: #fff;
            padding: 35px 20px;
        }
        .ticket-detail-box {
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px dashed #ced4da;
            padding: 20px;
        }
    </style>
</head>
<body class="py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">

                <div class="card success-card">
                    <!-- HEADER THÀNH CÔNG MÀU XANH LÁ -->
                    <div class="success-header text-center">
                        <i class="bi bi-check-circle-fill display-3 d-block mb-2"></i>
                        <h2 class="fw-bold mb-1">Đặt Vé Thành Công!</h2>
                        <p class="lead mb-0 text-white-50">Cảm ơn bạn đã sử dụng dịch vụ giao thông công cộng</p>
                    </div>

                    <div class="card-body p-4 p-md-5">

                        <!-- Thông báo xanh nổi bật theo yêu cầu đề bài -->
                        <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
                            <i class="bi bi-shield-fill-check fs-4 me-2"></i>
                            <div>
                                Giao dịch đặt vé đã được xác nhận. Bản nháp trong Session & Cookie đã được xóa sạch.
                            </div>
                        </div>

                        <!-- Bảng chi tiết vé vừa đặt (XSS Protection với htmlspecialchars) -->
                        <div class="ticket-detail-box mb-4">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                <span class="text-muted small">MÃ ĐẶT VÉ</span>
                                <span class="badge bg-dark fs-6 px-3 py-2"><?php echo htmlspecialchars($booking_code, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="text-muted small">Hành khách:</div>
                                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Số điện thoại:</div>
                                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>

                                <div class="col-12"><hr class="my-1"></div>

                                <div class="col-sm-6">
                                    <div class="text-muted small">Tuyến xe:</div>
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Giờ khởi hành:</div>
                                    <div class="fw-bold text-danger"><?php echo htmlspecialchars($trip['departure_time'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>

                                <div class="col-sm-6">
                                    <div class="text-muted small">Lộ trình:</div>
                                    <div><?php echo htmlspecialchars($trip['from'] . ' ➔ ' . $trip['to'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Số lượng vé đã đặt:</div>
                                    <div class="fw-bold text-dark"><?php echo (int)$seats; ?> vé</div>
                                </div>

                                <div class="col-12"><hr class="my-1"></div>

                                <div class="col-12 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-secondary">TỔNG TIỀN THANH TOÁN:</span>
                                    <span class="fs-4 fw-bold text-danger"><?php echo number_format($total_price, 0, ',', '.'); ?> VNĐ</span>
                                </div>
                            </div>
                        </div>

                        <!-- Nút điều hướng -->
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <a href="../index.php" class="btn btn-primary btn-lg flex-grow-1 fw-semibold">
                                <i class="bi bi-house-door-fill me-1"></i> Quay về trang chủ
                            </a>
                            <button onclick="window.print()" class="btn btn-outline-secondary btn-lg fw-semibold">
                                <i class="bi bi-printer-fill me-1"></i> In vé
                            </button>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
