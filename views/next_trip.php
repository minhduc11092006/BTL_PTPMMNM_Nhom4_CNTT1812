<?php
// next_trip.php - Tìm chuyến tiếp theo theo ngày hoạt động, ngày ngoại lệ và giờ vượt 24h
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();
$version_id = isset($_GET['version_id']) ? (int)$_GET['version_id'] : ($active_version['id'] ?? 0);

$stop_id = $_GET['stop_id'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$time = $_GET['time'] ?? date('H:i');
$save_success = '';
$save_error = '';

// Xử lý Lưu truy vấn mẫu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_query') {
    if (!$user) {
        $save_error = 'Vui lòng đăng nhập để lưu truy vấn mẫu này.';
    } else {
        $q_name = trim($_POST['query_name'] ?? '');
        if ($q_name === '') {
            $q_name = "Tra cứu trạm " . $stop_id . " lúc " . $time . " ngày " . $date;
        }
        $params = [
            'stop_id' => $stop_id,
            'date' => $date,
            'time' => $time
        ];
        if (save_query($user['id'], $q_name, 'next_trip', $params)) {
            $save_success = "Đã lưu truy vấn mẫu: \"<strong>" . htmlspecialchars($q_name) . "</strong>\" thành công!";
        } else {
            $save_error = "Không thể lưu truy vấn mẫu.";
        }
    }
}

// Lấy danh sách trạm dừng nếu có bản active
$stops = [];
$selected_stop = null;
$next_trips = [];
$searched = false;

