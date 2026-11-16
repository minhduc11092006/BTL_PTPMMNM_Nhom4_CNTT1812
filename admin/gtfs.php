<?php
// admin/gtfs.php - Quản lý, Nhập, Validate và So sánh các phiên bản lịch trình GTFS
@ini_set('memory_limit', '1024M');
@set_time_limit(0);

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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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

    // 3. XÓA MỀM PHIÊN BẢN (Soft Delete)
    elseif ($action === 'delete_version') {
        $version_id = (int)($_POST['version_id'] ?? 0);
        if (soft_delete_feed_version($version_id)) {
            $success_msg = 'Đã xóa phiên bản lịch trình thành công! Bạn có thể khôi phục lại từ danh sách bên dưới.';
        } else {
            $error_msg = 'Không thể xóa phiên bản này. Phiên bản đang được sử dụng (Active) không thể xóa.';
        }
        $active_tab = 'versions';
    }

    // 4. KHÔI PHỤC PHIÊN BẢN ĐÃ XÓA (Restore)
    elseif ($action === 'restore_version') {
        $version_id = (int)($_POST['version_id'] ?? 0);
        if (restore_feed_version($version_id)) {
            $success_msg = 'Đã khôi phục phiên bản lịch trình thành công!';
        } else {
            $error_msg = 'Không thể khôi phục phiên bản này.';
        }
        $active_tab = 'versions';
    }

    // 5. XÓA VĨNH VIỄN PHIÊN BẢN (Purge - cascade delete)
    elseif ($action === 'purge_version') {
        $version_id = (int)($_POST['version_id'] ?? 0);
        if (purge_feed_version($version_id)) {
            $success_msg = 'Đã xoá vĩnh viễn phiên bản và toàn bộ dữ liệu liên quan!';
        } else {
            $error_msg = 'Không thể xoá vĩnh viễn. Chỉ có thể xoá vĩnh viễn các phiên bản đã được xóa mềm trước đó.';
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
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                            950: '#1e1b4b',
                        }
                    },
                    boxShadow: {
                        'glow': '0 0 25px -5px rgba(79, 70, 229, 0.3)',
                    }
                }
            }
        }
    </script>

    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #1e293b;
        }

        /* Hiệu ứng Parallax Navbar & Bus */
        @keyframes infinite-pan-left {
            0% { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-50%, 0, 0); }
        }
        .animate-infinite-parallax {
            display: flex;
            width: 200%;
            animation: infinite-pan-left var(--parallax-duration, 10s) linear infinite;
            will-change: transform;
        }
        @keyframes bus-wheel-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .animate-wheel-spin {
            transform-origin: center center;
            transform-box: fill-box;
            animation: bus-wheel-spin 0.65s linear infinite;
            will-change: transform;
        }
        @keyframes bus-bounce-suspension {
            0%, 100% { transform: translateY(0); }
            30% { transform: translateY(-2px); }
            70% { transform: translateY(1px); }
        }
        .animate-bus-bounce {
            animation: bus-bounce-suspension 0.85s ease-in-out infinite;
            will-change: transform;
        }
        @keyframes headlight-glow-pulse {
            0%, 100% { opacity: 0.75; transform: scaleX(1); }
            50% { opacity: 1; transform: scaleX(1.15); filter: drop-shadow(0 0 10px rgba(254, 240, 138, 0.95)); }
        }
        .animate-headlight-pulse {
            animation: headlight-glow-pulse 1.6s ease-in-out infinite;
        }
        @keyframes bus-exhaust-puff {
            0% { transform: translate(0, 0) scale(0.35); opacity: 0.8; }
            50% { opacity: 0.5; }
            100% { transform: translate(-35px, -10px) scale(1.8); opacity: 0; }
        }
        .animate-exhaust-puff-1 {
            animation: bus-exhaust-puff 1.2s cubic-bezier(0.15, 0.85, 0.35, 1) infinite;
        }
        .animate-exhaust-puff-2 {
            animation: bus-exhaust-puff 1.2s cubic-bezier(0.15, 0.85, 0.35, 1) 0.6s infinite;
        }
        @keyframes highway-track-pan {
            0% { background-position: 0 0; }
            100% { background-position: -36px 0; }
        }
        .animate-highway-track {
            background-image: repeating-linear-gradient(90deg, #38bdf8 0, #38bdf8 12px, transparent 12px, transparent 24px);
            background-size: 36px 2px;
            animation: highway-track-pan 0.65s linear infinite;
        }
        @keyframes bus-suspension {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            30% { transform: translateY(-1.5px) rotate(-1deg); }
            70% { transform: translateY(0.5px) rotate(0.5deg); }
        }
        .animate-bus-suspension {
            animation: bus-suspension 1.8s ease-in-out infinite;
        }
        @keyframes taillight-trail {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; filter: drop-shadow(0 0 6px rgba(244, 63, 94, 0.9)); }
        }
        .animate-taillight-trail {
            animation: taillight-trail 1s ease-in-out infinite;
        }
        .custom-scrollbar::-webkit-scrollbar {
            height: 6px;
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 9999px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="min-h-full flex flex-col bg-slate-50 selection:bg-brand-600 selection:text-white">

    <!-- ================================================================= -->
    <!-- MODERN FULL-WIDTH ANIMATED TRANSIT NAVBAR CHO TRANG ADMIN        -->
    <!-- (Xe Bus chạy liên tục trên nền Parallax, Dải đường neo phát sáng)  -->
    <!-- ================================================================= -->
    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800/80 transition-all overflow-hidden relative shadow-lg">
        
        <!-- ============================================================== -->
        <!-- LỚP PARALLAX CUỘN CẢNH VÔ TẬN & XE BUS ĐÔ THỊ ĐANG CHẠY LIÊN TỤC -->
        <!-- ============================================================== -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
            
            <!-- Lớp Parallax 1: Bầu trời sao đêm & dải mây trôi chậm -->
            <div class="absolute inset-0 opacity-25 animate-infinite-parallax" style="--parallax-duration: 40s;">
                <div class="w-1/2 h-full flex items-center justify-around shrink-0">
                    <div class="w-24 h-4 bg-sky-300/30 rounded-full blur-sm"></div>
                    <div class="w-40 h-5 bg-indigo-300/20 rounded-full blur-md"></div>
                    <div class="w-32 h-4 bg-slate-300/25 rounded-full blur-sm"></div>
                </div>
                <div class="w-1/2 h-full flex items-center justify-around shrink-0">
                    <div class="w-24 h-4 bg-sky-300/30 rounded-full blur-sm"></div>
                    <div class="w-40 h-5 bg-indigo-300/20 rounded-full blur-md"></div>
                    <div class="w-32 h-4 bg-slate-300/25 rounded-full blur-sm"></div>
                </div>
            </div>

            <!-- Lớp Parallax 2: Dãy nhà cao ốc thành phố (City Skyline) cuộn ngang vô tận -->
            <div class="absolute bottom-[3px] inset-x-0 h-11 opacity-30 animate-infinite-parallax" style="--parallax-duration: 16s;">
                <div class="w-1/2 h-full flex items-end shrink-0">
                    <svg class="w-full h-full fill-indigo-950 stroke-indigo-800/50" viewBox="0 0 800 45" preserveAspectRatio="none">
                        <rect x="10" y="15" width="25" height="30" />
                        <rect x="45" y="8" width="30" height="37" />
                        <polygon points="60,2 57,8 63,8" />
                        <rect x="85" y="18" width="20" height="27" />
                        <rect x="115" y="10" width="35" height="35" />
                        <rect x="160" y="22" width="28" height="23" />
                        <rect x="200" y="6" width="32" height="39" />
                        <rect x="245" y="16" width="25" height="29" />
                        <rect x="280" y="12" width="40" height="33" />
                        <rect x="330" y="20" width="22" height="25" />
                        <rect x="365" y="5" width="35" height="40" />
                        <polygon points="382,0 379,5 385,5" />
                        <rect x="410" y="14" width="28" height="31" />
                        <rect x="450" y="22" width="24" height="23" />
                        <rect x="485" y="9" width="38" height="36" />
                        <rect x="535" y="18" width="25" height="27" />
                        <rect x="570" y="7" width="30" height="38" />
                        <rect x="610" y="15" width="35" height="30" />
                        <rect x="655" y="20" width="25" height="25" />
                        <rect x="690" y="10" width="40" height="35" />
                        <rect x="740" y="16" width="30" height="29" />
                        <rect x="780" y="8" width="20" height="37" />
                    </svg>
                </div>
                <div class="w-1/2 h-full flex items-end shrink-0">
                    <svg class="w-full h-full fill-indigo-950 stroke-indigo-800/50" viewBox="0 0 800 45" preserveAspectRatio="none">
                        <rect x="10" y="15" width="25" height="30" />
                        <rect x="45" y="8" width="30" height="37" />
                        <polygon points="60,2 57,8 63,8" />
                        <rect x="85" y="18" width="20" height="27" />
                        <rect x="115" y="10" width="35" height="35" />
                        <rect x="160" y="22" width="28" height="23" />
                        <rect x="200" y="6" width="32" height="39" />
                        <rect x="245" y="16" width="25" height="29" />
                        <rect x="280" y="12" width="40" height="33" />
                        <rect x="330" y="20" width="22" height="25" />
                        <rect x="365" y="5" width="35" height="40" />
                        <polygon points="382,0 379,5 385,5" />
                        <rect x="410" y="14" width="28" height="31" />
                        <rect x="450" y="22" width="24" height="23" />
                        <rect x="485" y="9" width="38" height="36" />
                        <rect x="535" y="18" width="25" height="27" />
                        <rect x="570" y="7" width="30" height="38" />
                        <rect x="610" y="15" width="35" height="30" />
                        <rect x="655" y="20" width="25" height="25" />
                        <rect x="690" y="10" width="40" height="35" />
                        <rect x="740" y="16" width="30" height="29" />
                        <rect x="780" y="8" width="20" height="37" />
                    </svg>
                </div>
            </div>

            <!-- Lớp Parallax 3: Làn đường cao tốc / Vạch sơn phản quang neon cuộn liên tục (DẢI ĐƯỜNG NEO) -->
            <div class="absolute bottom-0 inset-x-0 h-[3px] bg-slate-950/90 border-t border-slate-800/80 flex items-center overflow-hidden">
                <div class="w-full h-full animate-highway-track opacity-85"></div>
            </div>

            <!-- Vệt sáng neon mờ dẫn hướng xe bus -->
            <div class="absolute bottom-1 inset-x-0 h-[1px] bg-gradient-to-r from-transparent via-cyan-500/25 to-transparent"></div>

            <!-- ========================================================== -->
            <!-- XE BUÝT ĐÔ THỊ ĐANG CHẠY LIÊN TỤC TRÊN NỀN PARALLAX VÔ TẬN -->
            <!-- ========================================================== -->
            <div class="absolute bottom-[3px] left-3 sm:left-6 md:left-10 flex items-end z-10 animate-bus-bounce">
                <div class="relative flex items-center">
                    
                    <!-- Hiệu ứng khói xả phun ra từ đuôi xe buýt -->
                    <div class="absolute -left-2 bottom-1 pointer-events-none">
                        <div class="w-2.5 h-2.5 rounded-full bg-slate-400/40 blur-[1px] animate-exhaust-puff-1"></div>
                        <div class="w-2 h-2 rounded-full bg-slate-300/35 blur-[1px] animate-exhaust-puff-2 -mt-1"></div>
                    </div>

                    <!-- Vệt sáng neon phát phía sau đuôi xe -->
                    <div class="w-12 h-3.5 bg-gradient-to-l from-emerald-500/30 via-cyan-500/15 to-transparent blur-[2px] -mr-1"></div>

                    <!-- SVG Xe Bus Điện Đô Thị Siêu Nét với Bánh Xe Quay Tròn 360 độ -->
                    <svg class="w-28 sm:w-32 h-8 sm:h-9 filter drop-shadow-[0_2px_10px_rgba(16,185,129,0.7)]" viewBox="0 0 110 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="navBusPaintGrad" x1="5" y1="4" x2="5" y2="25" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#10b981" />
                                <stop offset="50%" stop-color="#059669" />
                                <stop offset="100%" stop-color="#047857" />
                            </linearGradient>
                            <linearGradient id="navBusBeamGrad" x1="88" y1="17.5" x2="110" y2="17.5" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#fef08a" stop-opacity="0.95" />
                                <stop offset="60%" stop-color="#fef08a" stop-opacity="0.35" />
                                <stop offset="100%" stop-color="#fef08a" stop-opacity="0" />
                            </linearGradient>
                        </defs>

                        <!-- Khung xe khí động học màu xanh Emerald hiện đại -->
                        <rect x="5" y="4" width="83" height="21" rx="5" fill="url(#navBusPaintGrad)" stroke="#0f172a" stroke-width="1.2" />
                        
                        <!-- Nóc xe vát màu trắng sáng bóng -->
                        <path d="M6 7 Q 48 3 87 7" stroke="#6ee7b7" stroke-width="1.2" fill="none" />
                        
                        <!-- Dải kính đen chống lóa cao cấp -->
                        <rect x="8" y="6" width="14" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="24" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="41" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="58" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        
                        <!-- Cửa kính người lái bo cong phía trước -->
                        <path d="M75 6 H83 C86 6 87 7.5 87 9.5 V15 H75 V6 Z" fill="#0f172a" />
                        
                        <!-- Biển số tuyến LED phát sáng trên kính xe: "BUS 01" -->
                        <text x="42" y="12.5" fill="#38bdf8" font-size="5" font-family="monospace" font-weight="bold" letter-spacing="0.5">BUS 01</text>
                        
                        <!-- Đèn hậu LED đỏ phía sau -->
                        <rect x="5" y="16" width="2" height="5" rx="0.8" fill="#f43f5e" class="animate-taillight-trail" />

                        <!-- Đèn pha LED trước chùm sáng cực mạnh -->
                        <circle cx="86" cy="17.5" r="2" fill="#fef08a" class="animate-headlight-pulse" />
                        
                        <!-- Chùm tia sáng đèn pha quét xa về phía trước -->
                        <polygon points="88,17.5 110,12 110,24" fill="url(#navBusBeamGrad)" class="animate-headlight-pulse opacity-85" />

                        <!-- Bánh xe sau quay tròn liên tục 360 độ (Animated Spinning Wheel) -->
                        <g class="animate-wheel-spin" style="transform-origin: 20px 24px;">
                            <circle cx="20" cy="24" r="5" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.5" />
                            <circle cx="20" cy="24" r="2" fill="#38bdf8" />
                            <line x1="20" y1="19" x2="20" y2="29" stroke="#cbd5e1" stroke-width="1.2" />
                            <line x1="15" y1="24" x2="25" y2="24" stroke="#cbd5e1" stroke-width="1.2" />
                        </g>

                        <!-- Bánh xe trước quay tròn liên tục 360 độ (Animated Spinning Wheel) -->
                        <g class="animate-wheel-spin" style="transform-origin: 73px 24px;">
                            <circle cx="73" cy="24" r="5" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.5" />
                            <circle cx="73" cy="24" r="2" fill="#38bdf8" />
                            <line x1="73" y1="19" x2="73" y2="29" stroke="#cbd5e1" stroke-width="1.2" />
                            <line x1="68" y1="24" x2="78" y2="24" stroke="#cbd5e1" stroke-width="1.2" />
                        </g>
                    </svg>

                    <!-- Huy hiệu "01 Live" nhỏ gọn theo xe -->
                    <span class="text-[9px] font-bold text-emerald-300 bg-emerald-950/90 px-1.5 py-0.5 rounded border border-emerald-500/40 -ml-2 -mb-2 shadow-sm whitespace-nowrap hidden sm:inline-block">
                        01 Live
                    </span>
                </div>
            </div>

        </div>

        <!-- ============================================================== -->
        <!-- LỚP NỘI DUNG & CÁC NÚT ĐIỀU HƯỚNG CHỨC NĂNG (NAVIGATION LINKS)  -->
        <!-- ============================================================== -->
        <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 sm:h-[70px] gap-2">
                
                <!-- Brand / Logo với biểu tượng Bus & Train Động -->
                <div class="flex items-center gap-2.5 shrink-0">
                    <a href="../index.php" class="group flex items-center gap-2.5 focus:outline-none">
                        <div class="relative w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 via-indigo-500 to-sky-400 p-0.5 shadow-glow flex items-center justify-center transform group-hover:scale-105 transition duration-300 shrink-0">
                            <div class="w-full h-full bg-slate-900 rounded-[10px] flex items-center justify-center relative overflow-hidden">
                                <i class="bi bi-bus-front-fill text-base text-sky-400 -mr-1 group-hover:text-amber-400 transition animate-bus-suspension"></i>
                                <i class="bi bi-train-front-fill text-xs text-indigo-400 -ml-1 opacity-80"></i>
                            </div>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm sm:text-base font-extrabold tracking-tight text-white flex items-center gap-1.5 whitespace-nowrap">
                                Smart Transit GTFS
                                <span class="hidden md:inline-block text-[9px] uppercase font-bold px-1.5 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">Admin Portal</span>
                            </span>
                            <span class="text-[10px] text-slate-400 -mt-0.5 font-medium hidden 2xl:block whitespace-nowrap">Hệ Thống Quản Trị Vận Tải Công Cộng</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links (Nút bo tròn, không viền border) -->
                <nav class="hidden lg:flex items-center gap-1 bg-slate-900/90 backdrop-blur-md p-1 rounded-xl shadow-md shrink-0">
                    <a href="../index.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-house-door text-brand-400 text-sm"></i>
                        <span>Trang chủ</span>
                    </a>

                    <a href="../views/map.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-map-fill text-emerald-400 text-sm"></i>
                        <span>Bản Đồ</span>
                    </a>

                    <a href="gtfs.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg bg-brand-600/50 text-white font-semibold transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-layers-fill text-sky-400 text-sm"></i>
                        <span>Quản Trị GTFS</span>
                    </a>
                </nav>

                <!-- Trạng Thái User / Nút Đăng xuất -->
                <div class="flex items-center gap-2 xl:gap-2.5 shrink-0">
                    <div class="hidden 2xl:flex items-center gap-2 px-2.5 py-1.5 h-9 rounded-xl bg-slate-900/85 shadow-inner backdrop-blur-md shrink-0">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                        </span>
                        <span class="text-xs font-bold text-slate-200 tracking-tight whitespace-nowrap flex items-center gap-1">
                            <i class="bi bi-shield-lock text-amber-400"></i>
                            <span>ADMIN MODE</span>
                        </span>
                    </div>

                    <div class="hidden sm:flex items-center gap-1.5 xl:gap-2 pl-2 border-l border-slate-800 shrink-0">
                        <div class="flex items-center gap-2 px-2.5 h-9 rounded-xl bg-slate-900/85 backdrop-blur-md shrink-0">
                            <div class="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold text-xs shrink-0">
                                <i class="bi bi-person-fill"></i>
                            </div>
                            <div class="flex items-center gap-1.5 whitespace-nowrap">
                                <span class="text-xs font-semibold text-slate-200 truncate max-w-[120px]"><?php echo htmlspecialchars($admin_user['fullname'] ?? 'Admin'); ?></span>
                                <span class="text-[10px] text-amber-300 bg-amber-500/10 px-1.5 py-0.2 rounded font-bold uppercase">Admin</span>
                            </div>
                        </div>

                        <a href="../auth/logout.php" title="Đăng xuất" class="h-9 w-9 inline-flex items-center justify-center text-slate-400 hover:text-rose-400 hover:bg-slate-800/80 rounded-xl transition shrink-0">
                            <i class="bi bi-box-arrow-right text-base"></i>
                        </a>
                    </div>

                    <!-- Mobile Menu Button -->
                    <button id="mobile-menu-btn" type="button" class="lg:hidden h-9 w-9 inline-flex items-center justify-center rounded-xl text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700 focus:outline-none">
                        <i id="menu-icon-bars" class="bi bi-list text-xl"></i>
                        <i id="menu-icon-close" class="bi bi-x-lg text-lg hidden"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Drawer Menu -->
        <div id="mobile-menu" class="hidden lg:hidden relative z-30 bg-slate-900/98 border-t border-slate-800/80 px-4 pt-3 pb-6 space-y-2 backdrop-blur-xl">
            <a href="../index.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
                <i class="bi bi-house-door text-brand-400 mr-2"></i> Trang chủ
            </a>
            <a href="../views/map.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
                <i class="bi bi-map-fill text-emerald-400 mr-2"></i> Bản Đồ
            </a>
            <a href="gtfs.php" class="block px-3 py-2.5 rounded-xl text-base font-medium bg-brand-600/50 text-white font-semibold transition">
                <i class="bi bi-layers-fill text-sky-400 mr-2"></i> Quản Trị GTFS
            </a>
            <div class="pt-3 border-t border-slate-800 flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-200">
                    <i class="bi bi-person-circle text-amber-400 mr-1.5"></i> <?php echo htmlspecialchars($admin_user['fullname'] ?? 'Admin'); ?> (Admin)
                </span>
                <a href="../auth/logout.php" class="text-sm font-medium text-rose-400 hover:text-rose-300">Đăng xuất</a>
            </div>
        </div>
    </header>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">

        <!-- ============================================================== -->
        <!-- COMMAND CENTER STATUS BANNER (PHIÊN BẢN ĐANG HOẠT ĐỘNG)        -->
        <!-- ============================================================== -->
        <section class="relative bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-7 shadow-xl overflow-hidden text-white">
            <!-- Hiệu ứng vệt sáng ambient glow -->
            <div class="absolute -right-16 -top-16 w-64 h-64 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-600 to-sky-400 flex items-center justify-center shrink-0 shadow-lg shadow-brand-500/20 text-white text-xl sm:text-2xl">
                        <i class="bi bi-broadcast"></i>
                    </div>
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Trạng Thái Vận Hành Hệ Thống</span>
                            <?php if ($active_version): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    <span class="relative flex h-2 w-2">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                    </span>
                                    ACTIVE ONLINE
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                    <i class="bi bi-exclamation-triangle-fill text-amber-400"></i> CHƯA KÍCH HOẠT
                                </span>
                            <?php endif; ?>
                        </div>

                        <h1 class="text-lg sm:text-2xl font-black text-white tracking-tight flex flex-wrap items-center gap-2">
                            <?php if ($active_version): ?>
                                <span><?php echo htmlspecialchars($active_version['version_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="text-xs font-mono font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">
                                    ID: #<?php echo $active_version['id']; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-rose-400 font-semibold text-base sm:text-lg">Chưa có phiên bản nào được kích hoạt. Hãy chọn 1 bản hợp lệ bên dưới!</span>
                            <?php endif; ?>
                        </h1>

                        <p class="text-xs sm:text-sm text-slate-300 flex flex-wrap items-center gap-x-4 gap-y-1">
                            <?php if ($active_version): ?>
                                <span><i class="bi bi-person-circle text-slate-400 mr-1"></i> Người tải: <strong class="text-white"><?php echo htmlspecialchars($active_version['uploader_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></strong></span>
                                <span><i class="bi bi-clock-history text-slate-400 mr-1"></i> Thời điểm nạp: <strong class="text-white"><?php echo date('d/m/Y H:i', strtotime($active_version['uploaded_at'])); ?></strong></span>
                                <?php if (!empty($active_version['notes'])): ?>
                                    <span class="text-slate-400 italic">“<?php echo htmlspecialchars($active_version['notes'], ENT_QUOTES, 'UTF-8'); ?>”</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-slate-400">Dữ liệu tra cứu công cộng đang tạm dừng cho tới khi một phiên bản GTFS hợp lệ được kích hoạt.</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5 shrink-0 pt-2 lg:pt-0">
                    <a href="../views/map.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 hover:text-sky-300 text-sm font-semibold border border-slate-700 transition shadow-sm">
                        <i class="bi bi-map-fill"></i> Xem Bản Đồ Tuyến
                    </a>
                    <a href="../index.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-sky-500 via-indigo-600 to-brand-600 hover:from-sky-400 hover:to-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-500/25 transition">
                        <i class="bi bi-box-arrow-up-right"></i> Trang Tra Cứu
                    </a>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- THÔNG BÁO FLASH ALERTS (SUCCESS & ERROR)                       -->
        <!-- ============================================================== -->
        <?php if ($success_msg): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-2xl p-4 shadow-sm flex items-start justify-between gap-3 animate-fade-in" role="alert">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0 mt-0.5">
                        <i class="bi bi-check-circle-fill text-lg"></i>
                    </div>
                    <div class="text-sm font-medium pt-1">
                        <?php echo $success_msg; ?>
                    </div>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700 p-1 rounded-lg hover:bg-emerald-100/50 transition">
                    <i class="bi bi-x-lg text-sm"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-900 rounded-2xl p-4 shadow-sm flex items-start justify-between gap-3 animate-fade-in" role="alert">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0 mt-0.5">
                        <i class="bi bi-exclamation-triangle-fill text-lg"></i>
                    </div>
                    <div class="text-sm font-medium pt-1">
                        <?php echo $error_msg; ?>
                    </div>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 p-1 rounded-lg hover:bg-rose-100/50 transition">
                    <i class="bi bi-x-lg text-sm"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- THANH CHUYỂN TAB ĐIỀU HƯỚNG SEGMENTED PILLS                    -->
        <!-- ============================================================== -->
        <nav class="flex flex-wrap items-center gap-2 bg-slate-200/80 p-1.5 rounded-2xl border border-slate-300/70 shadow-inner backdrop-blur-sm" aria-label="Tabs Quản Lý GTFS">
            <a href="gtfs.php?tab=versions" 
               class="<?php echo ($active_tab === 'versions') 
                    ? 'bg-white text-slate-900 font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-slate-900 hover:bg-white/60 font-semibold'; ?> px-4 py-2.5 rounded-xl text-sm flex items-center gap-2 transition">
                <i class="bi bi-layers-fill <?php echo ($active_tab === 'versions') ? 'text-brand-600' : 'text-slate-400'; ?>"></i>
                <span>Danh Sách Bản Lịch</span>
                <span class="text-xs px-2 py-0.5 rounded-full font-mono font-bold <?php echo ($active_tab === 'versions') ? 'bg-brand-50 text-brand-700 border border-brand-200' : 'bg-slate-300/60 text-slate-600'; ?>">
                    <?php echo count($all_versions); ?>
                </span>
            </a>

            <a href="gtfs.php?tab=import" 
               class="<?php echo ($active_tab === 'import') 
                    ? 'bg-white text-slate-900 font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-slate-900 hover:bg-white/60 font-semibold'; ?> px-4 py-2.5 rounded-xl text-sm flex items-center gap-2 transition">
                <i class="bi bi-cloud-arrow-up-fill <?php echo ($active_tab === 'import') ? 'text-indigo-600' : 'text-slate-400'; ?>"></i>
                <span>Nhập Bộ GTFS Mới</span>
            </a>

            <a href="gtfs.php?tab=compare" 
               class="<?php echo ($active_tab === 'compare') 
                    ? 'bg-white text-slate-900 font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-slate-900 hover:bg-white/60 font-semibold'; ?> px-4 py-2.5 rounded-xl text-sm flex items-center gap-2 transition">
                <i class="bi bi-arrow-left-right <?php echo ($active_tab === 'compare') ? 'text-sky-600' : 'text-slate-400'; ?>"></i>
                <span>So Sánh Hai Bản Lịch</span>
            </a>

            <?php if ($selected_error_version_id || $active_tab === 'errors'): ?>
                <a href="gtfs.php?tab=errors<?php echo $selected_error_version_id ? '&view_errors='.$selected_error_version_id : ''; ?>" 
                   class="<?php echo ($active_tab === 'errors') 
                        ? 'bg-white text-rose-700 font-bold shadow-sm' 
                        : 'text-rose-600 hover:text-rose-700 hover:bg-rose-50/60 font-semibold'; ?> px-4 py-2.5 rounded-xl text-sm flex items-center gap-2 transition">
                    <i class="bi bi-shield-x <?php echo ($active_tab === 'errors') ? 'text-rose-600' : 'text-rose-400'; ?>"></i>
                    <span>Chi Tiết Báo Cáo Lỗi</span>
                </a>
            <?php endif; ?>
        </nav>

        <!-- ============================================================== -->
        <!-- TAB 1: DANH SÁCH CÁC PHIÊN BẢN LỊCH TRÌNH                      -->
        <!-- ============================================================== -->
        <?php if ($active_tab === 'versions'): ?>
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07),0_2px_8px_rgba(15,23,42,0.04)] overflow-hidden">
                <!-- Header của Card -->
                <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-slate-50/50">
                    <div class="space-y-1">
                        <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                            <i class="bi bi-archive-fill text-brand-600"></i>
                            Kho Lưu Trữ Phiên Bản GTFS
                        </h2>
                        <p class="text-xs text-slate-500">Quản lý, kích hoạt vận hành, kiểm tra lỗi và sao lưu các bản phát hành lịch xe bus.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <!-- Ô tìm kiếm tức thì trên bảng -->
                        <div class="relative">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" id="filterVersionsInput" placeholder="Lọc phiên bản, uploader..." 
                                   class="pl-8 pr-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 bg-white shadow-sm w-48 sm:w-60">
                        </div>
                        <a href="gtfs.php?tab=import" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-sm transition">
                            <i class="bi bi-plus-lg"></i> Nhập Bản Mới (.zip)
                        </a>
                    </div>
                </div>

                <?php if (empty($all_versions)): ?>
                    <div class="p-12 text-center space-y-4">
                        <div class="w-16 h-16 mx-auto rounded-3xl bg-sky-50 text-sky-600 flex items-center justify-center text-3xl">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <div class="max-w-md mx-auto space-y-1">
                            <h3 class="text-base font-bold text-slate-900">Chưa có bộ dữ liệu GTFS nào trong hệ thống!</h3>
                            <p class="text-xs text-slate-500">Hệ thống cần ít nhất một bộ GTFS tĩnh để cung cấp thông tin tra cứu tuyến, trạm và lịch trình.</p>
                        </div>
                        <a href="gtfs.php?tab=import" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow transition">
                            <i class="bi bi-upload"></i> Nhập Bản Lịch Đầu Tiên
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse" id="versionsTable">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 text-[11px] font-bold uppercase tracking-wider border-b border-slate-200">
                                    <th class="py-3 px-4 w-14">ID</th>
                                    <th class="py-3 px-4 min-w-[220px]">Tên Phiên Bản & Ghi Chú</th>
                                    <th class="py-3 px-4 min-w-[130px]">Người Nhập</th>
                                    <th class="py-3 px-4 min-w-[140px]">Thời Gian Tải</th>
                                    <th class="py-3 px-4 min-w-[210px]">Thống Kê Dữ Liệu</th>
                                    <th class="py-3 px-4 min-w-[150px]">Trạng Thái</th>
                                    <th class="py-3 px-4 text-right min-w-[200px]">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-xs text-slate-700">
                                <?php foreach ($all_versions as $ver): 
                                    $stats = get_feed_stats($ver['id']);
                                    $errCount = count_validation_errors($ver['id']);
                                    $is_deleted = !empty($ver['deleted_at']);
                                    $is_active = ($ver['status'] === 'active');
                                ?>
                                    <tr class="version-row transition-colors <?php 
                                        if ($is_deleted) echo 'bg-slate-100/70 text-slate-400 opacity-75';
                                        elseif ($is_active) echo 'bg-emerald-50/40 hover:bg-emerald-50/70';
                                        else echo 'hover:bg-slate-50/80';
                                    ?>">
                                        <td class="py-3.5 px-4 font-mono font-bold <?php echo $is_active ? 'text-emerald-700' : 'text-slate-500'; ?>">
                                            #<?php echo $ver['id']; ?>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                                <?php echo htmlspecialchars($ver['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($is_active): ?>
                                                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($ver['notes'])): ?>
                                                <div class="text-[11px] text-slate-500 mt-0.5 line-clamp-1 italic">
                                                    “<?php echo htmlspecialchars($ver['notes'], ENT_QUOTES, 'UTF-8'); ?>”
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 font-medium text-slate-700">
                                            <i class="bi bi-person text-slate-400 mr-1"></i>
                                            <?php echo htmlspecialchars($ver['uploader_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-600 whitespace-nowrap">
                                            <?php echo date('d/m/Y H:i', strtotime($ver['uploaded_at'])); ?>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <?php if ($ver['status'] === 'invalid'): ?>
                                                <span class="inline-flex items-center gap-1 text-rose-600 font-semibold text-xs">
                                                    <i class="bi bi-exclamation-octagon-fill"></i> Dữ liệu không hợp lệ
                                                </span>
                                            <?php else: ?>
                                                <div class="flex flex-wrap items-center gap-1.5 font-mono">
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200" title="Tổng số tuyến">
                                                        <i class="bi bi-signpost-2"></i> <?php echo number_format($stats['routes']); ?> T
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200" title="Tổng số trạm dừng">
                                                        <i class="bi bi-geo-alt"></i> <?php echo number_format($stats['stops']); ?> Tr
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold bg-purple-50 text-purple-700 border border-purple-200" title="Tổng số chuyến">
                                                        <i class="bi bi-bus-front"></i> <?php echo number_format($stats['trips']); ?> Ch
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 whitespace-nowrap">
                                            <?php if ($is_deleted): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-200 text-slate-700 border border-slate-300">
                                                    <i class="bi bi-trash-fill text-[11px]"></i> ĐÃ XÓA
                                                </span>
                                                <div class="text-[10px] text-slate-400 mt-0.5"><?php echo date('d/m/Y H:i', strtotime($ver['deleted_at'])); ?></div>
                                            <?php elseif ($is_active): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-sm">
                                                    <i class="bi bi-check-circle-fill text-emerald-600 text-[11px]"></i> ĐANG DÙNG
                                                </span>
                                            <?php elseif ($ver['status'] === 'valid'): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-sky-100 text-sky-800 border border-sky-200">
                                                    <i class="bi bi-shield-check text-sky-600 text-[11px]"></i> HỢP LỆ
                                                </span>
                                            <?php elseif ($ver['status'] === 'invalid'): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300">
                                                    <i class="bi bi-shield-x text-rose-600 text-[11px]"></i> CÓ LỖI (<?php echo $errCount; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                                    <i class="bi bi-hourglass-split text-amber-600 text-[11px]"></i> CHỜ XỬ LÝ
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5">
                                                <?php if ($is_deleted): ?>
                                                    <!-- Đã xóa: Khôi phục + Xóa vĩnh viễn -->
                                                    <form method="POST" action="gtfs.php" class="inline">
                                                        <input type="hidden" name="action" value="restore_version">
                                                        <input type="hidden" name="version_id" value="<?php echo $ver['id']; ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-300 text-xs font-bold transition" title="Khôi phục phiên bản này">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Khôi Phục
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="gtfs.php" class="inline" onsubmit="return confirm('⚠️ Bạn có chắc muốn XÓA VĨNH VIỄN phiên bản #<?php echo $ver['id']; ?>?\n\nToàn bộ dữ liệu tuyến, trạm, chuyến sẽ bị dọn sạch và KHÔNG THỂ khôi phục!');">
                                                        <input type="hidden" name="action" value="purge_version">
                                                        <input type="hidden" name="version_id" value="<?php echo $ver['id']; ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 text-xs font-bold transition" title="Xóa vĩnh viễn khỏi CSDL">
                                                            <i class="bi bi-x-octagon-fill"></i> Dọn Sạch
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <!-- Trạng thái bình thường -->
                                                    <?php if ($ver['status'] === 'valid'): ?>
                                                        <form method="POST" action="gtfs.php" class="inline" onsubmit="return confirm('Kích hoạt phiên bản #<?php echo $ver['id']; ?> làm dữ liệu tra cứu chính thức cho toàn hệ thống?');">
                                                            <input type="hidden" name="action" value="activate_version">
                                                            <input type="hidden" name="version_id" value="<?php echo $ver['id']; ?>">
                                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition" title="Kích hoạt phiên bản này">
                                                                <i class="bi bi-play-circle-fill"></i> Kích Hoạt
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($ver['status'] === 'invalid' || $errCount > 0): ?>
                                                        <a href="gtfs.php?tab=errors&view_errors=<?php echo $ver['id']; ?>" class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold transition" title="Xem chi tiết các lỗi phát hiện">
                                                            <i class="bi bi-eye"></i> Lỗi (<?php echo $errCount; ?>)
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ($ver['status'] === 'valid' || $ver['status'] === 'active'): ?>
                                                        <a href="../views/gtfs_browse.php?version_id=<?php echo $ver['id']; ?>" class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Duyệt chi tiết các tuyến và trạm">
                                                            <i class="bi bi-search"></i> Xem
                                                        </a>
                                                        <a href="../export.php?version_id=<?php echo $ver['id']; ?>&type=schedule" class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Xuất toàn bộ lịch trình ra CSV">
                                                            <i class="bi bi-download"></i> Xuất
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ($ver['status'] !== 'active'): ?>
                                                        <form method="POST" action="gtfs.php" class="inline" onsubmit="return confirm('Đưa phiên bản #<?php echo $ver['id']; ?> vào thùng rác? (Có thể khôi phục lại sau).');">
                                                            <input type="hidden" name="action" value="delete_version">
                                                            <input type="hidden" name="version_id" value="<?php echo $ver['id']; ?>">
                                                            <button type="submit" class="px-2 py-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 text-xs font-semibold transition" title="Xóa tạm phiên bản">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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

        <!-- ============================================================== -->
        <!-- TAB 2: NHẬP BỘ GTFS MỚI (TỪ FOLDER HOẶC ZIP)                  -->
        <!-- ============================================================== -->
        <?php elseif ($active_tab === 'import'): ?>
            <div class="space-y-6">

                <!-- Bento Grid: Quy định kỹ thuật & Sandbox Protection -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="bi bi-file-earmark-text-fill"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900 uppercase">Tệp Bắt Buộc</div>
                            <div class="text-[11px] text-slate-500 font-mono mt-0.5">agency, routes, stops, trips, stop_times, calendar</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="bi bi-bezier2"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900 uppercase">Tệp Tùy Chọn</div>
                            <div class="text-[11px] text-slate-500 font-mono mt-0.5">calendar_dates, shapes (vẽ đường phố thật)</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="bi bi-link-45deg"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900 uppercase">Toàn Vẹn Khóa Ngoại</div>
                            <div class="text-[11px] text-slate-500 mt-0.5">Tự động kiểm tra liên kết route_id, stop_id, trip_id</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900 uppercase">Bảo Vệ Độc Lập</div>
                            <div class="text-[11px] text-slate-500 mt-0.5">Bản đang dùng không bị ảnh hưởng nếu nạp bản lỗi</div>
                        </div>
                    </div>
                </div>

                <!-- 2 Cột Phương Thức Nhập -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- CÁCH 1: NHẬP TỪ THƯ MỤC CÓ SẴN TRONG gtfs_data/ -->
                    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 sm:p-7 flex flex-col justify-between">
                        <div class="space-y-4">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                                    <i class="bi bi-folder-check"></i>
                                </div>
                                <div>
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-600">Cách 1: Khuyên Dùng Cho Dữ Liệu Lớn</span>
                                    <h3 class="text-base font-bold text-slate-900">Nhập Từ Thư Mục gtfs_data/</h3>
                                </div>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed">
                                Hệ thống quét trực tiếp các thư mục GTFS giải nén sẵn trong thư mục dự án. Nhanh hơn và không bị giới hạn dung lượng tải lên qua HTTP POST của máy chủ.
                            </p>

                            <?php if (!empty($detected_gtfs_dirs)): ?>
                                <form action="gtfs.php" method="POST" class="space-y-4 pt-2">
                                    <input type="hidden" name="action" value="import_folder">

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                            Chọn Thư Mục GTFS Được Nhận Diện: <span class="text-rose-500">*</span>
                                        </label>
                                        <select name="folder_name" class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white shadow-sm" required>
                                            <?php foreach ($detected_gtfs_dirs as $d): ?>
                                                <option value="<?php echo htmlspecialchars($d); ?>">
                                                    📁 gtfs_data/<?php echo htmlspecialchars($d); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                            Đặt Tên Phiên Bản Lịch: <span class="text-rose-500">*</span>
                                        </label>
                                        <input type="text" name="version_name" placeholder="Ví dụ: GTFS Machida City - Bản 1.0 (Có chuyến đêm 25:10)" 
                                               class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white shadow-sm" required>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                            Ghi Chú Bổ Sung:
                                        </label>
                                        <input type="text" name="notes" placeholder="Ví dụ: Dữ liệu thực tế có shapes và calendar_dates đầy đủ" 
                                               class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white shadow-sm">
                                    </div>

                                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition">
                                        <i class="bi bi-shield-check text-sm"></i> Nạp Dữ Liệu & Kiểm Tra Thư Mục Này
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs space-y-1">
                                    <div class="font-bold flex items-center gap-1.5">
                                        <i class="bi bi-exclamation-triangle-fill text-amber-600"></i> Chưa tìm thấy thư mục nào
                                    </div>
                                    <p class="text-amber-800">Hãy tạo thư mục con chứa các tệp .txt trong <code>gtfs_data/</code> hoặc dùng Cách 2 tải tệp .zip bên phải.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CÁCH 2: TẢI LÊN FILE .ZIP -->
                    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 sm:p-7 flex flex-col justify-between">
                        <div class="space-y-4">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-2xl bg-sky-50 text-sky-600 flex items-center justify-center text-xl shrink-0">
                                    <i class="bi bi-file-earmark-zip-fill"></i>
                                </div>
                                <div>
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-sky-600">Cách 2: Tệp Nén Trực Tuyến</span>
                                    <h3 class="text-base font-bold text-slate-900">Tải Lên Tệp Nén ZIP GTFS (.zip)</h3>
                                </div>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed">
                                Tải gói tệp nén trực tiếp từ máy của bạn. Hệ thống sẽ tự động giải nén trong sandbox, xác thực dữ liệu và kiểm tra toàn vẹn trước khi lưu.
                            </p>

                            <form action="gtfs.php" method="POST" enctype="multipart/form-data" class="space-y-4 pt-2">
                                <input type="hidden" name="action" value="import_gtfs">

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Tên Phiên Bản Lịch Trình: <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="text" id="version_name" name="version_name" placeholder="Ví dụ: GTFS Tuyến Metro & Bus - Bản Mùa Hè 2026" 
                                           class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 bg-white shadow-sm" required>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Chọn Tệp Nén ZIP (.zip): <span class="text-rose-500">*</span>
                                    </label>
                                    <label for="gtfs_zip" class="group relative flex flex-col items-center justify-center p-6 border-2 border-dashed border-slate-300 hover:border-sky-500 rounded-2xl bg-slate-50/50 hover:bg-sky-50/30 cursor-pointer transition text-center">
                                        <i class="bi bi-cloud-arrow-up text-3xl text-slate-400 group-hover:text-sky-600 transition"></i>
                                        <span class="mt-2 text-xs font-bold text-slate-700 group-hover:text-sky-700" id="zipFileNamePreview">
                                            Nhấp để chọn tệp .zip hoặc kéo thả vào đây
                                        </span>
                                        <span class="text-[11px] text-slate-400 mt-0.5">Chứa agency.txt, routes.txt, trips.txt, stops.txt...</span>
                                        <input type="file" id="gtfs_zip" name="gtfs_zip" accept=".zip" class="hidden" required>
                                    </label>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Ghi Chú (Tùy Chọn):
                                    </label>
                                    <textarea id="notes" name="notes" rows="2" placeholder="Ví dụ: Bổ sung tuyến metro 01, điều chỉnh giờ chuyến đêm..." 
                                              class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 bg-white shadow-sm"></textarea>
                                </div>

                                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold shadow-md shadow-sky-600/20 transition">
                                    <i class="bi bi-upload text-sm"></i> Tải Lên & Tiến Hành Kiểm Tra Toàn Bộ
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

        <!-- ============================================================== -->
        <!-- TAB 3: SO SÁNH HAI BẢN LỊCH TRÌNH                             -->
        <!-- ============================================================== -->
        <?php elseif ($active_tab === 'compare'): ?>
            <div class="space-y-6">

                <!-- Bộ Chọn So Sánh 2 Bản Lịch -->
                <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 sm:p-7">
                    <div class="max-w-3xl mb-6">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-sky-600">Công Cụ Phân Tích Đối Chiếu</span>
                        <h2 class="text-lg font-bold text-slate-900 mt-1 flex items-center gap-2">
                            <i class="bi bi-arrow-left-right text-sky-600"></i> So Sánh Tuyến & Chuyến Giữa Hai Phiên Bản Lịch
                        </h2>
                        <p class="text-xs text-slate-500 mt-1">Phát hiện ngay những tuyến xe mới mở, tuyến bị tạm dừng hoặc các chuyến thay đổi mốc giờ xuất bến.</p>
                    </div>

                    <form method="GET" action="gtfs.php" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end bg-slate-50/70 p-4 sm:p-5 rounded-2xl border border-slate-200">
                        <input type="hidden" name="tab" value="compare">

                        <div class="md:col-span-5 space-y-1.5">
                            <label class="block text-xs font-bold text-slate-700 uppercase">
                                <span class="inline-block w-2 h-2 rounded-full bg-slate-400 mr-1"></span>
                                Phiên Bản Cũ (Gốc / V1):
                            </label>
                            <select name="v1" class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 bg-white shadow-sm" required>
                                <option value="">-- Chọn phiên bản thứ nhất --</option>
                                <?php foreach ($all_versions as $v): if ($v['status'] === 'invalid' || !empty($v['deleted_at'])) continue; ?>
                                    <option value="<?php echo $v['id']; ?>" <?php echo ($compare_v1 === (int)$v['id']) ? 'selected' : ''; ?>>
                                        #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php echo ($v['status'] === 'active') ? ' ★ (Đang dùng)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="md:col-span-2 flex items-center justify-center py-2 md:py-0">
                            <div class="w-10 h-10 rounded-full bg-white border border-slate-200 text-slate-600 flex items-center justify-center font-bold text-xs shadow-sm">
                                VS
                            </div>
                        </div>

                        <div class="md:col-span-5 space-y-1.5">
                            <label class="block text-xs font-bold text-slate-700 uppercase">
                                <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>
                                Phiên Bản Mới (So Sánh / V2):
                            </label>
                            <select name="v2" class="w-full text-xs font-medium px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 bg-white shadow-sm" required>
                                <option value="">-- Chọn phiên bản thứ hai --</option>
                                <?php foreach ($all_versions as $v): if ($v['status'] === 'invalid' || !empty($v['deleted_at'])) continue; ?>
                                    <option value="<?php echo $v['id']; ?>" <?php echo ($compare_v2 === (int)$v['id']) ? 'selected' : ''; ?>>
                                        #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php echo ($v['status'] === 'active') ? ' ★ (Đang dùng)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="md:col-span-12 pt-2 flex justify-end">
                            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold shadow-md shadow-sky-600/20 transition">
                                <i class="bi bi-search"></i> Bắt Đầu So Sánh
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Kết Quả So Sánh -->
                <?php if ($comparison_result && isset($v1_info, $v2_info)): ?>
                    <div class="space-y-6 animate-fade-in">

                        <!-- Header Kết Quả + Nút Xuất CSV -->
                        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-2 text-sm font-bold text-slate-800">
                                <span><?php echo htmlspecialchars($v1_info['version_name']); ?></span>
                                <i class="bi bi-arrow-right text-slate-400"></i>
                                <span class="text-emerald-600"><?php echo htmlspecialchars($v2_info['version_name']); ?></span>
                            </div>
                            <a href="../export.php?type=comparison&v1=<?php echo $compare_v1; ?>&v2=<?php echo $compare_v2; ?>" 
                               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition shrink-0">
                                <i class="bi bi-file-earmark-spreadsheet text-emerald-600"></i> Xuất Báo Cáo CSV
                            </a>
                        </div>

                        <!-- 2 Thẻ Thống Kê Delta (Routes & Trips) -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                            <!-- Delta Tuyến (Routes) -->
                            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 space-y-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-base">
                                            <i class="bi bi-signpost-split"></i>
                                        </div>
                                        <h3 class="font-bold text-slate-900 text-sm">Biến Động Tuyến Tuyến (Routes)</h3>
                                    </div>
                                    <span class="text-xs font-mono font-bold text-slate-500">
                                        <?php echo $comparison_result['routes']['total_v1']; ?> ➔ <?php echo $comparison_result['routes']['total_v2']; ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-100 text-center">
                                    <div class="p-3 rounded-2xl bg-emerald-50/70 border border-emerald-100">
                                        <div class="text-[10px] font-bold uppercase text-emerald-600">Thêm Mới</div>
                                        <div class="text-lg font-black text-emerald-700 font-mono mt-0.5">
                                            +<?php echo count($comparison_result['routes']['added']); ?>
                                        </div>
                                    </div>
                                    <div class="p-3 rounded-2xl bg-rose-50/70 border border-rose-100">
                                        <div class="text-[10px] font-bold uppercase text-rose-600">Hủy Bỏ</div>
                                        <div class="text-lg font-black text-rose-700 font-mono mt-0.5">
                                            -<?php echo count($comparison_result['routes']['removed']); ?>
                                        </div>
                                    </div>
                                    <div class="p-3 rounded-2xl bg-amber-50/70 border border-amber-100">
                                        <div class="text-[10px] font-bold uppercase text-amber-600">Chỉnh Sửa</div>
                                        <div class="text-lg font-black text-amber-700 font-mono mt-0.5">
                                            ~<?php echo count($comparison_result['routes']['modified']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Delta Chuyến (Trips) -->
                            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 space-y-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-9 h-9 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-base">
                                            <i class="bi bi-bus-front"></i>
                                        </div>
                                        <h3 class="font-bold text-slate-900 text-sm">Biến Động Chuyến Xe (Trips)</h3>
                                    </div>
                                    <span class="text-xs font-mono font-bold text-slate-500">
                                        <?php echo number_format($comparison_result['trips']['total_v1']); ?> ➔ <?php echo number_format($comparison_result['trips']['total_v2']); ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-100 text-center">
                                    <div class="p-3 rounded-2xl bg-emerald-50/70 border border-emerald-100">
                                        <div class="text-[10px] font-bold uppercase text-emerald-600">Thêm Mới</div>
                                        <div class="text-lg font-black text-emerald-700 font-mono mt-0.5">
                                            +<?php echo count($comparison_result['trips']['added']); ?>
                                        </div>
                                    </div>
                                    <div class="p-3 rounded-2xl bg-rose-50/70 border border-rose-100">
                                        <div class="text-[10px] font-bold uppercase text-rose-600">Hủy Bỏ</div>
                                        <div class="text-lg font-black text-rose-700 font-mono mt-0.5">
                                            -<?php echo count($comparison_result['trips']['removed']); ?>
                                        </div>
                                    </div>
                                    <div class="p-3 rounded-2xl bg-amber-50/70 border border-amber-100">
                                        <div class="text-[10px] font-bold uppercase text-amber-600">Đổi Giờ / Trạm</div>
                                        <div class="text-lg font-black text-amber-700 font-mono mt-0.5">
                                            ~<?php echo count($comparison_result['trips']['modified']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Chi Tiết Thay Đổi Tuyến -->
                        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 space-y-4">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2 border-b border-slate-100 pb-3">
                                <i class="bi bi-list-check text-indigo-600"></i>
                                Chi Tiết Thay Đổi Về Tuyến Đường
                            </h3>

                            <div class="space-y-3">
                                <?php if (!empty($comparison_result['routes']['added'])): ?>
                                    <div class="p-4 rounded-2xl bg-emerald-50/80 border border-emerald-200 text-xs space-y-2">
                                        <div class="font-bold text-emerald-900 flex items-center gap-1.5">
                                            <i class="bi bi-plus-circle-fill text-emerald-600"></i>
                                            Tuyến mới được thêm vào (<?php echo count($comparison_result['routes']['added']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-emerald-950 font-medium">
                                            <?php foreach ($comparison_result['routes']['added'] as $r): ?>
                                                <li>
                                                    Mã <code class="font-mono font-bold bg-emerald-100/80 px-1.5 py-0.5 rounded text-emerald-800"><?php echo htmlspecialchars($r['route_id']); ?></code>
                                                    — <?php echo htmlspecialchars($r['route_short_name'] . ' (' . $r['route_long_name'] . ')'); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($comparison_result['routes']['removed'])): ?>
                                    <div class="p-4 rounded-2xl bg-rose-50/80 border border-rose-200 text-xs space-y-2">
                                        <div class="font-bold text-rose-900 flex items-center gap-1.5">
                                            <i class="bi bi-dash-circle-fill text-rose-600"></i>
                                            Tuyến bị xóa bỏ (<?php echo count($comparison_result['routes']['removed']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-rose-950 font-medium">
                                            <?php foreach ($comparison_result['routes']['removed'] as $r): ?>
                                                <li>
                                                    Mã <code class="font-mono font-bold bg-rose-100/80 px-1.5 py-0.5 rounded text-rose-800"><?php echo htmlspecialchars($r['route_id']); ?></code>
                                                    — <?php echo htmlspecialchars($r['route_short_name'] . ' (' . $r['route_long_name'] . ')'); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($comparison_result['routes']['modified'])): ?>
                                    <div class="p-4 rounded-2xl bg-amber-50/80 border border-amber-200 text-xs space-y-2">
                                        <div class="font-bold text-amber-900 flex items-center gap-1.5">
                                            <i class="bi bi-pencil-square text-amber-600"></i>
                                            Tuyến có chỉnh sửa thông tin (<?php echo count($comparison_result['routes']['modified']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-amber-950 font-medium">
                                            <?php foreach ($comparison_result['routes']['modified'] as $rid => $changes): ?>
                                                <li>
                                                    Mã <code class="font-mono font-bold bg-amber-100/80 px-1.5 py-0.5 rounded text-amber-800"><?php echo htmlspecialchars($rid); ?></code>: 
                                                    <?php echo implode(', ', $changes); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if (empty($comparison_result['routes']['added']) && empty($comparison_result['routes']['removed']) && empty($comparison_result['routes']['modified'])): ?>
                                    <div class="text-xs text-slate-500 py-3 text-center italic">Không phát hiện sự thay đổi nào về danh mục tuyến giữa hai phiên bản này.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Chi Tiết Thay Đổi Chuyến Xe -->
                        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 space-y-4">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2 border-b border-slate-100 pb-3">
                                <i class="bi bi-clock-history text-sky-600"></i>
                                Chi Tiết Thay Đổi Về Chuyến Xe (Trips)
                            </h3>

                            <div class="space-y-3">
                                <?php if (!empty($comparison_result['trips']['added'])): ?>
                                    <div class="p-4 rounded-2xl bg-emerald-50/80 border border-emerald-200 text-xs space-y-2">
                                        <div class="font-bold text-emerald-900 flex items-center gap-1.5">
                                            <i class="bi bi-plus-circle-fill text-emerald-600"></i>
                                            Chuyến xe mới thêm (<?php echo count($comparison_result['trips']['added']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-emerald-950 font-medium">
                                            <?php foreach (array_slice($comparison_result['trips']['added'], 0, 10) as $t): ?>
                                                <li>
                                                    Chuyến <code class="font-mono font-bold bg-emerald-100/80 px-1.5 py-0.5 rounded text-emerald-800"><?php echo htmlspecialchars($t['trip_id']); ?></code>
                                                    (Tuyến: <?php echo htmlspecialchars($t['route_short_name'] ?? $t['route_id'] ?? ''); ?> - Hướng: <?php echo htmlspecialchars($t['trip_headsign'] ?? 'N/A'); ?>)
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if (count($comparison_result['trips']['added']) > 10): ?>
                                                <li class="text-slate-500 italic list-none pt-1">
                                                    ...và còn <?php echo count($comparison_result['trips']['added']) - 10; ?> chuyến khác (vui lòng xuất file CSV để xem đầy đủ).
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($comparison_result['trips']['removed'])): ?>
                                    <div class="p-4 rounded-2xl bg-rose-50/80 border border-rose-200 text-xs space-y-2">
                                        <div class="font-bold text-rose-900 flex items-center gap-1.5">
                                            <i class="bi bi-dash-circle-fill text-rose-600"></i>
                                            Chuyến xe bị hủy (<?php echo count($comparison_result['trips']['removed']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-rose-950 font-medium">
                                            <?php foreach (array_slice($comparison_result['trips']['removed'], 0, 10) as $t): ?>
                                                <li>Chuyến <code class="font-mono font-bold bg-rose-100/80 px-1.5 py-0.5 rounded text-rose-800"><?php echo htmlspecialchars($t['trip_id']); ?></code></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($comparison_result['trips']['modified'])): ?>
                                    <div class="p-4 rounded-2xl bg-amber-50/80 border border-amber-200 text-xs space-y-2">
                                        <div class="font-bold text-amber-900 flex items-center gap-1.5">
                                            <i class="bi bi-pencil-square text-amber-600"></i>
                                            Chuyến thay đổi giờ hoặc trạm dừng (<?php echo count($comparison_result['trips']['modified']); ?>):
                                        </div>
                                        <ul class="space-y-1 pl-5 list-disc text-amber-950 font-medium">
                                            <?php foreach (array_slice($comparison_result['trips']['modified'], 0, 10) as $tid => $change): ?>
                                                <li>
                                                    Chuyến <code class="font-mono font-bold bg-amber-100/80 px-1.5 py-0.5 rounded text-amber-800"><?php echo htmlspecialchars($tid); ?></code>: 
                                                    <?php echo htmlspecialchars($change); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                <?php elseif (isset($_GET['v1'], $_GET['v2']) && $compare_v1 === $compare_v2): ?>
                    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-5 text-center text-xs font-semibold">
                        <i class="bi bi-exclamation-triangle-fill text-amber-600 text-lg mr-1.5 align-middle"></i>
                        Vui lòng chọn 2 phiên bản khác nhau để thực hiện so sánh đối chiếu.
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-3xl border border-slate-200/80 p-8 text-center text-slate-500 space-y-2">
                        <i class="bi bi-arrow-left-right text-3xl text-slate-300"></i>
                        <p class="text-xs">Vui lòng chọn 2 phiên bản từ bộ chọn ở trên và nhấn <strong>"Bắt Đầu So Sánh"</strong> để phân tích sự khác biệt.</p>
                    </div>
                <?php endif; ?>

            </div>

        <!-- ============================================================== -->
        <!-- TAB 4: BÁO CÁO LỖI KIỂM TRA (VALIDATION ERRORS)                -->
        <!-- ============================================================== -->
        <?php elseif ($active_tab === 'errors'): ?>
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07)] p-6 sm:p-7 space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-base font-bold text-rose-700 flex items-center gap-2">
                            <i class="bi bi-shield-x"></i>
                            Báo Cáo Chi Tiết Lỗi Kiểm Tra Bộ Dữ Liệu
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Xác định chính xác tệp tin, dòng và cột vi phạm chuẩn quy định GTFS.</p>
                    </div>
                    <?php if ($selected_error_version_id && !empty($version_errors)): ?>
                        <a href="../export.php?type=errors&version_id=<?php echo $selected_error_version_id; ?>" 
                           class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold transition shrink-0">
                            <i class="bi bi-download"></i> Xuất Báo Cáo Lỗi Ra CSV
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Form chọn phiên bản xem lỗi -->
                <form method="GET" action="gtfs.php" class="flex flex-wrap items-center gap-3">
                    <input type="hidden" name="tab" value="errors">
                    <label class="text-xs font-bold text-slate-700 uppercase">Chọn phiên bản:</label>
                    <select name="view_errors" class="text-xs font-medium px-3.5 py-2 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500 bg-white shadow-sm min-w-[280px]" onchange="this.form.submit()">
                        <option value="">-- Chọn phiên bản cần kiểm tra --</option>
                        <?php foreach ($all_versions as $v): ?>
                            <option value="<?php echo $v['id']; ?>" <?php echo ($selected_error_version_id === (int)$v['id']) ? 'selected' : ''; ?>>
                                #<?php echo $v['id']; ?> - <?php echo htmlspecialchars($v['version_name']); ?> (Trạng thái: <?php echo strtoupper($v['status']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($selected_error_version_id && $error_version_info): ?>
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-xs text-slate-700 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            Đang xem lỗi của: <strong class="text-slate-900"><?php echo htmlspecialchars($error_version_info['version_name']); ?></strong>
                            <span class="text-slate-400 mx-1">•</span>
                            Trạng thái: <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 font-mono"><?php echo strtoupper($error_version_info['status']); ?></span>
                        </div>
                        <div class="font-semibold">
                            Tổng số lỗi phát hiện: <span class="font-mono font-bold text-rose-600"><?php echo count($version_errors); ?></span>
                        </div>
                    </div>

                    <?php if (empty($version_errors)): ?>
                        <div class="p-8 text-center space-y-2 rounded-2xl bg-emerald-50/50 border border-emerald-100">
                            <div class="w-12 h-12 mx-auto rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-2xl">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <h4 class="text-sm font-bold text-emerald-900">Phiên bản này hoàn toàn hợp lệ!</h4>
                            <p class="text-xs text-emerald-700">Không có bất kỳ lỗi nào về tệp, cấu trúc cột hay mã tham chiếu.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto custom-scrollbar border border-slate-200 rounded-2xl">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-rose-50/80 text-rose-900 text-[11px] font-bold uppercase tracking-wider border-b border-rose-200">
                                        <th class="py-2.5 px-3 w-12 text-center">STT</th>
                                        <th class="py-2.5 px-3 min-w-[120px]">Loại Lỗi</th>
                                        <th class="py-2.5 px-3 min-w-[130px]">Tệp Tin</th>
                                        <th class="py-2.5 px-3 min-w-[120px]">Cột</th>
                                        <th class="py-2.5 px-3 min-w-[90px]">Dòng</th>
                                        <th class="py-2.5 px-3 min-w-[280px]">Mô Tả Lỗi Chi Tiết</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <?php $idx = 1; foreach ($version_errors as $err): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="py-2.5 px-3 text-center font-mono font-bold text-slate-500"><?php echo $idx++; ?></td>
                                            <td class="py-2.5 px-3">
                                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 font-mono">
                                                    <?php echo htmlspecialchars($err['error_type']); ?>
                                                </span>
                                            </td>
                                            <td class="py-2.5 px-3 font-mono font-semibold text-slate-800">
                                                <code><?php echo htmlspecialchars($err['file_name'] ?? 'N/A'); ?></code>
                                            </td>
                                            <td class="py-2.5 px-3 font-mono text-slate-600">
                                                <code><?php echo htmlspecialchars($err['column_name'] ?? 'N/A'); ?></code>
                                            </td>
                                            <td class="py-2.5 px-3 font-mono text-slate-600">
                                                <?php echo $err['row_number'] ? 'Dòng ' . $err['row_number'] : '—'; ?>
                                            </td>
                                            <td class="py-2.5 px-3 font-medium text-rose-700">
                                                <?php echo htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="py-8 text-center text-xs text-slate-500">
                        Vui lòng chọn một phiên bản từ danh sách trên để xem chi tiết các lỗi kiểm tra.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Script Toggle Menu Mobile -->
    <script>
        const menuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        const iconBars = document.getElementById('menu-icon-bars');
        const iconClose = document.getElementById('menu-icon-close');

        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', function() {
                const isOpen = !mobileMenu.classList.contains('hidden');
                if (isOpen) {
                    mobileMenu.classList.add('hidden');
                    iconBars.classList.remove('hidden');
                    iconClose.classList.add('hidden');
                } else {
                    mobileMenu.classList.remove('hidden');
                    iconBars.classList.add('hidden');
                    iconClose.classList.remove('hidden');
                }
            });
        }

        // Live Filter cho bảng phiên bản GTFS
        const filterInput = document.getElementById('filterVersionsInput');
        const versionsTable = document.getElementById('versionsTable');
        if (filterInput && versionsTable) {
            filterInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const rows = versionsTable.querySelectorAll('tbody tr.version-row');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
            });
        }

        // Preview tên tệp ZIP khi người dùng chọn tải lên
        const zipFileInput = document.getElementById('gtfs_zip');
        const zipPreview = document.getElementById('zipFileNamePreview');
        if (zipFileInput && zipPreview) {
            zipFileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    zipPreview.innerHTML = `<i class="bi bi-file-earmark-zip-fill text-indigo-600 mr-1"></i> Đã chọn: <span class="text-indigo-600">${file.name}</span> (${sizeMB} MB)`;
                } else {
                    zipPreview.textContent = 'Nhấp để chọn tệp .zip hoặc kéo thả vào đây';
                }
            });
        }
    </script>
</body>
</html>
