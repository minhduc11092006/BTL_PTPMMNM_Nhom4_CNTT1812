<?php
// index.php - Trang chủ tra cứu lịch trình giao thông công cộng chuẩn GTFS
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$gtfs_routes = [];
$total_gtfs_trips = 0;
$total_gtfs_stops = 0;

// Chỉ tra cứu và hiển thị dữ liệu GTFS khi người dùng đã đăng nhập và admin đã kích hoạt phiên bản GTFS
if ($user && $active_version) {
    if ($keyword !== '') {
        $gtfs_routes = search_routes($keyword, $active_version['id']);
    } else {
        $gtfs_routes = get_all_routes($active_version['id']);
    }
    $stats = get_feed_stats($active_version['id']);
    $total_gtfs_trips = $stats['trips'] ?? 0;
    $total_gtfs_stops = $stats['stops'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="vi" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Tra Cứu Lịch Trình Giao Thông Công Cộng GTFS - Bus &amp; Metro</title>
    
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
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
                        'card': '0 10px 30px -5px rgba(15, 23, 42, 0.05), 0 4px 6px -2px rgba(15, 23, 42, 0.02)',
                        'card-hover': '0 20px 35px -5px rgba(15, 23, 42, 0.08), 0 10px 10px -5px rgba(15, 23, 42, 0.03)',
                    }
                }
            }
        }
    </script>

    <!-- Bootstrap Icons (chỉ sử dụng icon font vector) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }
        .hero-pattern {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        /* Hiệu ứng chuyển động hình ảnh nền tàu metro/bus nhẹ nhàng (Ken Burns effect) */
        @keyframes hero-kenburns {
            0% { transform: scale(1) translate(0, 0); }
            50% { transform: scale(1.06) translate(-0.8%, -0.8%); }
            100% { transform: scale(1) translate(0, 0); }
        }
        .animate-hero-bg {
            animation: hero-kenburns 24s ease-in-out infinite alternate;
        }
        /* Hiệu ứng chuyển động pulse nhẹ cho các tàu/xe transit */
        @keyframes float-slow {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-6px); }
        }
        .animate-float {
            animation: float-slow 4s ease-in-out infinite;
        }
        .animate-float-delayed {
            animation: float-slow 4.5s ease-in-out 1.5s infinite;
        }
        /* Custom Shadow nổi khối sâu, tỏa rộng cho CTA Box */
        .cta-floating-shadow {
            box-shadow: 0 25px 60px -12px rgba(15, 23, 42, 0.35), 
                        0 0 35px rgba(99, 102, 241, 0.18),
                        inset 0 1px 1px rgba(255, 255, 255, 0.8);
        }
        /* Hiệu ứng Text Shadow đa tầng giúp chữ hiển thị siêu sắc nét trên nền ảnh */
        .text-hero-title {
            text-shadow: 0 3px 6px rgba(0, 0, 0, 0.95), 
                         0 6px 20px rgba(0, 0, 0, 0.85),
                         0 0 35px rgba(0, 0, 0, 0.75);
        }
        .text-hero-sub {
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.95), 
                         0 4px 12px rgba(0, 0, 0, 0.85);
        }
        /* Lớp Scrim Glass trung tâm làm nổi chữ mà vẫn khoe rõ nền bus & metro xung quanh */
        .hero-scrim-glass {
            background: radial-gradient(ellipse at center, rgba(15, 23, 42, 0.82) 0%, rgba(15, 23, 42, 0.65) 65%, rgba(15, 23, 42, 0.25) 100%);
        }
        /* ============================================================== */
        /* HIỆU ỨNG XE BUS CHẠY XUYÊN SUỐT TOÀN BỘ THANH ĐIỀU HƯỚNG (NAVBAR) */
        /* ============================================================== */

        /* Xe bus di chuyển từ ngoài mép trái xuyên suốt sang tận mép phải toàn màn hình */
        @keyframes bus-traverse-nav {
            0% {
                transform: translateX(-160px);
                opacity: 0;
            }
            1.5% {
                opacity: 1;
            }
            98.5% {
                opacity: 1;
            }
            100% {
                transform: translateX(calc(100vw + 160px));
                opacity: 0;
            }
        }

        .animate-bus-highway-1 {
            animation: bus-traverse-nav 13s cubic-bezier(0.35, 0, 0.25, 1) infinite;
        }

        .animate-bus-highway-2 {
            animation: bus-traverse-nav 13s cubic-bezier(0.35, 0, 0.25, 1) infinite;
            animation-delay: 6.5s;
        }

        /* Làn đường ray Neon ở đáy Navbar trôi chuyển động liên tục */
        @keyframes highway-track-pan {
            0% { background-position: 0 0; }
            100% { background-position: -36px 0; }
        }
        .animate-highway-track {
            background-image: repeating-linear-gradient(90deg, #38bdf8 0, #38bdf8 12px, transparent 12px, transparent 24px);
            background-size: 36px 2px;
            animation: highway-track-pan 0.65s linear infinite;
        }

        /* Hiệu ứng chùm đèn pha xe bus quét sáng */
        @keyframes headlight-glow-pulse {
            0%, 100% { opacity: 0.65; transform: scaleX(1); }
            50% { opacity: 1; transform: scaleX(1.18); filter: drop-shadow(0 0 10px rgba(254, 240, 138, 0.95)); }
        }
        .animate-headlight-pulse {
            animation: headlight-glow-pulse 1.6s ease-in-out infinite;
        }

        /* Hiệu ứng nhún xe bus logo */
        @keyframes bus-suspension {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            30% { transform: translateY(-1.5px) rotate(-1deg); }
            70% { transform: translateY(0.5px) rotate(0.5deg); }
        }
        .animate-bus-suspension {
            animation: bus-suspension 1.8s ease-in-out infinite;
        }

        /* Hiệu ứng đèn hậu đỏ quét vệt chuyển động */
        @keyframes taillight-trail {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; filter: drop-shadow(0 0 6px rgba(244, 63, 94, 0.9)); }
        }
        .animate-taillight-trail {
            animation: taillight-trail 1s ease-in-out infinite;
        }
    </style>