if ($active_version) {
    $stops = get_all_stops($active_version['id']);

    if ($stop_id !== '') {
        $selected_stop = get_stop($stop_id, $active_version['id']);
        if ($selected_stop) {
            $searched = true;
            $next_trips = find_next_trips($stop_id, $date, $time, $active_version['id'], 15);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tìm Chuyến Kế Tiếp - Tra Cứu Lịch Trình GTFS</title>
    <!-- Bootstrap 5 CSS -->
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .top-navbar {
            background: #084298;
        }
        .search-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            background: #fff;
        }
        .trip-result-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #fff;
            transition: all 0.2s ease-in-out;
            border-left: 5px solid #0d6efd;
        }
        .trip-result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.09);
        }
        .trip-overnight {
            border-left-color: #6f42c1 !important;
            background: #fdfaff;
        }
        .time-badge {
            font-size: 1.1rem;
            font-weight: 700;
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
    </style>
</head>
<body>

    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800/80 transition-all overflow-hidden relative shadow-lg">
    
    <!-- LỚP PARALLAX CẢNH VÔ TẬN & XE BUS ĐÔ THỊ ĐANG CHẠY LIÊN TỤC -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
        <!-- Lớp Parallax 1: Bầu trời sao đêm & dải mây -->
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

        <!-- Lớp Parallax 3: Làn đường cao tốc / Vạch sơn phản quang neon cuộn liên tục -->
        <div class="absolute bottom-0 inset-x-0 h-[3px] bg-slate-950/90 border-t border-slate-800/80 flex items-center overflow-hidden">
            <div class="w-full h-full animate-highway-track opacity-85"></div>
        </div>
        <div class="absolute bottom-1 inset-x-0 h-[1px] bg-gradient-to-r from-transparent via-cyan-500/25 to-transparent"></div>

        <!-- XE BUÝT ĐÔ THỊ ĐANG CHẠY LIÊN TỤC TRÊN NỀN PARALLAX VÔ TẬN -->
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

    <!-- NỘI DUNG NAVBAR & LINKS -->
    <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 sm:h-[70px] gap-2">
            
            <!-- Logo -->
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
                            <span class="hidden md:inline-block text-[9px] uppercase font-bold px-1.5 py-0.5 rounded-full bg-brand-500/20 text-brand-300 border border-brand-500/30">Bus &amp; Metro</span>
                        </span>
                        <span class="text-[10px] text-slate-400 -mt-0.5 font-medium hidden 2xl:block whitespace-nowrap">Tra Cứu Lịch Trình Giao Thông Công Cộng</span>
                    </div>
                </a>
            </div>

            <!-- Desktop Links (Thẻ nav và nút không có viền border) -->
            <nav class="hidden lg:flex items-center gap-1 bg-slate-900/90 backdrop-blur-md p-1 rounded-xl shadow-md shrink-0">
                <a href="../index.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                    <i class="bi bi-house-door text-brand-400 text-sm"></i>
                    <span>Trang chủ</span>
                </a>
                
                <a href="gtfs_browse.php?tab=routes&version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                    <i class="bi bi-compass-fill text-sky-400 text-sm"></i>
                    <span>Tuyến & Trạm</span>
                </a>
                
                <a href="map.php?version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                    <i class="bi bi-map-fill text-emerald-400 text-sm"></i>
                    <span>Bản Đồ</span>
                </a>

                <a href="next_trip.php" class="h-9 px-3 text-xs xl:text-sm font-semibold rounded-lg bg-brand-600/50 text-white shadow-sm inline-flex items-center gap-1.5 transition hover:bg-brand-600/60 whitespace-nowrap shrink-0">
                    <i class="bi bi-clock-history text-amber-400 text-sm"></i>
                    <span>Chuyến Kế Tiếp</span>
                </a>
                
                <?php if ($user): ?>
                    <a href="saved_queries.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-bookmark-star-fill text-amber-400 text-sm"></i>
                        <span>Truy Vấn Đã Lưu</span>
                    </a>
                <?php endif; ?>
            </nav>

            <!-- Trạng Thái User / Nút Đăng nhập -->
            <div class="flex items-center gap-2 xl:gap-2.5 shrink-0">
                <div class="hidden 2xl:flex items-center gap-2 px-2.5 py-1.5 h-9 rounded-xl bg-slate-900/85 shadow-inner backdrop-blur-md shrink-0">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span class="text-xs font-bold text-slate-200 tracking-tight whitespace-nowrap flex items-center gap-1">
                        <i class="bi bi-broadcast text-emerald-400"></i>
                        <span>Radar</span>
                        <span class="text-[9px] font-bold text-emerald-300 bg-emerald-500/20 px-1 py-0.2 rounded border border-emerald-500/30">LIVE</span>
                    </span>
                </div>

                <?php if ($user): ?>
                    <div class="hidden sm:flex items-center gap-1.5 xl:gap-2 pl-2 border-l border-slate-800 shrink-0">
                        <div class="flex items-center gap-2 px-2.5 h-9 rounded-xl bg-slate-900/85 backdrop-blur-md shrink-0">
                            <div class="w-6 h-6 rounded-full bg-brand-600/40 text-brand-300 flex items-center justify-center font-bold text-xs shrink-0">
                                <i class="bi bi-person-fill"></i>
                            </div>
                            <div class="flex items-center gap-1.5 whitespace-nowrap">
                                <span class="text-xs font-semibold text-slate-200 truncate max-w-[110px]"><?php echo htmlspecialchars($user['fullname']); ?></span>
                                <span class="text-[10px] text-amber-300 bg-amber-500/10 px-1.5 py-0.2 rounded font-medium capitalize"><?php echo htmlspecialchars($user['role'] ?? 'Member'); ?></span>
                            </div>
                        </div>

                        <?php if (isset($user['role']) && $user['role'] === 'admin'): ?>
                            <a href="../admin/gtfs.php" class="h-9 inline-flex items-center gap-1.5 px-3 text-xs font-bold rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 hover:from-amber-400 hover:to-amber-500 shadow-sm transition whitespace-nowrap shrink-0">
                                <i class="bi bi-speedometer2 text-sm"></i>
                                <span>Quản Trị GTFS</span>
                            </a>
                        <?php endif; ?>

                        <a href="../auth/logout.php" title="Đăng xuất" class="h-9 w-9 inline-flex items-center justify-center text-slate-400 hover:text-rose-400 hover:bg-slate-800/80 rounded-xl transition shrink-0">
                            <i class="bi bi-box-arrow-right text-base"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <a href="../auth/login.php" class="h-9 px-4 inline-flex items-center justify-center text-xs font-semibold rounded-lg bg-slate-800/80 hover:bg-slate-700 text-slate-200 transition shrink-0 ml-2">
                        Đăng nhập
                    </a>
                <?php endif; ?>

                <!-- Mobile Menu Button -->
                <button id="mobile-menu-btn" type="button" class="sm:hidden h-9 w-9 inline-flex items-center justify-center rounded-xl text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700 focus:outline-none">
                    <i id="menu-icon-bars" class="bi bi-list text-xl"></i>
                    <i id="menu-icon-close" class="bi bi-x-lg text-lg hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Drawer Menu -->
    <div id="mobile-menu" class="hidden sm:hidden relative z-30 bg-slate-900/98 border-t border-slate-800/80 px-4 pt-3 pb-6 space-y-2 backdrop-blur-xl">
        <a href="../index.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
            <i class="bi bi-house-door text-brand-400 mr-2"></i> Trang chủ
        </a>
        <a href="gtfs_browse.php?tab=routes&version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
            <i class="bi bi-compass-fill text-sky-400 mr-2"></i> Tuyến & Trạm
        </a>
        <a href="map.php?version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
            <i class="bi bi-map-fill text-emerald-400 mr-2"></i> Bản Đồ
        </a>
        <a href="next_trip.php" class="block px-3 py-2.5 rounded-xl text-base font-semibold text-white bg-brand-600/50 transition">
            <i class="bi bi-clock-history text-amber-400 mr-2"></i> Chuyến Kế Tiếp
        </a>
        
        <?php if ($user): ?>
            <a href="saved_queries.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
                <i class="bi bi-bookmark-star-fill text-amber-400 mr-2"></i> Truy Vấn Đã Lưu
            </a>
            <?php if (isset($user['role']) && $user['role'] === 'admin'): ?>
                <a href="../admin/gtfs.php" class="block px-3 py-2.5 rounded-xl text-base font-bold text-amber-400 hover:bg-slate-800 transition">
                    <i class="bi bi-speedometer2 mr-2"></i> Quản Trị GTFS
                </a>
            <?php endif; ?>
            <div class="pt-3 border-t border-slate-800 flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-200">
                    <i class="bi bi-person-circle text-brand-400 mr-1.5"></i> <?php echo htmlspecialchars($user['fullname']); ?>
                </span>
                <a href="../auth/logout.php" class="text-sm font-medium text-rose-400 hover:text-rose-300">Đăng xuất</a>
            </div>
        <?php else: ?>
            <a href="../auth/login.php" class="block px-3 py-2.5 mt-4 text-center rounded-xl text-base font-bold text-white bg-brand-600 hover:bg-brand-500 transition">
                Đăng nhập hệ thống
            </a>
        <?php endif; ?>
    </div>
</header>

    <div class="container py-4">

        <!-- Header -->
        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark mb-1">
                <i class="bi bi-clock-history text-primary me-2"></i>Tìm Chuyến Kế Tiếp Tại Trạm
            </h2>
            <p class="text-muted">
                Xử lý chính xác theo <strong>ngày hoạt động</strong>, <strong>lịch ngoại lệ (thêm / hủy dịch vụ)</strong> và <strong>giờ chạy qua nửa đêm (> 24:00:00)</strong>
            </p>
            <?php if ($active_version): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                    <i class="bi bi-check-circle-fill me-1"></i> Dữ liệu lịch trình: <strong><?php echo htmlspecialchars($active_version['version_name']); ?></strong>
                </span>
            <?php else: ?>
                <div class="alert alert-warning mt-2 mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Chưa có phiên bản lịch trình nào được kích hoạt. Quản trị viên cần kích hoạt một bản GTFS để tra cứu.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($save_success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo $save_success; ?>
                <a href="saved_queries.php" class="ms-2 fw-semibold text-decoration-none">Xem danh sách truy vấn đã lưu</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($save_error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-x-circle-fill me-2"></i> <?php echo $save_error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form Tra Cứu -->
        <div class="card search-card p-4 p-md-4 mb-4">
            <form method="GET" action="next_trip.php" class="row g-3 align-items-end">

                <!-- 1. Chọn Trạm -->
                <div class="col-md-5">
                    <label for="stop_id" class="form-label fw-bold">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i> Chọn Trạm Dừng <span class="text-danger">*</span>
                    </label>
                    <select class="form-select form-select-lg" id="stop_id" name="stop_id" required>
                        <option value="">-- Chọn hoặc tìm trạm dừng --</option>
                        <?php foreach ($stops as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['stop_id']); ?>" <?php echo ($stop_id === $s['stop_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['stop_name']); ?> (Mã: <?php echo htmlspecialchars($s['stop_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Chọn Ngày -->
                <div class="col-md-3">
                    <label for="date" class="form-label fw-bold">
                        <i class="bi bi-calendar-event-fill text-primary me-1"></i> Ngày Tra Cứu <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control form-control-lg" id="date" name="date" 
                           value="<?php echo htmlspecialchars($date); ?>" required>
                </div>

                <!-- 3. Chọn Giờ -->
                <div class="col-md-2">
                    <label for="time" class="form-label fw-bold">
                        <i class="bi bi-clock-fill text-warning me-1"></i> Giờ Bắt Đầu <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg" id="time" name="time" 
                           placeholder="HH:MM" value="<?php echo htmlspecialchars($time); ?>" required>
                    <div class="form-text small">Hỗ trợ 00:00 - 26:00</div>
                </div>

                <!-- 4. Nút Tìm Kiếm -->
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                        <i class="bi bi-search me-1"></i> Tìm Chuyến
                    </button>
                </div>
            </form>
        </div>

        <!-- KẾT QUẢ TÌM KIẾM -->
        <?php if ($searched && $selected_stop): ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-pin-map-fill text-danger me-1"></i> Các chuyến sắp đến tại: 
                        <span class="text-primary"><?php echo htmlspecialchars($selected_stop['stop_name']); ?></span>
                    </h5>
                    <small class="text-muted">
                        Từ ngày: <strong><?php echo date('d/m/Y', strtotime($date)); ?></strong> lúc <strong><?php echo htmlspecialchars($time); ?></strong>
                        (Tìm thấy <?php echo count($next_trips); ?> chuyến kế tiếp)
                    </small>
                </div>

                <!-- Nút Lưu Truy Vấn Mẫu -->
                <?php if ($user): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#saveQueryModal">
                        <i class="bi bi-bookmark-plus-fill me-1"></i> Lưu Truy Vấn Này
                    </button>
                <?php endif; ?>
            </div>

            <!-- NẾU KHÔNG CÓ CHUYẾN: YÊU CẦU ĐỀ BÀI BÁO RÕ RÀNG -->
            <?php if (empty($next_trips)): ?>
                <div class="alert alert-warning text-center py-5 rounded-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill display-3 text-warning d-block mb-3"></i>
                    <h4 class="fw-bold text-dark">Không có chuyến nào tiếp theo!</h4>
                    <p class="text-muted mb-3" style="max-width: 600px; margin: 0 auto;">
                        Không tìm thấy chuyến xe nào khởi hành tại trạm <strong><?php echo htmlspecialchars($selected_stop['stop_name']); ?></strong> 
                        sau thời điểm <strong><?php echo htmlspecialchars($time); ?></strong> ngày <strong><?php echo date('d/m/Y', strtotime($date)); ?></strong>.
                    </p>
                    <div class="small text-secondary mb-3">
                        <p class="mb-1"><strong>Nguyên nhân có thể là:</strong></p>
                        <ul class="list-unstyled mb-0">
                            <li>• Các chuyến trong ngày đã kết thúc hoặc giờ tra cứu quá muộn.</li>
                            <li>• Tuyến không hoạt động vào thứ này theo lịch (<code>calendar.txt</code>).</li>
                            <li>• Ngày này có lịch nghỉ / hủy dịch vụ đặc biệt (<code>calendar_dates.txt</code>).</li>
                        </ul>
                    </div>
                    <a href="next_trip.php?stop_id=<?php echo urlencode($stop_id); ?>&date=<?php echo $date; ?>&time=06:00" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Thử tra cứu từ đầu ngày (06:00)
                    </a>
                </div>

            <!-- NẾU CÓ CHUYẾN -->
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($next_trips as $t): 
                        $is_overnight = !empty($t['is_overnight']);
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card trip-result-card h-100 p-3 <?php echo $is_overnight ? 'trip-overnight' : ''; ?>">
                                
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">
                                        <i class="bi <?php echo route_type_icon($t['route_type']); ?> me-1"></i>
                                        Tuyến <?php echo htmlspecialchars($t['route_short_name']); ?>
                                    </span>

                                    <?php if ($is_overnight): ?>
                                        <span class="badge bg-purple text-white" style="background-color: #6f42c1;">
                                            <i class="bi bi-moon-stars-fill me-1"></i> Chuyến Đêm (>24h)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Đúng Lịch</span>
                                    <?php endif; ?>
                                </div>

                                <h6 class="fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($t['route_long_name']); ?>
                                </h6>

                                <div class="text-secondary small mb-3">
                                    <i class="bi bi-compass"></i> Hướng: <strong><?php echo htmlspecialchars($t['trip_headsign'] ?: 'Theo lộ trình'); ?></strong>
                                    (Chuyến #<?php echo htmlspecialchars($t['trip_id']); ?>)
                                </div>

                                <!-- THỜI GIAN KHỞI HÀNH -->
                                <div class="p-2 rounded bg-light mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted small">Giờ khởi hành tại trạm:</span>
                                        <span class="text-primary time-badge">
                                            <i class="bi bi-clock-fill me-1"></i> <?php echo htmlspecialchars($t['clock_time']); ?>
                                        </span>
                                    </div>

                                    <?php if ($is_overnight): ?>
                                        <div class="d-flex justify-content-between align-items-center text-muted small mt-1 border-top pt-1">
                                            <span>Giờ định dạng GTFS:</span>
                                            <code class="text-danger fw-bold"><?php echo htmlspecialchars($t['departure_time']); ?></code>
                                        </div>
                                        <div class="small text-purple mt-1" style="color: #6f42c1; font-size: 0.8rem;">
                                            <i class="bi bi-info-circle-fill"></i> Lịch dịch vụ ngày <?php echo $t['service_date'] ?? $t['actual_date']; ?>, chạy rạng sáng ngày <?php echo $t['actual_date']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-auto d-flex gap-2">
                                    <a href="gtfs_browse.php?tab=trips&trip_id=<?php echo urlencode($t['trip_id']); ?>" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                                        <i class="bi bi-list-ol me-1"></i> Xem các lần dừng
                                    </a>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($searched && !$selected_stop): ?>
            <div class="alert alert-danger text-center">
                Mã trạm không hợp lệ hoặc không tồn tại trong phiên bản lịch này.
            </div>
        <?php else: ?>
            <div class="card p-5 text-center text-muted border-0 bg-white rounded-4 shadow-sm">
                <i class="bi bi-bus-front display-4 text-primary mb-3"></i>
                <h5 class="fw-bold text-dark">Hãy chọn một trạm dừng để bắt đầu tra cứu chuyến tiếp theo</h5>
                <p class="mb-0">Hệ thống sẽ tính toán ngày trong tuần, ngày ngoại lệ và các chuyến chạy qua 24h để hiển thị kết quả chính xác nhất.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL LƯU TRUY VẤN MẪU -->
    <?php if ($user && $searched && $selected_stop): ?>
        <div class="modal fade" id="saveQueryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="next_trip.php?stop_id=<?php echo urlencode($stop_id); ?>&date=<?php echo urlencode($date); ?>&time=<?php echo urlencode($time); ?>">
                        <input type="hidden" name="action" value="save_query">
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold"><i class="bi bi-bookmark-star-fill text-warning me-1"></i> Lưu Truy Vấn Mẫu</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3">Lưu lại bộ lọc này để chạy lại bất kỳ lúc nào hoặc tự động kiểm tra khi có phiên bản lịch mới.</p>
                            <div class="mb-3">
                                <label for="query_name" class="form-label fw-bold">Đặt tên cho truy vấn mẫu này:</label>
                                <input type="text" class="form-control" id="query_name" name="query_name" 
                                       value="Chuyến tiếp theo tại <?php echo htmlspecialchars($selected_stop['stop_name']); ?> (<?php echo htmlspecialchars($time); ?>)" required>
                            </div>
                            <div class="p-2 bg-light rounded small text-secondary">
                                <div>• <strong>Trạm:</strong> <?php echo htmlspecialchars($selected_stop['stop_name']); ?></div>
                                <div>• <strong>Giờ:</strong> <?php echo htmlspecialchars($time); ?></div>
                                <div>• <strong>Ngày:</strong> <?php echo htmlspecialchars($date); ?></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-primary fw-bold">
                                <i class="bi bi-check2"></i> Xác Nhận Lưu
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
