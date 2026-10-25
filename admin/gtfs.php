<?php
// admin/gtfs.php - Quản lý, Nhập, Validate và So sánh các phiên bản lịch trình GTFS
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';
require_once __DIR__ . '/../includes/gtfs_import.php';

// Bắt buộc quyền Admin
require_admin();
$admin_user = current_user();

$success_msg = '';
$error_msg = '';
$active_tab = $_GET['tab'] ?? 'versions';

$gtfs_base_dir = __DIR__ . '/../gtfs_data';

// Xử lý POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. NHẬP GTFS MỚI TỪ FILE ZIP
    if ($action === 'import_gtfs') {
        $version_name = trim($_POST['version_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($version_name === '') {
            $error_msg = 'Vui lòng nhập tên cho phiên bản lịch này.';
            $active_tab = 'import';
        } elseif (!isset($_FILES['gtfs_zip']) || $_FILES['gtfs_zip']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = 'Vui lòng chọn tệp nén GTFS (.zip) hợp lệ để tải lên.';
            $active_tab = 'import';
        } else {
            $fileTmp = $_FILES['gtfs_zip']['tmp_name'];
            $fileName = $_FILES['gtfs_zip']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($ext !== 'zip') {
                $error_msg = 'Định dạng tệp không hợp lệ. Vui lòng tải lên tệp nén đuôi .zip chứa các tệp GTFS (.txt).';
                $active_tab = 'import';
            } else {
                $importer = new GTFSImporter($pdo);
                $res = $importer->importZip($fileTmp, $version_name, $admin_user['id'], $notes);

                if ($res['success']) {
                    $success_msg = "Đã nhập và kiểm tra dữ liệu thành công cho phiên bản <strong>" . htmlspecialchars($version_name) . "</strong>! Bản lịch này hợp lệ và sẵn sàng để kích hoạt sử dụng.";
                    $active_tab = 'versions';
                } else {
                    $errCount = count($res['errors']);
                    $error_msg = "Phát hiện {$errCount} lỗi kiểm tra trong bộ dữ liệu! Phiên bản này đã được đánh dấu là <strong>Không hợp lệ (Invalid)</strong>. Bản lịch đang sử dụng hiện tại <strong>hoàn toàn không bị ảnh hưởng</strong>.";
                    $active_tab = 'errors';
                    $_GET['view_errors'] = $res['version_id'];
                }
            }
        }
    }

    // 1b. NHẬP GTFS TỪ THƯ MỤC CÓ SẴN TRONG gtfs_data/
    elseif ($action === 'import_folder') {
        $folder_name = trim($_POST['folder_name'] ?? '');
        $version_name = trim($_POST['version_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $folder_path = '';
        if ($folder_name !== '') {
            if (is_dir($gtfs_base_dir . '/' . $folder_name)) {
                $folder_path = $gtfs_base_dir . '/' . $folder_name;
            } elseif (is_dir(__DIR__ . '/../' . $folder_name)) {
                $folder_path = __DIR__ . '/../' . $folder_name;
            }
        }

        if ($version_name === '') {
            $error_msg = 'Vui lòng nhập tên cho phiên bản lịch này.';
            $active_tab = 'import';
        } elseif ($folder_path === '' || !is_dir($folder_path)) {
            $error_msg = 'Thư mục GTFS được chọn không tồn tại hoặc không hợp lệ.';
            $active_tab = 'import';
        } else {
            $importer = new GTFSImporter($pdo);
            $res = $importer->importDirectory($folder_path, $version_name, $admin_user['id'], $notes);

            if ($res['success']) {
                $success_msg = "Đã nạp và kiểm tra thành công dữ liệu GTFS từ thư mục <code>" . htmlspecialchars($folder_name) . "</code> cho phiên bản <strong>" . htmlspecialchars($version_name) . "</strong>! Bản lịch đã hợp lệ và sẵn sàng để kích hoạt.";
                $active_tab = 'versions';
            } else {
                $errCount = count($res['errors']);
                $error_msg = "Phát hiện {$errCount} lỗi kiểm tra trong thư mục GTFS này! Phiên bản đã được đánh dấu là <strong>Không hợp lệ (Invalid)</strong>. Bản lịch đang sử dụng hiện tại <strong>hoàn toàn không bị ảnh hưởng</strong>.";
                $active_tab = 'errors';
                $_GET['view_errors'] = $res['version_id'];
            }
        }
    }

    // 2. KÍCH HOẠT PHIÊN BẢN LỊCH
    elseif ($action === 'activate_version') {
        $version_id = (int)($_POST['version_id'] ?? 0);
        if (activate_feed_version($version_id)) {
            $success_msg = 'Đã kích hoạt phiên bản lịch trình thành công! Hệ thống tra cứu đã chuyển sang sử dụng bản lịch mới.';
        } else {
            $error_msg = 'Không thể kích hoạt phiên bản này (chỉ kích hoạt được bản hợp lệ không có lỗi).';
        }
        $active_tab = 'versions';
    }
}

// Tự động quét các thư mục GTFS có sẵn trong gtfs_data/ hoặc thư mục dự án
$detected_gtfs_dirs = [];
$scan_dirs = [$gtfs_base_dir, __DIR__ . '/..'];
foreach ($scan_dirs as $sdir) {
    if (is_dir($sdir)) {
        $project_items = scandir($sdir);
        foreach ($project_items as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($sdir . '/' . $item)) {
                if (file_exists($sdir . '/' . $item . '/routes.txt')) {
                    if (!in_array($item, $detected_gtfs_dirs, true)) {
                        $detected_gtfs_dirs[] = $item;
                    }
                }
            }
        }
    }
}

// Lấy danh sách phiên bản
$all_versions = get_all_feed_versions();
$active_version = get_active_feed_version();

// Xử lý xem lỗi của 1 phiên bản
$selected_error_version_id = isset($_GET['view_errors']) ? (int)$_GET['view_errors'] : null;
$version_errors = [];
$error_version_info = null;
if ($selected_error_version_id) {
    $version_errors = get_validation_errors($selected_error_version_id);
    $error_version_info = get_feed_version($selected_error_version_id);
}

// Xử lý So sánh hai phiên bản
$compare_v1 = isset($_GET['v1']) ? (int)$_GET['v1'] : null;
$compare_v2 = isset($_GET['v2']) ? (int)$_GET['v2'] : null;
$comparison_result = null;
if ($compare_v1 && $compare_v2 && $compare_v1 !== $compare_v2) {
    $comparison_result = compare_versions($compare_v1, $compare_v2);
    $v1_info = get_feed_version($compare_v1);
    $v2_info = get_feed_version($compare_v2);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Trị Lịch Trình GTFS Tĩnh - Hệ Thống Vận Tải</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .admin-nav {
            background-color: #1e293b;
        }
        .main-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #fff;
        }
        .nav-tabs .nav-link {
            font-weight: 600;
            color: #64748b;
            border: none;
            padding: 12px 20px;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }
    </style>
</head>
<body>

    <!-- Admin Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark admin-nav py-3">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="gtfs.php">
                <i class="bi bi-file-earmark-zip-fill text-warning fs-4"></i> Quản Trị Bộ Lịch GTFS Tĩnh
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="dashboard.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-speedometer2"></i> Dashboard Thống kê
                </a>
                <a href="../index.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-eye"></i> Xem Trang Tra Cứu
                </a>
                <a href="../auth/logout.php" class="btn btn-sm btn-danger">
                    <i class="bi bi-box-arrow-right"></i> Đăng xuất
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">

        <!-- Thông báo Trạng thái Phiên bản Đang Sử Dụng Hiện Tại -->
        <div class="card main-card mb-4 border-start border-4 border-primary">
            <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary text-white p-3 rounded-circle">
                        <i class="bi bi-broadcast fs-4"></i>
                    </div>
                    <div>
                        <div class="text-muted small">PHIÊN BẢN LỊCH ĐANG ÁP DỤNG TRA CỨU HIỆN TẠI:</div>
                        <h5 class="fw-bold text-dark mb-0">
                            <?php if ($active_version): ?>
                                <span class="badge bg-success me-2">ACTIVE</span>
                                <?php echo htmlspecialchars($active_version['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="text-muted small fw-normal ms-2">(Tải lên: <?php echo date('d/m/Y H:i', strtotime($active_version['uploaded_at'])); ?>)</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark me-2">CHƯA CÓ</span>
                                <span class="text-danger fw-semibold">Chưa có phiên bản nào được kích hoạt. Vui lòng nhập hoặc chọn 1 bản hợp lệ!</span>
                            <?php endif; ?>
                        </h5>
                    </div>
                </div>
                <div>
                    <a href="../index.php" target="_blank" class="btn btn-sm btn-outline-primary fw-semibold">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Kiểm tra trên trang Tra cứu
                    </a>
                </div>
            </div>
        </div>

        <!-- Alert messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-check-circle-fill fs-4 me-2"></i>
                <div><?php echo $success_msg; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
                <div><?php echo $error_msg; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Nav tabs -->
        <ul class="nav nav-tabs mb-4" id="gtfsTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo ($active_tab === 'versions') ? 'active' : ''; ?>" href="gtfs.php?tab=versions">
                    <i class="bi bi-layers me-1"></i> Danh Sách Phiên Bản (<?php echo count($all_versions); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($active_tab === 'import') ? 'active' : ''; ?>" href="gtfs.php?tab=import">
                    <i class="bi bi-cloud-arrow-up-fill me-1 text-primary"></i> Nhập Bộ GTFS Mới
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($active_tab === 'compare') ? 'active' : ''; ?>" href="gtfs.php?tab=compare">
                    <i class="bi bi-arrow-left-right me-1 text-info"></i> So Sánh Hai Bản Lịch
                </a>
            </li>
            <?php if ($selected_error_version_id || $active_tab === 'errors'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($active_tab === 'errors') ? 'active' : ''; ?>" href="gtfs.php?tab=errors<?php echo $selected_error_version_id ? '&view_errors='.$selected_error_version_id : ''; ?>">
                        <i class="bi bi-shield-x me-1 text-danger"></i> Chi Tiết Báo Cáo Lỗi
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <!-- TAB CONTENT -->

        <!-- TAB 1: DANH SÁCH CÁC PHIÊN BẢN -->
        <?php if ($active_tab === 'versions'): ?>
            <div class="card main-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-archive-fill text-primary me-2"></i>Các Phiên Bản Lịch Trình Trong Hệ Thống
                    </h5>
                    <a href="gtfs.php?tab=import" class="btn btn-primary btn-sm fw-semibold">
                        <i class="bi bi-plus-circle me-1"></i> Nhập Bản Lịch Mới (.zip)
                    </a>
                </div>

                <?php if (empty($all_versions)): ?>
                    <div class="alert alert-info text-center py-5">
                        <i class="bi bi-cloud-arrow-up display-4 d-block mb-2 text-primary"></i>
                        <h5>Chưa có bộ dữ liệu GTFS nào trong hệ thống!</h5>
                        <p class="text-muted">Nhấn vào tab <strong>"Nhập Bộ GTFS Mới"</strong> để nạp lịch trình.</p>
                        <a href="gtfs.php?tab=import" class="btn btn-primary">Tải lên ngay</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Tên Phiên Bản</th>
                                    <th>Người Nhập</th>
                                    <th>Thời Gian Tải Lên</th>
                                    <th>Thống Kê Dữ Liệu</th>
                                    <th>Trạng Thái</th>
                                    <th class="text-end">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_versions as $ver): 
                                    $stats = get_feed_stats($ver['id']);
                                    $errCount = count_validation_errors($ver['id']);
                                ?>
                                    <tr class="<?php echo ($ver['status'] === 'active') ? 'table-success bg-opacity-25' : ''; ?>">
                                        <td class="fw-bold">#<?php echo $ver['id']; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($ver['version_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php if (!empty($ver['notes'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($ver['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($ver['uploader_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($ver['uploaded_at'])); ?></td>
                                        <td>
                                            <?php if ($ver['status'] === 'invalid'): ?>
                                                <span class="text-danger small"><i class="bi bi-exclamation-octagon"></i> Lỗi kiểm tra</span>
                                            <?php else: ?>
                                                <div class="small">
                                                    <span class="badge bg-secondary me-1"><?php echo $stats['routes']; ?> Tuyến</span>
                                                    <span class="badge bg-secondary me-1"><?php echo $stats['stops']; ?> Trạm</span>
                                                    <span class="badge bg-secondary"><?php echo $stats['trips']; ?> Chuyến</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($ver['status'] === 'active'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i> Đang Sử Dụng</span>
                                            <?php elseif ($ver['status'] === 'valid'): ?>
                                                <span class="badge bg-info text-dark"><i class="bi bi-shield-check me-1"></i> Hợp Lệ (Sẵn Sàng)</span>
                                            <?php elseif ($ver['status'] === 'invalid'): ?>
                                                <span class="badge bg-danger"><i class="bi bi-shield-x me-1"></i> Có Lỗi (<?php echo $errCount; ?>)</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Chờ Kiểm Tra</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($ver['status'] === 'valid'): ?>
                                                    <form method="POST" action="gtfs.php" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn chuyển hệ thống sang sử dụng phiên bản này không?');">
                                                        <input type="hidden" name="action" value="activate_version">
                                                        <input type="hidden" name="version_id" value="<?php echo $ver['id']; ?>">
                                                        <button type="submit" class="btn btn-success fw-semibold" title="Đưa phiên bản này vào tra cứu chính thức">
                                                            <i class="bi bi-play-circle-fill"></i> Kích Hoạt
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($ver['status'] === 'invalid' || $errCount > 0): ?>
                                                    <a href="gtfs.php?tab=errors&view_errors=<?php echo $ver['id']; ?>" class="btn btn-outline-danger" title="Xem chi tiết các lỗi">
                                                        <i class="bi bi-eye"></i> Xem Lỗi (<?php echo $errCount; ?>)
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($ver['status'] === 'valid' || $ver['status'] === 'active'): ?>
                                                    <a href="../views/gtfs_browse.php?version_id=<?php echo $ver['id']; ?>" class="btn btn-outline-primary" title="Xem chi tiết các tuyến trạm">
                                                        <i class="bi bi-search"></i> Xem
                                                    </a>
                                                    <a href="../export.php?version_id=<?php echo $ver['id']; ?>&type=schedule" class="btn btn-outline-secondary" title="Xuất lịch CSV">
                                                        <i class="bi bi-download"></i> Xuất
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <!-- TAB 2: NHẬP GTFS MỚI -->
        <?php elseif ($active_tab === 'import'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card main-card p-4 p-md-5">
                        <div class="text-center mb-4">
                            <div class="bg-primary-subtle text-primary p-3 rounded-circle d-inline-block mb-2">
                                <i class="bi bi-cloud-arrow-up-fill fs-2"></i>
                            </div>
                            <h4 class="fw-bold text-dark">Nhập & Kiểm Tra Bộ Dữ Liệu GTFS Tĩnh</h4>
                            <p class="text-muted">Chọn từ thư mục GTFS trong hệ thống hoặc tải lên tệp nén <code>.zip</code>.</p>
                        </div>

                        <!-- Chú thích quy định kỹ thuật -->
                        <div class="alert alert-info small mb-4">
                            <h6 class="fw-bold mb-2"><i class="bi bi-info-circle-fill me-1"></i> Quy định kiểm tra & Bảo vệ bản đang dùng:</h6>
                            <ul class="mb-0 ps-3">
                                <li><strong>Tệp bắt buộc:</strong> <code>agency.txt</code>, <code>routes.txt</code>, <code>stops.txt</code>, <code>trips.txt</code>, <code>stop_times.txt</code>, <code>calendar.txt</code>.</li>
                                <li><strong>Tệp tùy chọn:</strong> <code>calendar_dates.txt</code> (ngày ngoại lệ thêm/hủy dịch vụ).</li>
                                <li><strong>Kiểm tra khóa ngoại:</strong> Mã liên kết giữa các file phải tồn tại đầy đủ.</li>
                                <li><strong>Bảo vệ:</strong> Nếu tệp có lỗi, phiên bản sẽ được lưu ở trạng thái <code>invalid</code> kèm chi tiết lỗi để kiểm tra. <strong>Bản lịch đang sử dụng hoàn toàn không bị ảnh hưởng!</strong></li>
                            </ul>
                        </div>

                        <?php if (!empty($detected_gtfs_dirs)): ?>
                            <!-- CÁCH 1: NHẬP TRỰC TIẾP TỪ THƯ MỤC CÓ SẴN (gtfs_data/) -->
                            <div class="card p-3 mb-4 border border-primary border-opacity-25 bg-primary bg-opacity-10 rounded-3">
                                <h6 class="fw-bold text-primary mb-2">
                                    <i class="bi bi-folder-check me-1"></i> Cách 1: Nhập Trực Tiếp Từ Thư Mục GTFS Trong <code>gtfs_data/</code>
                                </h6>
                                <p class="small text-muted mb-3">Hệ thống đã tự động nhận diện các thư mục GTFS trong <code>gtfs_data/</code>. Bạn có thể chọn ngay một thư mục để kiểm tra và nạp vào database:</p>

                                <form action="gtfs.php" method="POST">
                                    <input type="hidden" name="action" value="import_folder">

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Chọn Thư Mục GTFS:</label>
                                        <select name="folder_name" class="form-select form-select-lg" required>
                                            <?php foreach ($detected_gtfs_dirs as $d): ?>
                                                <option value="<?php echo htmlspecialchars($d); ?>">
                                                    📁 <?php echo htmlspecialchars($d); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Đặt Tên Phiên Bản Lịch: <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="version_name" 
                                               placeholder="Ví dụ: GTFS Machida City - Bản 1.0 (Có chuyến đêm 25:10)" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Ghi Chú:</label>
                                        <input type="text" class="form-control" name="notes" placeholder="Ví dụ: Dữ liệu GTFS thực tế có calendar_dates và giờ qua đêm 25:10">
                                    </div>

                                    <button type="submit" class="btn btn-primary fw-bold w-100">
                                        <i class="bi bi-shield-check me-1"></i> Nạp Dữ Liệu & Kiểm Tra Thư Mục Này
                                    </button>
                                </form>
                            </div>

                            <div class="text-center text-muted fw-bold mb-4">— HOẶC TẢI LÊN FILE .ZIP —</div>
                        <?php endif; ?>

                        <!-- CÁCH 2: TẢI LÊN FILE ZIP -->
                        <div class="card p-3 border rounded-3 bg-light">
                            <h6 class="fw-bold text-dark mb-2">
                                <i class="bi bi-file-earmark-zip-fill text-warning me-1"></i> Cách 2: Tải Lên Tệp Nén ZIP GTFS (.zip)
                            </h6>
                            <form action="gtfs.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="import_gtfs">

                                <div class="mb-3">
                                    <label for="version_name" class="form-label fw-bold">Tên phiên bản lịch trình <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-lg" id="version_name" name="version_name" 
                                           placeholder="Ví dụ: GTFS Lịch Hè 2026 - Phiên bản 1.0" required>
                                </div>

                                <div class="mb-3">
                                    <label for="gtfs_zip" class="form-label fw-bold">Chọn tệp ZIP GTFS (.zip) <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control form-control-lg" id="gtfs_zip" name="gtfs_zip" accept=".zip" required>
                                    <div class="form-text">Tệp nén .zip chứa các tệp .txt theo chuẩn GTFS.</div>
                                </div>

                                <div class="mb-4">
                                    <label for="notes" class="form-label fw-semibold">Ghi chú (Tùy chọn)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Ví dụ: Bổ sung tuyến metro 01, điều chỉnh giờ chuyến đêm..."></textarea>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-outline-primary btn-lg fw-bold shadow-sm">
                                        <i class="bi bi-upload me-1"></i> Tải Lên & Tiến Hành Kiểm Tra Toàn Bộ
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <!-- TAB 3: SO SÁNH HAI BẢN LỊCH -->
        <?php elseif ($active_tab === 'compare'): ?>
            <div class="card main-card p-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="bi bi-arrow-left-right text-info me-2"></i>So Sánh Tuyến & Chuyến Giữa Hai Phiên Bản Lịch
                </h5>

                <form method="GET" action="gtfs.php" class="row g-3 align-items-end mb-4 p-3 bg-light rounded-3">
                    <input type="hidden" name="tab" value="compare">

                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Phiên Bản Cũ (Gốc / V1):</label>
                        <select name="v1" class="form-select form-select-lg" required>
                            <option value="">-- Chọn phiên bản thứ nhất --</option>
                            <?php foreach ($all_versions as $v): if ($v['status'] === 'invalid') continue; ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo ($compare_v1 === (int)$v['id']) ? 'selected' : ''; ?>>
                                    #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php echo ($v['status'] === 'active') ? ' (Đang dùng)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Phiên Bản Mới (So Sánh / V2):</label>
                        <select name="v2" class="form-select form-select-lg" required>
                            <option value="">-- Chọn phiên bản thứ hai --</option>
                            <?php foreach ($all_versions as $v): if ($v['status'] === 'invalid') continue; ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo ($compare_v2 === (int)$v['id']) ? 'selected' : ''; ?>>
                                    #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php echo ($v['status'] === 'active') ? ' (Đang dùng)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                            <i class="bi bi-search"></i> So Sánh
                        </button>
                    </div>
                </form>

                <?php if ($comparison_result && isset($v1_info, $v2_info)): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">
                            Kết Quả So Sánh: <span class="text-primary"><?php echo htmlspecialchars($v1_info['version_name']); ?></span> 
                            ➔ <span class="text-success"><?php echo htmlspecialchars($v2_info['version_name']); ?></span>
                        </h6>
                        <a href="../export.php?type=comparison&v1=<?php echo $compare_v1; ?>&v2=<?php echo $compare_v2; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Xuất Kết Quả So Sánh CSV
                        </a>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card p-3 border">
                                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-signpost-split"></i> So Sánh Tuyến (Routes)</h6>
                                <ul class="list-unstyled mb-0 small">
                                    <li>Tổng số tuyến: <strong><?php echo $comparison_result['routes']['total_v1']; ?></strong> ➔ <strong><?php echo $comparison_result['routes']['total_v2']; ?></strong></li>
                                    <li class="text-success">➕ Tuyến mới thêm: <strong><?php echo count($comparison_result['routes']['added']); ?></strong></li>
                                    <li class="text-danger">➖ Tuyến bị xóa: <strong><?php echo count($comparison_result['routes']['removed']); ?></strong></li>
                                    <li class="text-warning">✏️ Tuyến có chỉnh sửa: <strong><?php echo count($comparison_result['routes']['modified']); ?></strong></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-3 border">
                                <h6 class="fw-bold text-success mb-2"><i class="bi bi-bus-front"></i> So Sánh Chuyến (Trips)</h6>
                                <ul class="list-unstyled mb-0 small">
                                    <li>Tổng số chuyến: <strong><?php echo $comparison_result['trips']['total_v1']; ?></strong> ➔ <strong><?php echo $comparison_result['trips']['total_v2']; ?></strong></li>
                                    <li class="text-success">➕ Chuyến mới thêm: <strong><?php echo count($comparison_result['trips']['added']); ?></strong></li>
                                    <li class="text-danger">➖ Chuyến bị xóa: <strong><?php echo count($comparison_result['trips']['removed']); ?></strong></li>
                                    <li class="text-warning">✏️ Chuyến thay đổi giờ / trạm: <strong><?php echo count($comparison_result['trips']['modified']); ?></strong></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-2">Chi Tiết Thay Đổi Về Tuyến:</h6>

                        <?php if (!empty($comparison_result['routes']['added'])): ?>
                            <div class="alert alert-success py-2 mb-2">
                                <strong><i class="bi bi-plus-circle me-1"></i> Tuyến mới được thêm vào (<?php echo count($comparison_result['routes']['added']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach ($comparison_result['routes']['added'] as $r): ?>
                                        <li>Mã: <code><?php echo htmlspecialchars($r['route_id']); ?></code> - <?php echo htmlspecialchars($r['route_short_name'] . ' (' . $r['route_long_name'] . ')'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comparison_result['routes']['removed'])): ?>
                            <div class="alert alert-danger py-2 mb-2">
                                <strong><i class="bi bi-dash-circle me-1"></i> Tuyến bị xóa bỏ (<?php echo count($comparison_result['routes']['removed']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach ($comparison_result['routes']['removed'] as $r): ?>
                                        <li>Mã: <code><?php echo htmlspecialchars($r['route_id']); ?></code> - <?php echo htmlspecialchars($r['route_short_name'] . ' (' . $r['route_long_name'] . ')'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comparison_result['routes']['modified'])): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <strong><i class="bi bi-pencil me-1"></i> Tuyến có chỉnh sửa thông tin (<?php echo count($comparison_result['routes']['modified']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach ($comparison_result['routes']['modified'] as $rid => $changes): ?>
                                        <li>Mã <code><?php echo htmlspecialchars($rid); ?></code>: <?php echo implode(', ', $changes); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($comparison_result['routes']['added']) && empty($comparison_result['routes']['removed']) && empty($comparison_result['routes']['modified'])): ?>
                            <p class="text-muted small">Không có sự thay đổi nào về danh sách tuyến giữa 2 bản.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h6 class="fw-bold text-dark border-bottom pb-2">Chi Tiết Thay Đổi Về Chuyến Xe:</h6>

                        <?php if (!empty($comparison_result['trips']['added'])): ?>
                            <div class="alert alert-success py-2 mb-2">
                                <strong><i class="bi bi-plus-circle me-1"></i> Chuyến xe mới thêm (<?php echo count($comparison_result['trips']['added']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach (array_slice($comparison_result['trips']['added'], 0, 10) as $t): ?>
                                        <li>Chuyến <code><?php echo htmlspecialchars($t['trip_id']); ?></code> (Tuyến: <?php echo htmlspecialchars($t['route_short_name'] ?? $t['route_id'] ?? ''); ?> - Hướng: <?php echo htmlspecialchars($t['trip_headsign'] ?? ''); ?>)</li>
                                    <?php endforeach; ?>
                                    <?php if (count($comparison_result['trips']['added']) > 10): ?>
                                        <li class="text-muted">...và còn <?php echo count($comparison_result['trips']['added']) - 10; ?> chuyến khác (xuất file CSV để xem đầy đủ).</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comparison_result['trips']['removed'])): ?>
                            <div class="alert alert-danger py-2 mb-2">
                                <strong><i class="bi bi-dash-circle me-1"></i> Chuyến xe bị hủy (<?php echo count($comparison_result['trips']['removed']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach (array_slice($comparison_result['trips']['removed'], 0, 10) as $t): ?>
                                        <li>Chuyến <code><?php echo htmlspecialchars($t['trip_id']); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comparison_result['trips']['modified'])): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <strong><i class="bi bi-pencil me-1"></i> Chuyến có thay đổi giờ hoặc trạm dừng (<?php echo count($comparison_result['trips']['modified']); ?>):</strong>
                                <ul class="mb-0 mt-1 small">
                                    <?php foreach (array_slice($comparison_result['trips']['modified'], 0, 10) as $tid => $change): ?>
                                        <li>Chuyến <code><?php echo htmlspecialchars($tid); ?></code>: <?php echo htmlspecialchars($change); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif (isset($_GET['v1'], $_GET['v2']) && $compare_v1 === $compare_v2): ?>
                    <div class="alert alert-warning text-center">
                        Vui lòng chọn 2 phiên bản khác nhau để thực hiện so sánh.
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4">Vui lòng chọn 2 phiên bản từ bộ chọn ở trên và nhấn <strong>"So Sánh"</strong> để phân tích sự khác biệt.</p>
                <?php endif; ?>

            </div>

        <!-- TAB 4: XEM BÁO CÁO LỖI -->
        <?php elseif ($active_tab === 'errors'): ?>
            <div class="card main-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-danger mb-0">
                        <i class="bi bi-shield-x me-2"></i>Báo Cáo Chi Tiết Lỗi Kiểm Tra Bộ Dữ Liệu
                    </h5>
                    <?php if ($selected_error_version_id && !empty($version_errors)): ?>
                        <a href="../export.php?type=errors&version_id=<?php echo $selected_error_version_id; ?>" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-download me-1"></i> Xuất Báo Cáo Lỗi Ra CSV
                        </a>
                    <?php endif; ?>
                </div>

                <form method="GET" action="gtfs.php" class="row g-2 mb-4 align-items-center">
                    <input type="hidden" name="tab" value="errors">
                    <div class="col-auto">
                        <label class="form-label mb-0 fw-semibold">Chọn phiên bản để xem lỗi:</label>
                    </div>
                    <div class="col-md-5">
                        <select name="view_errors" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Chọn phiên bản --</option>
                            <?php foreach ($all_versions as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo ($selected_error_version_id === (int)$v['id']) ? 'selected' : ''; ?>>
                                    #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name']); ?>
                                    (Trạng thái: <?php echo strtoupper($v['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if ($selected_error_version_id && $error_version_info): ?>
                    <div class="alert alert-secondary py-2 mb-3">
                        Đang xem lỗi của: <strong><?php echo htmlspecialchars($error_version_info['version_name']); ?></strong>
                        - Trạng thái: <span class="badge bg-danger"><?php echo strtoupper($error_version_info['status']); ?></span>
                        - Tổng số lỗi phát hiện: <strong><?php echo count($version_errors); ?></strong>
                    </div>

                    <?php if (empty($version_errors)): ?>
                        <div class="alert alert-success text-center py-4">
                            <i class="bi bi-check-circle display-4 d-block mb-2 text-success"></i>
                            <h5 class="fw-bold">Phiên bản này hoàn toàn hợp lệ!</h5>
                            <p class="text-muted mb-0">Không có bất kỳ lỗi nào về tệp, cấu trúc cột hay mã tham chiếu.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-danger">
                                    <tr>
                                        <th style="width: 50px;">STT</th>
                                        <th>Loại Lỗi</th>
                                        <th>Tệp Tin</th>
                                        <th>Cột</th>
                                        <th>Dòng</th>
                                        <th>Mô Tả Lỗi Chi Tiết</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $idx = 1; foreach ($version_errors as $err): ?>
                                        <tr>
                                            <td class="text-center fw-bold"><?php echo $idx++; ?></td>
                                            <td>
                                                <span class="badge bg-danger">
                                                    <?php echo htmlspecialchars($err['error_type']); ?>
                                                </span>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($err['file_name'] ?? 'N/A'); ?></code></td>
                                            <td><code><?php echo htmlspecialchars($err['column_name'] ?? 'N/A'); ?></code></td>
                                            <td><?php echo $err['row_number'] ? 'Dòng ' . $err['row_number'] : '-'; ?></td>
                                            <td class="text-danger fw-semibold"><?php echo htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-4">Vui lòng chọn 1 phiên bản từ danh sách trên để xem báo cáo lỗi kiểm tra.</p>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
