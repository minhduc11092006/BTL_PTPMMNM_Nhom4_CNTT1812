<?php
// map.php - Bản đồ các tuyến đường: vẽ lộ trình tuyến và các trạm dừng lên bản đồ (Leaflet + OpenStreetMap)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();
$version_id = isset($_GET['version_id']) ? (int)$_GET['version_id'] : ($active_version['id'] ?? 0);

// Endpoint JSON cho bản đồ: map.php?data=1
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$active_version) {
        echo json_encode(['error' => 'no_active_version']);
        exit;
    }
    try {
        echo json_encode(get_route_map_data((int)$active_version['id']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error']);
    }
    exit;
}

$initial_route = $_GET['route_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bản Đồ Tuyến Đường - Tra Cứu Giao Thông Công Cộng</title>
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
    <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: #084298; }
        .map-card { border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); background: #fff; overflow: hidden; }
        #map { height: 78vh; min-height: 480px; width: 100%; }
        #route-list { max-height: 38vh; overflow-y: auto; }
        .route-item { cursor: pointer; border-left: 5px solid transparent; }
        .route-item.active { background: #e7f1ff; }
        .dir-arrow { background: none; border: none; }
        .route-swatch { width: 14px; height: 14px; border-radius: 50%; display: inline-block; flex: 0 0 14px; }
        #stop-panel { max-height: 30vh; overflow-y: auto; }
        .stop-row { cursor: pointer; font-size: .9rem; }
        .stop-row:hover { background: #f1f3f5; }
        .stop-seq { width: 26px; text-align: center; color: #6c757d; }
    
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
                
                <a href="map.php?version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="h-9 px-3 text-xs xl:text-sm font-semibold rounded-lg bg-brand-600/50 text-white shadow-sm inline-flex items-center gap-1.5 transition hover:bg-brand-600/60 whitespace-nowrap shrink-0">
                    <i class="bi bi-map-fill text-emerald-400 text-sm"></i>
                    <span>Bản Đồ</span>
                </a>

                <a href="next_trip.php" class="h-9 px-3 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
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
        <a href="map.php?version_id=<?php echo htmlspecialchars($version_id ?? ''); ?>" class="block px-3 py-2.5 rounded-xl text-base font-semibold text-white bg-brand-600/50 transition">
            <i class="bi bi-map-fill text-emerald-400 mr-2"></i> Bản Đồ
        </a>
        <a href="next_trip.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
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

    <div class="container-fluid px-lg-4 py-3">
        <?php if (!$active_version): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Chưa có phiên bản lịch trình nào được kích hoạt. Quản trị viên cần kích hoạt một bản GTFS để xem bản đồ.
            </div>
        <?php else: ?>
        <div class="row g-3">
            <!-- Sidebar -->
            <div class="col-lg-4 col-xl-3">
                <div class="map-card p-3">
                    <h5 class="fw-bold mb-1"><i class="bi bi-map-fill text-primary me-1"></i> Bản Đồ Tuyến Đường</h5>
                    <div class="small text-muted mb-3">
                        Dữ liệu: <strong><?php echo htmlspecialchars($active_version['version_name']); ?></strong>
                    </div>

                    <input type="text" id="search" class="form-control form-control-sm mb-2" placeholder="Tìm theo tên hoặc số hiệu tuyến...">
                    <select id="type-filter" class="form-select form-select-sm mb-2">
                        <option value="">Tất cả loại phương tiện</option>
                    </select>
                    <div class="d-flex gap-3 mb-2 small">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dir0" checked>
                            <label class="form-check-label" for="dir0">Chiều đi (nét liền)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dir1" checked>
                            <label class="form-check-label" for="dir1">Chiều về (nét đứt)</label>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted" id="count-label">Đang tải...</span>
                        <button class="btn btn-sm btn-outline-secondary" id="btn-clear" style="display:none;">
                            <i class="bi bi-x-circle me-1"></i> Bỏ chọn
                        </button>
                    </div>

                    <div id="route-list" class="list-group list-group-flush border rounded"></div>

                    <div id="stop-panel-wrap" class="mt-3" style="display:none;">
                        <div class="fw-bold small mb-1" id="stop-panel-title"></div>
                        <div id="stop-panel" class="border rounded"></div>
                    </div>
                </div>
            </div>

            <!-- Map -->
            <div class="col-lg-8 col-xl-9">
                <div class="map-card"><div id="map"></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php if ($active_version): ?>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    const INITIAL_ROUTE = <?php echo json_encode($initial_route, JSON_UNESCAPED_UNICODE); ?>;
    const FALLBACK = ['#0d6efd','#dc3545','#198754','#fd7e14','#6f42c1','#20c997','#d63384','#0dcaf0','#795548','#6610f2'];

    const map = L.map('map').setView([35.68, 139.5], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    const lineLayer = L.layerGroup().addTo(map);
    const stopLayer = L.layerGroup().addTo(map);

    let routes = [];       // dữ liệu tuyến từ server
    let selectedId = null;
    const listItems = {};  // route_id -> phần tử DOM

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const label = r => (r.short ? r.short : r.route_id) + (r.long ? ' — ' + r.long : '');

    // ----- Liên kết Google Maps (không cần API key) -----
    const gmapsStop = s => 'https://www.google.com/maps/search/?api=1&query=' + s.lat + ',' + s.lon;

    // Google Maps chỉ nhận tối đa 9 điểm trung gian -> lấy mẫu đều các trạm ở giữa
    function gmapsRoute(line) {
        const st = line.stops;
        const first = st[0], last = st[st.length - 1];
        const mid = st.slice(1, -1);
        const MAX = 9;
        let picked = mid;
        if (mid.length > MAX) {
            picked = [];
            for (let k = 0; k < MAX; k++) {
                picked.push(mid[Math.round(k * (mid.length - 1) / (MAX - 1))]);
            }
        }
        let url = 'https://www.google.com/maps/dir/?api=1'
            + '&origin=' + first.lat + ',' + first.lon
            + '&destination=' + last.lat + ',' + last.lon;
        if (picked.length) {
            url += '&waypoints=' + picked.map(p => p.lat + ',' + p.lon).join('%7C');
        }
        return url + '&travelmode=driving';
    }

    // ----- Chiều đi / chiều về -----
    const dirVisible = line => {
        if (line.direction === 0) return document.getElementById('dir0').checked;
        if (line.direction === 1) return document.getElementById('dir1').checked;
        return true;
    };

    // Vẽ mũi tên chỉ hướng chạy dọc theo đường (theo thứ tự điểm của shape / trạm)
    function addArrows(coords, color) {
        if (coords.length < 2) return;
        const dist = [0];
        for (let k = 1; k < coords.length; k++) {
            dist.push(dist[k - 1] + map.distance(coords[k - 1], coords[k]));
        }
        const total = dist[dist.length - 1];
        if (total <= 0) return;
        const n = Math.max(3, Math.min(25, Math.round(total / 400)));
        let seg = 1;
        for (let k = 1; k <= n; k++) {
            const target = total * k / (n + 1);
            while (seg < dist.length - 1 && dist[seg] < target) seg++;
            const a = coords[seg - 1], b = coords[seg];
            const span = (dist[seg] - dist[seg - 1]) || 1;
            const t = Math.min(1, Math.max(0, (target - dist[seg - 1]) / span));
            const lat = a[0] + (b[0] - a[0]) * t;
            const lon = a[1] + (b[1] - a[1]) * t;
            const bearing = Math.atan2((b[1] - a[1]) * Math.cos(a[0] * Math.PI / 180), b[0] - a[0]) * 180 / Math.PI;
            const icon = L.divIcon({
                className: 'dir-arrow',
                iconSize: [18, 18],
                iconAnchor: [9, 9],
                html: '<svg viewBox="0 0 20 20" width="18" height="18" style="transform:rotate(' + bearing.toFixed(1) + 'deg)">' +
                      '<path d="M10 2 L17 17 L10 13 L3 17 Z" fill="' + color + '" stroke="#fff" stroke-width="1.5"/></svg>'
            });
            L.marker([lat, lon], {icon: icon, interactive: false, keyboard: false}).addTo(lineLayer);
        }
    }

    function colorOf(r, i) { return r.color || FALLBACK[i % FALLBACK.length]; }

    function matches(r) {
        const kw = document.getElementById('search').value.trim().toLowerCase();
        const type = document.getElementById('type-filter').value;
        if (type !== '' && String(r.type) !== type) return false;
        if (kw === '') return true;
        return (r.short || '').toLowerCase().includes(kw)
            || (r.long || '').toLowerCase().includes(kw)
            || String(r.route_id).toLowerCase().includes(kw);
    }

    function drawLines() {
        lineLayer.clearLayers();
        let shown = 0;
        routes.forEach((r, i) => {
            const visible = matches(r);
            if (listItems[r.route_id]) listItems[r.route_id].style.display = visible ? '' : 'none';
            if (!visible) return;
            shown++;
            const isSel = r.route_id === selectedId;
            const dim = selectedId !== null && !isSel;
            r.lines.forEach(line => {
                if (!dirVisible(line)) return;
                const coords = line.path || line.stops.map(s => [s.lat, s.lon]);
                const pl = L.polyline(coords, {
                    color: colorOf(r, i),
                    weight: isSel ? 6 : 3,
                    opacity: dim ? 0.15 : (isSel ? 0.95 : 0.6),
                    dashArray: line.direction === 1 ? '8 6' : null
                });
                pl.on('click', () => selectRoute(r.route_id, true));
                pl.bindTooltip(esc(label(r)), {sticky: true});
                if (isSel) pl.addTo(lineLayer).bringToFront(); else pl.addTo(lineLayer);
                if (isSel) addArrows(coords, colorOf(r, i));
            });
        });
        document.getElementById('count-label').textContent = shown + ' / ' + routes.length + ' tuyến';
    }

    function drawStops(r) {
        stopLayer.clearLayers();
        if (!r) return;
        const col = colorOf(r, routes.indexOf(r));
        r.lines.forEach(line => {
            if (!dirVisible(line)) return;
            line.stops.forEach((s, idx) => {
                const first = idx === 0, last = idx === line.stops.length - 1;
                const m = L.circleMarker([s.lat, s.lon], {
                    radius: first || last ? 8 : 5,
                    color: '#fff', weight: 2, fillOpacity: 1,
                    fillColor: first ? '#198754' : (last ? '#dc3545' : col)
                });
                m.bindPopup(
                    '<strong>' + esc(s.name) + '</strong><br>' +
                    '<small>Mã trạm: ' + esc(s.id) + '</small><br>' +
                    '<a href="next_trip.php?stop_id=' + encodeURIComponent(s.id) + '">Xem chuyến kế tiếp tại trạm</a><br>' +
                    '<a href="' + gmapsStop(s) + '" target="_blank" rel="noopener">Mở trạm trong Google Maps</a>'
                );
                m.addTo(stopLayer);
            });
        });
    }

    function renderStopPanel(r) {
        const wrap = document.getElementById('stop-panel-wrap');
        const panel = document.getElementById('stop-panel');
        if (!r) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';
        document.getElementById('stop-panel-title').textContent = 'Lộ trình tuyến ' + (r.short || r.route_id) + ' · ' + r.type_name + (r.agency ? ' · ' + r.agency : '');
        panel.innerHTML = '';
        r.lines.forEach(line => {
            const h = document.createElement('div');
            h.className = 'bg-light px-2 py-1 small fw-semibold border-bottom d-flex justify-content-between align-items-center gap-2';
            const title = (line.direction === 1 ? 'Chiều về' : (line.direction === 0 ? 'Chiều đi' : 'Lộ trình'))
                + (line.headsign ? ' → ' + line.headsign : '');
            h.innerHTML = '<span>' + esc(title) + '</span>' +
                '<a class="btn btn-sm btn-outline-primary py-0 text-nowrap" target="_blank" rel="noopener" href="' + gmapsRoute(line) + '">' +
                '<i class="bi bi-google me-1"></i>Google Maps</a>';
            panel.appendChild(h);
            line.stops.forEach((s, idx) => {
                const row = document.createElement('div');
                row.className = 'stop-row d-flex px-2 py-1 border-bottom';
                row.innerHTML = '<span class="stop-seq">' + (idx + 1) + '</span><span class="flex-grow-1">' + esc(s.name) + '</span>' +
                    '<a class="text-primary ms-2" title="Mở trạm trong Google Maps" target="_blank" rel="noopener" href="' + gmapsStop(s) + '"><i class="bi bi-box-arrow-up-right"></i></a>';
                row.querySelector('a').addEventListener('click', e => e.stopPropagation());
                row.addEventListener('click', () => {
                    map.flyTo([s.lat, s.lon], Math.max(map.getZoom(), 16));
                    L.popup().setLatLng([s.lat, s.lon]).setContent('<strong>' + esc(s.name) + '</strong>').openOn(map);
                });
                panel.appendChild(row);
            });
        });
    }

    function selectRoute(id, scrollList) {
        selectedId = id;
        const r = routes.find(x => x.route_id === id) || null;
        Object.keys(listItems).forEach(k => listItems[k].classList.toggle('active', k === id));
        document.getElementById('btn-clear').style.display = r ? '' : 'none';
        drawLines();
        drawStops(r);
        renderStopPanel(r);
        if (r) {
            const pts = [];
            r.lines.forEach(l => (l.path || l.stops.map(s => [s.lat, s.lon])).forEach(p => pts.push(p)));
            map.fitBounds(pts, {padding: [40, 40]});
            if (scrollList && listItems[id]) listItems[id].scrollIntoView({block: 'nearest'});
        }
    }

    function clearSelection() {
        selectedId = null;
        Object.values(listItems).forEach(el => el.classList.remove('active'));
        document.getElementById('btn-clear').style.display = 'none';
        stopLayer.clearLayers();
        renderStopPanel(null);
        drawLines();
        fitAll();
    }

    function fitAll() {
        const pts = [];
        routes.forEach(r => r.lines.forEach(l => l.stops.forEach(s => pts.push([s.lat, s.lon]))));
        if (pts.length) map.fitBounds(pts, {padding: [30, 30]});
    }

    function buildList() {
        const list = document.getElementById('route-list');
        list.innerHTML = '';
        routes.forEach((r, i) => {
            const el = document.createElement('a');
            el.className = 'list-group-item list-group-item-action route-item d-flex align-items-center gap-2 py-2';
            el.style.borderLeftColor = colorOf(r, i);
            el.innerHTML = '<span class="route-swatch" style="background:' + colorOf(r, i) + '"></span>' +
                '<span class="small"><strong>' + esc(r.short || r.route_id) + '</strong>' +
                (r.long ? '<br><span class="text-muted">' + esc(r.long) + '</span>' : '') + '</span>';
            el.addEventListener('click', () => selectRoute(r.route_id, false));
            list.appendChild(el);
            listItems[r.route_id] = el;
        });
    }

    function buildTypeFilter() {
        const sel = document.getElementById('type-filter');
        const seen = {};
        routes.forEach(r => { seen[r.type] = r.type_name; });
        Object.keys(seen).sort().forEach(t => {
            const o = document.createElement('option');
            o.value = t; o.textContent = seen[t];
            sel.appendChild(o);
        });
    }

    document.getElementById('search').addEventListener('input', () => { drawLines(); });
    document.getElementById('type-filter').addEventListener('change', () => { drawLines(); });
    ['dir0', 'dir1'].forEach(id => document.getElementById(id).addEventListener('change', () => {
        drawLines();
        if (selectedId !== null) drawStops(routes.find(x => x.route_id === selectedId) || null);
    }));
    document.getElementById('btn-clear').addEventListener('click', clearSelection);

    fetch('map.php?data=1')
        .then(res => res.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            routes = data.routes;
            if (routes.length === 0) {
                document.getElementById('count-label').textContent = 'Không có tuyến nào có dữ liệu toạ độ trạm.';
                return;
            }
            buildTypeFilter();
            buildList();
            drawLines();
            fitAll();
            if (data.without_geometry > 0) {
                document.getElementById('count-label').title = data.without_geometry + ' tuyến không có đủ toạ độ trạm nên không hiển thị.';
            }
            if (INITIAL_ROUTE && routes.some(r => r.route_id === INITIAL_ROUTE)) {
                selectRoute(INITIAL_ROUTE, true);
            }
        })
        .catch(err => {
            document.getElementById('count-label').textContent = 'Không tải được dữ liệu bản đồ (' + err.message + ').';
        });
})();
</script>
<?php endif; ?>
</body>
</html>