</head>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-800 antialiased selection:bg-brand-500 selection:text-white">

    <!-- ================================================================= -->
    <!-- 1. MODERN FULL-WIDTH ANIMATED TRANSIT NAVBAR                      -->
    <!-- (Xe Bus chạy xuyên suốt toàn bộ thanh điều hướng, nút chức năng nổi) -->
    <!-- ================================================================= -->
    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800/80 transition-all overflow-hidden relative shadow-lg">
        
        <!-- ============================================================== -->
        <!-- LỚP XE BUS CHẠY XUYÊN SUỐT TOÀN BỘ CHIỀU NGANG THANH ĐIỀU HƯỚNG -->
        <!-- (Layer nền tự động, pointer-events-none không cản trở click nút) -->
        <!-- ============================================================== -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
            
            <!-- Làn đường cao tốc / Đường ray phát sáng ở mép dưới Navbar -->
            <div class="absolute bottom-0 inset-x-0 h-[3px] bg-slate-950/80 border-t border-slate-800/60 flex items-center">
                <!-- Vạch kẻ sơn phản quang neon trôi liên tục xuyên suốt màn hình -->
                <div class="w-full h-full animate-highway-track opacity-70"></div>
            </div>

            <!-- Vệt sáng neon mờ dẫn hướng xe bus -->
            <div class="absolute bottom-1 inset-x-0 h-[1px] bg-gradient-to-r from-transparent via-cyan-500/20 to-transparent"></div>

            <!-- XE BUS 1: SMART ELECTRIC BUS (Tuyến 01 - Xanh Ngọc Emerald & Cyan) -->
            <div class="animate-bus-highway-1 absolute bottom-[3px] flex items-end">
                <div class="relative flex items-center">
                    
                    <!-- Vệt khói sáng / Motion Tail ánh sáng neon phát phía sau đuôi xe -->
                    <div class="w-16 h-4 bg-gradient-to-l from-emerald-500/40 via-cyan-500/20 to-transparent blur-[3px] -mr-1"></div>

                    <!-- SVG Xe Bus Điện Đô Thị Siêu Nét -->
                    <svg class="w-24 h-7 filter drop-shadow-[0_2px_8px_rgba(16,185,129,0.7)]" viewBox="0 0 100 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Khung xe khí động học màu xanh Emerald hiện đại -->
                        <rect x="4" y="3" width="76" height="19" rx="4.5" fill="#059669" />
                        <!-- Nóc xe vát màu sáng bóng -->
                        <path d="M5 7 Q 45 2 79 7" stroke="#34d399" stroke-width="1.2" fill="none" />
                        
                        <!-- Dải kính đen chống lóa cao cấp -->
                        <rect x="7" y="5" width="13" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="22" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="38" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="54" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <!-- Cửa kính người lái bo cong phía trước -->
                        <path d="M70 5 H77 C79 5 79.5 6 79.5 8 V13 H70 V5 Z" fill="#0f172a" />
                        
                        <!-- Biển số tuyến LED phát sáng trên kính xe: "BUS 01" -->
                        <text x="39" y="10.8" fill="#38bdf8" font-size="4.8" font-family="monospace" font-weight="bold" letter-spacing="0.5">BUS 01</text>
                        
                        <!-- Đèn hậu LED đỏ phía sau -->
                        <rect x="4" y="14" width="2" height="4" rx="0.8" fill="#f43f5e" class="animate-taillight-trail" />

                        <!-- Đèn pha LED trước chùm sáng cực mạnh -->
                        <circle cx="78.5" cy="15.5" r="1.8" fill="#fef08a" class="animate-headlight-pulse" />
                        
                        <!-- Chùm tia sáng đèn pha quét xa về phía trước hơn 60px -->
                        <polygon points="80,15.5 100,10 100,22" fill="url(#busBeamGradient1)" class="animate-headlight-pulse opacity-80" />

                        <!-- Bánh xe hợp kim thể thao đô thị -->
                        <circle cx="17" cy="22" r="4.2" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.4" />
                        <circle cx="17" cy="22" r="1.8" fill="#38bdf8" />
                        <circle cx="67" cy="22" r="4.2" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.4" />
                        <circle cx="67" cy="22" r="1.8" fill="#38bdf8" />

                        <defs>
                            <linearGradient id="busBeamGradient1" x1="80" y1="15.5" x2="100" y2="15.5" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#fef08a" stop-opacity="0.95" />
                                <stop offset="60%" stop-color="#fef08a" stop-opacity="0.4" />
                                <stop offset="100%" stop-color="#fef08a" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                    </svg>

                    <!-- Huy hiệu "Eco Transit" nhỏ gọn theo xe -->
                    <span class="text-[9px] font-bold text-emerald-300 bg-emerald-950/80 px-1.5 py-0.5 rounded border border-emerald-500/40 -ml-2 -mb-2 shadow-sm whitespace-nowrap">
                        01 Live
                    </span>
                </div>
            </div>

            <!-- XE BUS 2: METRO RAPID TRANSIT (Tuyến 02 - Hổ Phách Amber & Indigo chạy so le) -->
            <div class="animate-bus-highway-2 absolute bottom-[3px] flex items-end">
                <div class="relative flex items-center">
                    
                    <!-- Vệt khói sáng neon tím/hổ phách -->
                    <div class="w-16 h-4 bg-gradient-to-l from-amber-500/40 via-indigo-500/20 to-transparent blur-[3px] -mr-1"></div>

                    <!-- SVG Xe Bus Nhanh Tuyến 02 -->
                    <svg class="w-24 h-7 filter drop-shadow-[0_2px_8px_rgba(245,158,11,0.7)]" viewBox="0 0 100 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Khung xe màu vàng hổ phách nổi bật -->
                        <rect x="4" y="3" width="76" height="19" rx="4.5" fill="#d97706" />
                        <!-- Nóc xe vát màu trắng sáng -->
                        <path d="M5 7 Q 45 2 79 7" stroke="#fde68a" stroke-width="1.2" fill="none" />
                        
                        <!-- Dải kính đen thể thao -->
                        <rect x="7" y="5" width="13" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="22" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="38" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <rect x="54" y="5" width="14" height="8" rx="1.5" fill="#0f172a" />
                        <path d="M70 5 H77 C79 5 79.5 6 79.5 8 V13 H70 V5 Z" fill="#0f172a" />
                        
                        <!-- Biển số tuyến LED phát sáng: "METRO 02" -->
                        <text x="38" y="10.8" fill="#fbbf24" font-size="4.8" font-family="monospace" font-weight="bold" letter-spacing="0.5">METRO</text>
                        
                        <!-- Đèn hậu đỏ -->
                        <rect x="4" y="14" width="2" height="4" rx="0.8" fill="#f43f5e" class="animate-taillight-trail" />

                        <!-- Đèn pha LED trước -->
                        <circle cx="78.5" cy="15.5" r="1.8" fill="#fef08a" class="animate-headlight-pulse" />
                        
                        <!-- Chùm tia sáng đèn pha quét xa -->
                        <polygon points="80,15.5 100,10 100,22" fill="url(#busBeamGradient2)" class="animate-headlight-pulse opacity-80" />

                        <!-- Bánh xe -->
                        <circle cx="17" cy="22" r="4.2" fill="#0f172a" stroke="#fde68a" stroke-width="1.4" />
                        <circle cx="17" cy="22" r="1.8" fill="#f59e0b" />
                        <circle cx="67" cy="22" r="4.2" fill="#0f172a" stroke="#fde68a" stroke-width="1.4" />
                        <circle cx="67" cy="22" r="1.8" fill="#f59e0b" />

                        <defs>
                            <linearGradient id="busBeamGradient2" x1="80" y1="15.5" x2="100" y2="15.5" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#fef08a" stop-opacity="0.95" />
                                <stop offset="60%" stop-color="#fef08a" stop-opacity="0.4" />
                                <stop offset="100%" stop-color="#fef08a" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                    </svg>

                    <!-- Huy hiệu "Metro Rapid" -->
                    <span class="text-[9px] font-bold text-amber-300 bg-amber-950/80 px-1.5 py-0.5 rounded border border-amber-500/40 -ml-2 -mb-2 shadow-sm whitespace-nowrap">
                        02 Rapid
                    </span>
                </div>
            </div>

        </div>

        <!-- ============================================================== -->
        <!-- LỚP NỘI DUNG & CÁC NÚT ĐIỀU HƯỚNG CHỨC NĂNG (NAVIGATION LINKS)  -->
        <!-- (Nổi trên z-20, nền kính mờ bảo đảm click và nhìn siêu rõ)     -->
        <!-- ============================================================== -->
        <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 sm:h-20">
                
                <!-- Brand / Logo với biểu tượng Bus & Train Động -->
                <div class="flex items-center gap-3">
                    <a href="index.php" class="group flex items-center gap-3 focus:outline-none">
                        <div class="relative w-11 h-11 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-500 to-sky-400 p-0.5 shadow-glow flex items-center justify-center transform group-hover:scale-105 transition duration-300">
                            <div class="w-full h-full bg-slate-900 rounded-[14px] flex items-center justify-center relative overflow-hidden">
                                <!-- Xe bus có hiệu ứng nhún suspension sống động -->
                                <i class="bi bi-bus-front-fill text-lg text-sky-400 -mr-1 group-hover:text-amber-400 transition animate-bus-suspension"></i>
                                <i class="bi bi-train-front-fill text-sm text-indigo-400 -ml-1 opacity-80"></i>
                            </div>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-base sm:text-lg font-extrabold tracking-tight text-white flex items-center gap-2">
                                Smart Transit GTFS
                                <span class="hidden md:inline-block text-[10px] uppercase font-semibold px-2 py-0.5 rounded-full bg-brand-500/20 text-brand-300 border border-brand-500/30">Bus &amp; Metro</span>
                            </span>
                            <span class="text-[11px] text-slate-400 -mt-0.5 font-medium hidden sm:block">Tra Cứu Lịch Trình Giao Thông Công Cộng</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links (Nút điều hướng chức năng giữ nguyên 100%) -->
                <nav class="hidden lg:flex items-center gap-1.5 bg-slate-900/80 backdrop-blur-md p-1.5 rounded-2xl border border-slate-800/80 shadow-md">
                    <a href="index.php" class="px-3.5 py-2 text-sm font-semibold rounded-xl bg-brand-600/40 text-white border border-brand-500/50 shadow-sm flex items-center gap-2 transition hover:bg-brand-600/50">
                        <i class="bi bi-house-door-fill text-brand-400"></i>
                        <span>Trang chủ</span>
                    </a>
                    <a href="<?php echo $user ? 'views/gtfs_browse.php' : 'auth/login.php'; ?>" class="px-3.5 py-2 text-sm font-medium rounded-xl text-slate-200 hover:text-white hover:bg-slate-800/80 transition flex items-center gap-2">
                        <i class="bi bi-compass text-sky-400"></i>
                        <span>Tuyến &amp; Trạm</span>
                    </a>
                    <a href="<?php echo $user ? 'views/next_trip.php' : 'auth/login.php'; ?>" class="px-3.5 py-2 text-sm font-semibold rounded-xl bg-amber-500/15 text-amber-300 hover:bg-amber-500/25 border border-amber-500/30 transition flex items-center gap-2">
                        <i class="bi bi-clock-history text-amber-400"></i>
                        <span>Chuyến Kế Tiếp</span>
                    </a>
                    <?php if ($user): ?>
                        <a href="views/saved_queries.php" class="px-3.5 py-2 text-sm font-medium rounded-xl text-slate-200 hover:text-white hover:bg-slate-800/80 transition flex items-center gap-2">
                            <i class="bi bi-bookmark-star-fill text-amber-400"></i>
                            <span>Truy Vấn Đã Lưu</span>
                        </a>
                    <?php endif; ?>
                </nav>

                <!-- KHỐI TRẠNG THÁI HỆ THỐNG & USER ACTIONS TRÊN NAVBAR -->
                <div class="flex items-center gap-3">
                    
                    <!-- Badge Radar Giám Sát Xe Bus & Metro Trực Tuyến Toàn Hệ Thống -->
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900/85 border border-slate-700/80 shadow-inner backdrop-blur-md">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                        </span>
                        <span class="text-xs font-bold text-slate-200 tracking-tight whitespace-nowrap flex items-center gap-1.5">
                            <i class="bi bi-broadcast text-emerald-400"></i>
                            <span>Radar Xe Bus</span>
                            <span class="text-[10px] font-semibold text-emerald-300 bg-emerald-500/20 px-1.5 py-0.2 rounded">LIVE</span>
                        </span>
                    </div>

                    <?php if ($user): ?>
                        <div class="hidden sm:flex items-center gap-2 pl-2 border-l border-slate-800">
                            <div class="flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700/60 backdrop-blur-md">
                                <div class="w-7 h-7 rounded-full bg-brand-600/40 text-brand-300 flex items-center justify-center font-bold text-xs border border-brand-500/40">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="flex flex-col text-left">
                                    <span class="text-xs font-semibold text-slate-200 leading-tight"><?php echo htmlspecialchars($user['fullname']); ?></span>
                                    <span class="text-[10px] text-slate-400 capitalize"><?php echo htmlspecialchars($user['role'] ?? 'Member'); ?></span>
                                </div>
                            </div>

                            <?php if ($user['role'] === 'admin'): ?>
                                <a href="admin/gtfs.php" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 hover:from-amber-400 hover:to-amber-500 shadow-sm transition">
                                    <i class="bi bi-speedometer2"></i>
                                    <span>Quản Trị GTFS</span>
                                </a>
                            <?php endif; ?>

                            <a href="auth/logout.php" title="Đăng xuất" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-slate-800/80 rounded-xl transition">
                                <i class="bi bi-box-arrow-right text-lg"></i>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Mobile Menu Button -->
                    <button id="mobile-menu-btn" type="button" class="sm:hidden p-2 rounded-xl text-slate-300 hover:text-white bg-slate-800/80 border border-slate-700/60 focus:outline-none" aria-label="Toggle Navigation">
                        <i id="menu-icon-bars" class="bi bi-list text-2xl"></i>
                        <i id="menu-icon-close" class="bi bi-x-lg text-xl hidden"></i>
                    </button>
                </div>

            </div>
        </div>

        <!-- Mobile Drawer Menu (Giữ nguyên các nút chức năng trên di động) -->
        <div id="mobile-menu" class="hidden sm:hidden relative z-30 bg-slate-900/98 border-t border-slate-800/80 px-4 pt-3 pb-6 space-y-2 backdrop-blur-xl">
            
            <!-- Live Bus Tracking Status cho Mobile -->
            <div class="flex items-center justify-between p-2.5 mb-2 rounded-xl bg-slate-800/80 border border-slate-700/60">
                <span class="text-xs font-bold text-slate-300 flex items-center gap-2">
                    <i class="bi bi-broadcast text-emerald-400 animate-pulse"></i> Tuyến Xe Bus Đang Chạy
                </span>
                <span class="text-[11px] font-bold text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">
                    Live Tracking Active
                </span>
            </div>

            <a href="index.php" class="block px-3 py-2.5 rounded-xl text-base font-semibold text-white bg-brand-600/30 border border-brand-500/40">
                <i class="bi bi-house-door-fill text-brand-400 mr-2"></i> Trang chủ
            </a>
            <a href="<?php echo $user ? 'views/gtfs_browse.php' : 'auth/login.php'; ?>" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800">
                <i class="bi bi-compass text-sky-400 mr-2"></i> Tuyến &amp; Trạm
            </a>
            <a href="<?php echo $user ? 'views/next_trip.php' : 'auth/login.php'; ?>" class="block px-3 py-2.5 rounded-xl text-base font-semibold text-amber-300 bg-amber-500/10 border border-amber-500/20">
                <i class="bi bi-clock-history text-amber-400 mr-2"></i> Chuyến Kế Tiếp
            </a>
            <?php if ($user): ?>
                <a href="views/saved_queries.php" class="block px-3 py-2.5 rounded-xl text-base font-medium text-slate-300 hover:bg-slate-800">
                    <i class="bi bi-bookmark-star-fill text-amber-400 mr-2"></i> Truy Vấn Đã Lưu
                </a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin/gtfs.php" class="block px-3 py-2.5 rounded-xl text-base font-bold text-amber-400 hover:bg-slate-800">
                        <i class="bi bi-speedometer2 mr-2"></i> Quản Trị GTFS
                    </a>
                <?php endif; ?>
                <div class="pt-3 border-t border-slate-800 flex items-center justify-between">
                    <span class="text-sm font-semibold text-slate-200">
                        <i class="bi bi-person-circle text-brand-400 mr-1.5"></i> <?php echo htmlspecialchars($user['fullname']); ?>
                    </span>
                    <a href="auth/logout.php" class="text-sm font-medium text-rose-400 hover:text-rose-300">Đăng xuất</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- ========================================================== -->
    <!-- 2. HERO SECTION LÀM RÕ ẢNH XE BUS & TÀU METRO + CHỮ SIÊU RÕ -->
    <!-- ========================================================== -->
    <section class="relative bg-slate-950 text-white pt-16 pb-28 sm:pt-20 sm:pb-36 lg:pt-24 lg:pb-44 overflow-hidden">
        
        <!-- Hình ảnh Xe Buýt & Tàu Metro Hiển Thị Sắc Nét, Rõ Ràng (Opacity 80%) -->
        <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
            <div 
                class="absolute inset-0 bg-cover bg-center animate-hero-bg opacity-75 sm:opacity-85 filter contrast-110 saturate-110" 
                style="background-image: url('https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?auto=format&fit=crop&w=2200&q=90');">
            </div>
            <!-- Lớp phủ Gradient thông minh: Giữ sáng ở hai bên & viền để thấy rõ xe bus & metro, chỉ làm tối nhẹ ở tâm -->
            <div class="absolute inset-0 bg-gradient-to-b from-slate-950/85 via-slate-950/50 to-slate-950/90"></div>
            <div class="absolute inset-0 hero-pattern opacity-25"></div>
        </div>

        <!-- Ambient Glow Orbs -->
        <div class="absolute top-1/4 left-1/4 w-[28rem] h-[28rem] bg-indigo-500/15 rounded-full blur-3xl pointer-events-none -z-0"></div>
        <div class="absolute bottom-1/4 right-1/4 w-[28rem] h-[28rem] bg-sky-500/15 rounded-full blur-3xl pointer-events-none -z-0"></div>

        <div class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            
            <!-- Khung Bảo Vệ Scrim Glass Giúp Chữ & Badges Hiển Thị 100% Sắc Nét -->
            <div class="hero-scrim-glass backdrop-blur-md p-6 sm:p-10 rounded-3xl border border-white/15 shadow-2xl inline-block max-w-4xl mx-auto mb-10">
                
                <!-- Floating Decorative Transit Badges (Xe Bus & Tàu Điện) -->
                <div class="flex flex-wrap items-center justify-center gap-3 sm:gap-4 mb-6 sm:mb-7">
                    <!-- Bus Pill Badge -->
                    <div class="animate-float inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-slate-950/90 border-2 border-emerald-400/60 backdrop-blur-md text-xs font-bold text-emerald-300 shadow-xl">
                        <span class="w-6 h-6 rounded-full bg-emerald-500/30 flex items-center justify-center text-emerald-300">
                            <i class="bi bi-bus-front-fill text-sm"></i>
                        </span>
                        <span class="text-hero-sub">Xe Buýt Đô Thị (City Bus)</span>
                    </div>

                    <!-- Metro / Train Pill Badge -->
                    <div class="animate-float-delayed inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-slate-950/90 border-2 border-sky-400/60 backdrop-blur-md text-xs font-bold text-sky-300 shadow-xl">
                        <span class="w-6 h-6 rounded-full bg-sky-500/30 flex items-center justify-center text-sky-300">
                            <i class="bi bi-train-front-fill text-sm"></i>
                        </span>
                        <span class="text-hero-sub">Tàu Điện Metro &amp; Đường Sắt</span>
                    </div>

                    <!-- GTFS Status Badge -->
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-slate-950/90 border-2 border-amber-400/60 backdrop-blur-md text-xs font-bold text-amber-300 shadow-xl">
                        <i class="bi bi-database-check text-amber-400"></i>
                        <span class="text-hero-sub">Chuẩn Dữ Liệu GTFS Tĩnh</span>
                    </div>
                </div>

                <!-- Tiêu Đề Chính Siêu Rõ Ràng (Tương Phản Tuyệt Đối) -->
                <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-tight">
                    <span class="block text-white text-hero-title mb-1">Quản Lý &amp; Tra Cứu Lịch Trình</span>
                    <span class="block text-transparent bg-clip-text bg-gradient-to-r from-amber-300 via-sky-200 to-white text-hero-title">
                        Giao Thông Công Cộng
                    </span>
                </h1>

                <!-- Đoạn Mô Tả Dễ Đọc Kèm Nền Scrim -->
                <p class="max-w-2xl mx-auto text-base sm:text-lg text-slate-100 font-medium leading-relaxed text-hero-sub mt-4 sm:mt-5">
                    Hệ thống thông tin giao thông tích hợp đa phương thức: tra cứu lộ trình xe buýt, tàu điện ngầm metro, thời gian xuất bến và trạm dừng theo thời gian thực.
                </p>

            </div>

            <!-- ============================================== -->
            <!-- FLOATING SEARCH BAR HOẶC CTA BOX NỔI KHỐI      -->
            <!-- ============================================== -->
            <div class="max-w-4xl mx-auto transform -mb-20 sm:-mb-24 lg:-mb-28 transition-all">
                <?php if ($user): ?>
                    <!-- Form Tra Cứu Tuyến Xe Khi Đã Đăng Nhập -->
                    <form method="GET" action="index.php" class="relative group">
                        <div class="flex flex-col sm:flex-row items-stretch p-2.5 bg-white/95 backdrop-blur-xl rounded-2xl sm:rounded-full cta-floating-shadow border border-white/80 focus-within:ring-4 focus-within:ring-brand-500/30 transition duration-300">
                            
                            <div class="flex items-center flex-1 px-4 py-2 sm:py-0">
                                <i class="bi bi-search text-xl text-slate-400 group-focus-within:text-brand-600 transition mr-3"></i>
                                <input 
                                    type="text" 
                                    name="keyword" 
                                    value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Tìm theo số tuyến bus, tuyến metro, tên đường, trạm dừng..."
                                    class="w-full bg-transparent text-slate-800 placeholder-slate-400 text-sm sm:text-base font-medium focus:outline-none"
                                >
                            </div>

                            <div class="flex items-center gap-2 pt-2 sm:pt-0">
                                <?php if ($keyword !== ''): ?>
                                    <a href="index.php" title="Xóa bộ lọc" class="p-3 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-full transition flex items-center justify-center">
                                        <i class="bi bi-x-lg text-sm"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="submit" class="w-full sm:w-auto px-8 py-3.5 bg-gradient-to-r from-brand-600 via-indigo-600 to-brand-700 hover:from-brand-500 hover:to-indigo-500 text-white font-bold text-sm sm:text-base rounded-xl sm:rounded-full shadow-md hover:shadow-glow flex items-center justify-center gap-2 transition duration-200">
                                    <span>Tra Cứu Tuyến</span>
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <!-- KHỐI CTA BOX NỔI KHỐI CHUYÊN NGHIỆP (KHI CHƯA ĐĂNG NHẬP) -->
                    <div class="bg-white/98 backdrop-blur-xl rounded-3xl p-6 sm:p-8 cta-floating-shadow border border-white/90 text-left">
                        <div class="flex flex-col lg:flex-row items-center justify-between gap-6">
                            
                            <!-- Cột Nội Dung Bên Trái -->
                            <div class="flex items-start sm:items-center gap-4 sm:gap-5 flex-1">
                                <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-amber-500/20 via-orange-500/15 to-amber-500/5 border border-amber-500/30 text-amber-600 flex items-center justify-center text-2xl sm:text-3xl shrink-0 shadow-inner">
                                    <i class="bi bi-shield-lock-fill"></i>
                                </div>
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-base sm:text-lg font-bold text-zinc-50 leading-snug">
                                            Yêu cầu đăng nhập để sử dụng tính năng tra cứu
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            Bảo Mật
                                        </span>
                                    </div>
                                    <p class="text-xs sm:text-sm text-slate-500 leading-relaxed max-w-xl">
                                        Đăng nhập tài khoản để tra cứu chi tiết lộ trình xe buýt, metro, thời gian xuất bến thực tế và lưu trữ truy vấn yêu thích.
                                    </p>
                                    
                                    <!-- Badge Lợi Ích Đi Kèm -->
                                    <div class="hidden sm:flex items-center gap-3 pt-1 text-[11px] font-semibold text-slate-400">
                                        <span class="flex items-center gap-1"><i class="bi bi-check-circle-fill text-emerald-500"></i> Chuẩn GTFS Tĩnh</span>
                                        <span>&bull;</span>
                                        <span class="flex items-center gap-1"><i class="bi bi-check-circle-fill text-emerald-500"></i> Cập nhật liên tục</span>
                                        <span>&bull;</span>
                                        <span class="flex items-center gap-1"><i class="bi bi-check-circle-fill text-emerald-500"></i> Miễn phí 100%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Cột Nút Bấm Hành Động Bên Phải -->
                            <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto shrink-0">
                                <a href="auth/login.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl sm:rounded-2xl text-sm font-bold text-white bg-gradient-to-r from-brand-600 via-indigo-600 to-brand-700 hover:from-brand-500 hover:to-indigo-500 shadow-lg shadow-brand-500/25 hover:shadow-glow transform hover:-translate-y-0.5 transition duration-200 whitespace-nowrap">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    <span>Đăng Nhập Ngay</span>
                                </a>
                                <a href="auth/register.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl sm:rounded-2xl text-sm font-bold text-slate-700 hover:text-slate-900 bg-slate-100/90 hover:bg-slate-200/80 border border-slate-200/90 transition duration-200 whitespace-nowrap">
                                    <i class="bi bi-person-plus"></i>
                                    <span>Tạo Tài Khoản</span>
                                </a>
                            </div>

                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </section>

    <!-- ============================================== -->
    <!-- 3. TRANSIT SHOWCASE (LOGO XE BUS & TÀU ĐIỆN)   -->
    <!-- ============================================== -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-28 sm:pt-32 lg:pt-36 mb-12">
        
        <!-- Khung Trang Trí Mô Phỏng Phương Tiện Giao Thông Công Cộng -->
        <div class="bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white relative overflow-hidden shadow-xl border border-slate-800">
            <!-- Background glow & road line grid -->
            <div class="absolute inset-0 opacity-10 pointer-events-none bg-[radial-gradient(#6366f1_1px,transparent_1px)] [background-size:16px_16px]"></div>
            
            <div class="relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                
                <div class="lg:col-span-7 space-y-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/20 border border-brand-500/30 text-brand-300 text-xs font-bold uppercase tracking-wider">
                        <i class="bi bi-geo-alt-fill text-amber-400"></i> Mạng Lưới Phương Tiện Đa Tầng
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
                        Hệ Thống Xe Buýt Nội Đô &amp; Tuyến Tàu Điện Metro Hiện Đại
                    </h2>
                    <p class="text-slate-300 text-sm leading-relaxed">
                        Toàn bộ dữ liệu tuyến đường, vị trí tọa độ trạm dừng (Stops), lịch trình khởi hành (Stop Times) và chuyến xe (Trips) được chuẩn hóa theo định dạng <strong>GTFS Tĩnh Quốc Tế</strong>, giúp hành khách luôn chủ động lộ trình di chuyển.
                    </p>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2">
                        <div class="p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-emerald-400 font-bold text-sm mb-1">
                                <i class="bi bi-bus-front text-lg"></i> Tuyến Xe Bus
                            </div>
                            <span class="text-xs text-slate-400">Nội thành &amp; Liên tỉnh</span>
                        </div>
                        <div class="p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-sky-400 font-bold text-sm mb-1">
                                <i class="bi bi-train-front text-lg"></i> Tàu Điện Metro
                            </div>
                            <span class="text-xs text-slate-400">Đường sắt trên cao</span>
                        </div>
                        <div class="p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm col-span-2 sm:col-span-1">
                            <div class="flex items-center gap-2 text-amber-400 font-bold text-sm mb-1">
                                <i class="bi bi-signpost-split text-lg"></i> Trạm Trung Chuyển
                            </div>
                            <span class="text-xs text-slate-400">Kết nối đa phương thức</span>
                        </div>
                    </div>
                </div>

                <!-- Graphic Showcase Trực Quan Xe Bus & Tàu Điện -->
                <div class="lg:col-span-5 flex flex-col gap-4">
                    <!-- Card Trang trí Xe Bus -->
                    <div class="bg-gradient-to-r from-emerald-950/60 to-slate-900/90 border border-emerald-500/30 rounded-2xl p-4 flex items-center gap-4 transform hover:scale-[1.02] transition duration-300">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-3xl text-emerald-400 shrink-0">
                            <i class="bi bi-bus-front-fill"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold text-white">Xe Buýt Công Cộng</span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300">Hoạt động 05:00 - 22:30</span>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">Hệ thống mạng lưới xe bus phủ khắp các trục đường chính và các bến trung chuyển đô thị.</p>
                        </div>
                    </div>

                    <!-- Card Trang trí Tàu Điện Metro -->
                    <div class="bg-gradient-to-r from-indigo-950/60 to-slate-900/90 border border-indigo-500/30 rounded-2xl p-4 flex items-center gap-4 transform hover:scale-[1.02] transition duration-300">
                        <div class="w-14 h-14 rounded-2xl bg-indigo-500/20 border border-indigo-500/40 flex items-center justify-center text-3xl text-sky-400 shrink-0">
                            <i class="bi bi-train-front-fill"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold text-white">Tàu Điện Metro Tuyến 01</span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-300">Tần suất 5 - 10 phút</span>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">Tuyến tàu điện ngầm và trên cao kết nối trung tâm thành phố với các quận cửa ngõ.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </section>

    <!-- ============================================== -->
    <!-- 4. MAIN CONTENT CONTAINER                      -->
    <!-- ============================================== -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 pb-16">

        <!-- QUICK ACTIONS GRID -->
        <section class="mb-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                
                <!-- Action 1: Chuyến Kế Tiếp -->
                <a href="<?php echo $user ? 'views/next_trip.php' : 'auth/login.php'; ?>" class="group relative bg-white rounded-2xl p-6 shadow-card hover:shadow-card-hover border border-slate-200/80 hover:border-brand-500/50 transition-all duration-300 transform hover:-translate-y-1">
                    <div class="flex items-start gap-4">
                        <div class="w-13 h-13 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-2xl group-hover:bg-indigo-600 group-hover:text-white transition duration-300 p-3.5 shadow-sm">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-bold text-slate-900 group-hover:text-brand-600 transition">Tìm Chuyến Kế Tiếp</h3>
                                <i class="bi bi-arrow-up-right text-slate-400 group-hover:text-brand-600 transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition"></i>
                            </div>
                            <p class="text-xs sm:text-sm text-slate-500 mt-1 leading-relaxed">
                                Xử lý ngày hoạt động, ngoại lệ GTFS và thuật toán chuyển tiếp giờ đêm vượt 24:00.
                            </p>
                        </div>
                    </div>
                    <div class="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-indigo-500 to-sky-500 rounded-b-2xl opacity-0 group-hover:opacity-100 transition duration-300"></div>
                </a>

                <!-- Action 2: Tuyến & Trạm -->
                <a href="<?php echo $user ? 'views/gtfs_browse.php' : 'auth/login.php'; ?>" class="group relative bg-white rounded-2xl p-6 shadow-card hover:shadow-card-hover border border-slate-200/80 hover:border-emerald-500/50 transition-all duration-300 transform hover:-translate-y-1">
                    <div class="flex items-start gap-4">
                        <div class="w-13 h-13 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-2xl group-hover:bg-emerald-600 group-hover:text-white transition duration-300 p-3.5 shadow-sm">
                            <i class="bi bi-compass"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-bold text-slate-900 group-hover:text-emerald-600 transition">Tra Cứu Tuyến &amp; Trạm</h3>
                                <i class="bi bi-arrow-up-right text-slate-400 group-hover:text-emerald-600 transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition"></i>
                            </div>
                            <p class="text-xs sm:text-sm text-slate-500 mt-1 leading-relaxed">
                                Xem danh mục trạm dừng, thứ tự đón trả khách, chiều đi về và thông tin vận hành.
                            </p>
                        </div>
                    </div>
                    <div class="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-b-2xl opacity-0 group-hover:opacity-100 transition duration-300"></div>
                </a>

                <!-- Action 3: Truy Vấn Đã Lưu -->
                <a href="<?php echo $user ? 'views/saved_queries.php' : 'auth/login.php'; ?>" class="group relative bg-white rounded-2xl p-6 shadow-card hover:shadow-card-hover border border-slate-200/80 hover:border-amber-500/50 transition-all duration-300 transform hover:-translate-y-1">
                    <div class="flex items-start gap-4">
                        <div class="w-13 h-13 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-2xl group-hover:bg-amber-600 group-hover:text-white transition duration-300 p-3.5 shadow-sm">
                            <i class="bi bi-bookmark-star"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-bold text-slate-900 group-hover:text-amber-600 transition">Truy Vấn Mẫu Đã Lưu</h3>
                                <i class="bi bi-arrow-up-right text-slate-400 group-hover:text-amber-600 transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition"></i>
                            </div>
                            <p class="text-xs sm:text-sm text-slate-500 mt-1 leading-relaxed">
                                Lưu lại các tuyến đường quen thuộc và chạy nhanh trên các phiên bản lịch trình mới.
                            </p>
                        </div>
                    </div>
                    <div class="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-amber-500 to-orange-500 rounded-b-2xl opacity-0 group-hover:opacity-100 transition duration-300"></div>
                </a>

            </div>
        </section>

        <!-- ============================================== -->
        <!-- 5. ĐIỀU KIỆN HIỂN THỊ DỮ LIỆU GTFS            -->
        <!-- Chỉ hiển thị khi: Đã đăng nhập & Có GTFS Active -->
        <!-- ============================================== -->

        <?php if (!$user): ?>
            <!-- GIAO DIỆN KHI NGƯỜI DÙNG CHƯA ĐĂNG NHẬP -->
            <div class="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200 shadow-card text-center max-w-2xl mx-auto">
                <div class="w-16 h-16 rounded-2xl bg-indigo-50 text-brand-600 flex items-center justify-center text-3xl mx-auto mb-4 shadow-sm">
                    <i class="bi bi-lock-fill"></i>
                </div>
                <h3 class="text-xl sm:text-2xl font-extrabold text-slate-900 mb-2">Vui lòng đăng nhập để tra cứu lịch trình</h3>
                <p class="text-slate-500 text-sm mb-6 leading-relaxed">
                    Hệ thống chỉ cung cấp dữ liệu chi tiết về mạng lưới tuyến xe bus, tàu điện metro, lịch trình trạm dừng và dự báo giờ đến cho thành viên đã đăng nhập.
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="auth/login.php" class="w-full sm:w-auto px-6 py-3 rounded-xl text-sm font-bold text-white bg-brand-600 hover:bg-brand-500 shadow-glow transition">
                        <i class="bi bi-box-arrow-in-right mr-1.5"></i> Đăng Nhập
                    </a>
                    <a href="auth/register.php" class="w-full sm:w-auto px-6 py-3 rounded-xl text-sm font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                        <i class="bi bi-person-plus mr-1.5"></i> Đăng Ký Tài Khoản Mới
                    </a>
                </div>
            </div>

        <?php elseif (!$active_version): ?>
            <!-- GIAO DIỆN KHI ĐÃ ĐĂNG NHẬP NHƯNG CHƯA CÓ PHIÊN BẢN GTFS NÀO ĐƯỢC KÍCH HOẠT -->
            <div class="p-6 sm:p-8 rounded-3xl bg-amber-50/90 border border-amber-200/90 shadow-sm text-center max-w-2xl mx-auto">
                <div class="w-14 h-14 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-2xl mx-auto mb-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h3 class="text-lg sm:text-xl font-bold text-amber-900 mb-2">Chưa Có Dữ Liệu Lịch Trình GTFS</h3>
                <p class="text-amber-800 text-sm mb-6 leading-relaxed">
                    Hệ thống hiện tại chưa có phiên bản GTFS (General Transit Feed Specification) nào được kích hoạt. Dữ liệu các tuyến xe bus và tàu metro sẽ tự động hiển thị tại đây ngay khi Quản trị viên tải lên và kích hoạt tệp dữ liệu GTFS.
                </p>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin/gtfs.php?tab=import" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold bg-amber-600 hover:bg-amber-500 text-white shadow-sm transition">
                        <i class="bi bi-cloud-arrow-up-fill"></i> Quản Trị &amp; Tải Lên Tệp Zip GTFS
                    </a>
                <?php else: ?>
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-white/80 text-amber-900 border border-amber-300">
                        <i class="bi bi-info-circle-fill text-amber-600"></i> Vui lòng liên hệ Quản trị viên để cập nhật phiên bản GTFS
                    </span>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- GIAO DIỆN HIỂN THỊ DANH SÁCH TUYẾN GTFS THỰC TẾ -->
            
            <!-- GTFS Active Status Banner -->
            <div class="mb-8 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-indigo-50 via-sky-50 to-white border border-indigo-100 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xl shrink-0">
                        <i class="bi bi-database-check"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            Dữ liệu GTFS hợp lệ đang áp dụng: <span class="text-indigo-700"><?php echo htmlspecialchars($active_version['version_name']); ?></span>
                        </h4>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Cập nhật lúc: <?php echo date('d/m/Y H:i', strtotime($active_version['uploaded_at'] ?? 'now')); ?> &bull; Định dạng GTFS tĩnh chuẩn Quốc tế
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-4 text-xs font-semibold text-slate-600 self-end sm:self-center">
                    <span class="flex items-center gap-1.5 bg-white px-3 py-1.5 rounded-lg border border-slate-200">
                        <i class="bi bi-signpost-2 text-indigo-600"></i> <?php echo count($gtfs_routes); ?> Tuyến
                    </span>
                    <span class="flex items-center gap-1.5 bg-white px-3 py-1.5 rounded-lg border border-slate-200">
                        <i class="bi bi-geo-alt text-emerald-600"></i> <?php echo $total_gtfs_stops; ?> Trạm
                    </span>
                    <span class="flex items-center gap-1.5 bg-white px-3 py-1.5 rounded-lg border border-slate-200">
                        <i class="bi bi-bus-front text-sky-600"></i> <?php echo $total_gtfs_trips; ?> Chuyến
                    </span>
                </div>
            </div>

            <!-- Header danh sách tuyến -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                        <i class="bi bi-signpost-2-fill text-brand-600"></i>
                        Danh Sách Tuyến Vận Tải Công Cộng
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">
                        <?php if ($keyword !== ''): ?>
                            Kết quả tìm kiếm cho: "<strong class="text-brand-600"><?php echo htmlspecialchars($keyword); ?></strong>" (Tìm thấy <?php echo count($gtfs_routes); ?> tuyến)
                        <?php else: ?>
                            Tổng hợp toàn bộ các tuyến bus &amp; đường sắt đô thị đang hoạt động trong hệ thống
                        <?php endif; ?>
                    </p>
                </div>

                <a href="views/gtfs_browse.php?tab=stops" class="inline-flex items-center gap-2 text-xs font-bold text-brand-600 hover:text-brand-700 bg-brand-50 hover:bg-brand-100 px-4 py-2.5 rounded-xl border border-brand-200 transition">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span>Xem toàn bộ trạm dừng</span>
                </a>
            </div>

            <?php if (empty($gtfs_routes)): ?>
                <!-- Empty State -->
                <div class="bg-white rounded-3xl p-10 text-center border border-slate-200 shadow-sm max-w-lg mx-auto">
                    <div class="w-16 h-16 mx-auto rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center text-3xl mb-4">
                        <i class="bi bi-search"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-1">Không tìm thấy tuyến phù hợp!</h3>
                    <p class="text-xs sm:text-sm text-slate-500 mb-6 leading-relaxed">
                        Không có tuyến xe hoặc đường nào khớp với từ khóa "<strong class="text-slate-800"><?php echo htmlspecialchars($keyword); ?></strong>". Vui lòng thử lại với tên khác.
                    </p>
                    <a href="index.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white shadow-glow transition">
                        <i class="bi bi-arrow-repeat"></i> Xem tất cả các tuyến
                    </a>
                </div>
            <?php else: ?>
                <!-- Route Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($gtfs_routes as $r): 
                        $routeTrips = search_trips(['route_id' => $r['route_id']], $active_version['id']);
                        $iconClass = route_type_icon($r['route_type']);
                        $typeName = route_type_name($r['route_type']);
                    ?>
                        <article class="group bg-white rounded-2xl p-6 shadow-card hover:shadow-card-hover border border-slate-200/90 hover:border-brand-500/40 flex flex-col justify-between transition-all duration-300 transform hover:-translate-y-1">
                            <div>
                                <!-- Top Badges -->
                                <div class="flex items-center justify-between gap-2 mb-4">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold bg-brand-50 text-brand-700 border border-brand-200/80">
                                        <i class="bi <?php echo $iconClass; ?> text-brand-600"></i>
                                        Tuyến <?php echo htmlspecialchars($r['route_short_name']); ?>
                                    </span>
                                    <span class="text-[11px] font-mono font-medium px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 border border-slate-200">
                                        Mã: <?php echo htmlspecialchars($r['route_id']); ?>
                                    </span>
                                </div>

                                <!-- Route Long Name -->
                                <h3 class="text-base font-bold text-slate-900 group-hover:text-brand-600 transition line-clamp-2 mb-3 leading-snug">
                                    <?php echo htmlspecialchars($r['route_long_name']); ?>
                                </h3>

                                <!-- Route Meta Details -->
                                <div class="space-y-1.5 text-xs text-slate-500 mb-5">
                                    <div class="flex items-center gap-1.5">
                                        <i class="bi bi-tag text-slate-400"></i>
                                        <span>Phương tiện:</span>
                                        <strong class="text-slate-700 font-semibold"><?php echo $typeName; ?></strong>
                                    </div>
                                    <?php if (!empty($r['agency_name'])): ?>
                                        <div class="flex items-center gap-1.5">
                                            <i class="bi bi-building text-slate-400"></i>
                                            <span>Vận hành:</span>
                                            <strong class="text-slate-700 font-semibold truncate"><?php echo htmlspecialchars($r['agency_name']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <!-- Trip count indicator -->
                                <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs text-slate-600 mb-4">
                                    <span class="flex items-center gap-1.5">
                                        <i class="bi bi-bus-front-fill text-brand-600"></i> Số chuyến trong lịch:
                                    </span>
                                    <span class="font-bold text-slate-900"><?php echo count($routeTrips); ?> chuyến</span>
                                </div>

                                <!-- Action Button -->
                                <a href="views/gtfs_browse.php?tab=routes&route_id=<?php echo urlencode($r['route_id']); ?>" class="w-full inline-flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl text-xs font-bold text-brand-700 bg-brand-50 hover:bg-brand-600 hover:text-white border border-brand-200 hover:border-transparent transition-all duration-200">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Xem lịch các chuyến</span>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </main>

    <!-- ============================================== -->
    <!-- 6. MODERN MINIMALIST FOOTER                    -->
    <!-- ============================================== -->
    <footer class="bg-white border-t border-slate-200 py-8 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2 font-medium">
                <i class="bi bi-bus-front-fill text-brand-600 text-sm"></i>
                <i class="bi bi-train-front-fill text-sky-600 text-sm"></i>
                <span>&copy; <?php echo date('Y'); ?> Hệ Thống Quản Lý &amp; Tra Cứu Lịch Trình Giao Thông Công Cộng (Chuẩn GTFS).</span>
            </div>
            <div class="flex items-center gap-4 text-slate-400">
                <span class="hover:text-slate-600 transition">General Transit Feed Specification (GTFS Tĩnh)</span>
                <span>&bull;</span>
                <span class="hover:text-slate-600 transition">Tailwind CSS &amp; Smart Mobility UI</span>
            </div>
        </div>
    </footer>

    <!-- Mobile Menu Interaction Script (Vanilla JS) -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const btn = document.getElementById('mobile-menu-btn');
            const menu = document.getElementById('mobile-menu');
            const iconBars = document.getElementById('menu-icon-bars');
            const iconClose = document.getElementById('menu-icon-close');

            if (btn && menu) {
                btn.addEventListener('click', function () {
                    const isExpanded = menu.classList.toggle('hidden');
                    if (isExpanded) {
                        iconBars.classList.remove('hidden');
                        iconClose.classList.add('hidden');
                    } else {
                        iconBars.classList.add('hidden');
                        iconClose.classList.remove('hidden');
                    }
                });
            }
        });
    </script>
</body>
</html>
