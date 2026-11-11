<?php
// map.php - Bản đồ các tuyến đường: vẽ lộ trình tuyến và các trạm dừng lên bản đồ (Leaflet + OpenStreetMap)
// Tích hợp Tra Cứu Tuyến & Trạm (GTFS Browse tối giản) và Quản lý Truy Vấn Đã Lưu
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

// Xử lý Xóa truy vấn đã lưu (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_query' && $user) {
    $del_qid = (int)($_POST['query_id'] ?? 0);
    delete_saved_query($del_qid, (int)$user['id']);
    header('Location: map.php?tab=saved' . ($version_id ? '&version_id=' . $version_id : ''));
    exit;
}

// Xử lý Chạy lại truy vấn đã lưu
$run_qid = isset($_GET['run']) ? (int)$_GET['run'] : 0;
$query_to_run = null;
$run_results = null;
if ($run_qid > 0 && $user && $active_version) {
    $query_to_run = get_saved_query($run_qid);
    if ($query_to_run && (int)$query_to_run['user_id'] === (int)$user['id']) {
        $p = $query_to_run['parameters'] ?? [];
        if (($query_to_run['query_type'] ?? '') === 'next_trip' && !empty($p['stop_id'])) {
            $stop = get_stop($p['stop_id'], $active_version['id']);
            $date = $p['date'] ?? date('Y-m-d');
            $time = $p['time'] ?? date('H:i');
            $run_results = [
                'query_name' => $query_to_run['query_name'],
                'stop' => $stop,
                'date' => $date,
                'time' => $time,
                'trips' => find_next_trips($p['stop_id'], $date, $time, $active_version['id'], 15)
            ];
        }
    }
}
$saved_queries = $user ? get_saved_queries((int)$user['id']) : [];

// Dữ liệu Tra Cứu Tuyến & Trạm (GTFS tối giản)
$browse_subtab = $_GET['subtab'] ?? 'routes'; // 'routes' hoặc 'stops'
$browse_keyword = trim($_GET['browse_q'] ?? '');
$browse_routes = [];
$browse_stops = [];

// Cấu hình phân trang cho danh sách Trạm dừng (subtab=stops)
$stops_per_page = 10;
$stops_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$stops_total = 0;
$stops_total_pages = 1;
$total_stops_count = 0;
$total_routes_count = 0;

if ($active_version) {
    try {
        $total_stops_count = (int)$pdo->query("SELECT COUNT(*) FROM gtfs_stops WHERE feed_version_id = " . (int)$active_version['id'])->fetchColumn();
        $total_routes_count = (int)$pdo->query("SELECT COUNT(*) FROM gtfs_routes WHERE feed_version_id = " . (int)$active_version['id'])->fetchColumn();
    } catch (Exception $e) {}

    if ($browse_subtab === 'stops') {
        if ($browse_keyword !== '') {
            $kw = '%' . $browse_keyword . '%';
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM gtfs_stops WHERE feed_version_id = ? AND (stop_id LIKE ? OR stop_name LIKE ?)");
            $count_stmt->execute([$active_version['id'], $kw, $kw]);
            $stops_total = (int)$count_stmt->fetchColumn();
            $stops_total_pages = max(1, (int)ceil($stops_total / $stops_per_page));
            if ($stops_page > $stops_total_pages) $stops_page = $stops_total_pages;
            $offset = ($stops_page - 1) * $stops_per_page;

            $stmt = $pdo->prepare("SELECT * FROM gtfs_stops WHERE feed_version_id = ? AND (stop_id LIKE ? OR stop_name LIKE ?) ORDER BY stop_name ASC LIMIT $offset, $stops_per_page");
            $stmt->execute([$active_version['id'], $kw, $kw]);
            $browse_stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stops_total = $total_stops_count;
            $stops_total_pages = max(1, (int)ceil($stops_total / $stops_per_page));
            if ($stops_page > $stops_total_pages) $stops_page = $stops_total_pages;
            $offset = ($stops_page - 1) * $stops_per_page;

            $stmt = $pdo->prepare("SELECT * FROM gtfs_stops WHERE feed_version_id = ? ORDER BY stop_name ASC LIMIT $offset, $stops_per_page");
            $stmt->execute([$active_version['id']]);
            $browse_stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        if ($browse_keyword !== '') {
            $browse_routes = search_routes($browse_keyword, $active_version['id']);
        } else {
            $browse_routes = get_all_routes($active_version['id']);
        }
    }
}

$initial_route = $_GET['route_id'] ?? '';
$has_initial_route = !empty($initial_route);
$browse_col_class = $has_initial_route ? 'lg:col-span-3 xl:col-span-3' : 'lg:col-span-4 xl:col-span-4';
$map_col_class = $has_initial_route ? 'lg:col-span-5 xl:col-span-6' : 'lg:col-span-8 xl:col-span-8';
$wrap_initial_style = $has_initial_route ? '' : 'style="display:none;"';

$active_tab = $_GET['tab'] ?? ($run_qid > 0 ? 'saved' : (!empty($initial_route) ? 'map' : 'map'));
if (!in_array($active_tab, ['map', 'browse', 'saved'])) {
    $active_tab = 'map';
}
?>
<!DOCTYPE html>
<html lang="vi" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bản Đồ Tuyến Đường - Tra Cứu Lịch Trình GTFS</title>
    
    <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
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

    <!-- Bootstrap 5.3 & Icons & Bundle JS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #1e293b;
        }

        #map {
            height: 600px;
            min-height: 520px;
            width: 100%;
            z-index: 10;
        }

        #route-list {
            max-height: 520px;
            overflow-y: auto;
        }

        #stop-panel {
            max-height: 520px;
            overflow-y: auto;
        }

        #route-list::-webkit-scrollbar,
        #stop-panel::-webkit-scrollbar {
            width: 5px;
        }
        #route-list::-webkit-scrollbar-thumb,
        #stop-panel::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        #route-list::-webkit-scrollbar-thumb:hover,
        #stop-panel::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .route-item.active {
            background-color: #eff6ff !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.15) !important;
        }

        .dir-arrow {
            background: none;
            border: none;
        }

        /* Leaflet Popup Styling */
        .leaflet-popup-content-wrapper {
            border-radius: 1.25rem;
            box-shadow: 0 15px 30px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.1);
            font-family: 'Plus Jakarta Sans', sans-serif;
            padding: 0.25rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .leaflet-popup-content {
            margin: 0.85rem 1.15rem;
            line-height: 1.5;
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
<body class="min-h-full flex flex-col bg-slate-50 selection:bg-brand-600 selection:text-white">

    <!-- ============================================================= -->
    <!-- NAVBAR CHUẨN PARALLAX - NÚT "BẢN ĐỒ" ĐANG ACTIVE             -->
    <!-- ============================================================= -->
    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800/80 transition-all overflow-hidden relative shadow-lg">
        
        <!-- LỚP PARALLAX CẢNH VÔ TẬN & XE BUS ĐÔ THỊ ĐANG CHẠY LIÊN TỤC -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
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

            <!-- Lớp Parallax 2: City Skyline -->
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

            <!-- Lớp Parallax 3: Cao tốc -->
            <div class="absolute bottom-0 inset-x-0 h-[3px] bg-slate-950/90 border-t border-slate-800/80 flex items-center overflow-hidden">
                <div class="w-full h-full animate-highway-track opacity-85"></div>
            </div>
            <div class="absolute bottom-1 inset-x-0 h-[1px] bg-gradient-to-r from-transparent via-cyan-500/25 to-transparent"></div>

            <!-- Xe Buýt Đang Chạy Liên Tục -->
            <div class="absolute bottom-[3px] left-3 sm:left-6 md:left-10 flex items-end z-10 animate-bus-bounce">
                <div class="relative flex items-center">
                    <div class="absolute -left-2 bottom-1 pointer-events-none">
                        <div class="w-2.5 h-2.5 rounded-full bg-slate-400/40 blur-[1px] animate-exhaust-puff-1"></div>
                        <div class="w-2 h-2 rounded-full bg-slate-300/35 blur-[1px] animate-exhaust-puff-2 -mt-1"></div>
                    </div>
                    <div class="w-12 h-3.5 bg-gradient-to-l from-emerald-500/30 via-cyan-500/15 to-transparent blur-[2px] -mr-1"></div>

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

                        <rect x="5" y="4" width="83" height="21" rx="5" fill="url(#navBusPaintGrad)" stroke="#0f172a" stroke-width="1.2" />
                        <path d="M6 7 Q 48 3 87 7" stroke="#6ee7b7" stroke-width="1.2" fill="none" />
                        <rect x="8" y="6" width="14" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="24" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="41" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        <rect x="58" y="6" width="15" height="9" rx="1.5" fill="#0f172a" />
                        <path d="M75 6 H83 C86 6 87 7.5 87 9.5 V15 H75 V6 Z" fill="#0f172a" />
                        
                        <text x="42" y="12.5" fill="#38bdf8" font-size="5" font-family="monospace" font-weight="bold" letter-spacing="0.5">BUS 01</text>
                        
                        <rect x="5" y="16" width="2" height="5" rx="0.8" fill="#f43f5e" class="animate-taillight-trail" />
                        <circle cx="86" cy="17.5" r="2" fill="#fef08a" class="animate-headlight-pulse" />
                        <polygon points="88,17.5 110,12 110,24" fill="url(#navBusBeamGrad)" class="animate-headlight-pulse opacity-85" />

                        <g class="animate-wheel-spin" style="transform-origin: 20px 24px;">
                            <circle cx="20" cy="24" r="5" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.5" />
                            <circle cx="20" cy="24" r="2" fill="#38bdf8" />
                            <line x1="20" y1="19" x2="20" y2="29" stroke="#cbd5e1" stroke-width="1.2" />
                            <line x1="15" y1="24" x2="25" y2="24" stroke="#cbd5e1" stroke-width="1.2" />
                        </g>

                        <g class="animate-wheel-spin" style="transform-origin: 73px 24px;">
                            <circle cx="73" cy="24" r="5" fill="#0f172a" stroke="#cbd5e1" stroke-width="1.5" />
                            <circle cx="73" cy="24" r="2" fill="#38bdf8" />
                            <line x1="73" y1="19" x2="73" y2="29" stroke="#cbd5e1" stroke-width="1.2" />
                            <line x1="68" y1="24" x2="78" y2="24" stroke="#cbd5e1" stroke-width="1.2" />
                        </g>
                    </svg>

                    <span class="text-[9px] font-bold text-emerald-300 bg-emerald-950/90 px-1.5 py-0.5 rounded border border-emerald-500/40 -ml-2 -mb-2 shadow-sm whitespace-nowrap hidden sm:inline-block">
                        01 Live
                    </span>
                </div>
            </div>
        </div>

        <!-- Links Navbar: NÚT "BẢN ĐỒ" ĐANG ACTIVE -->
        <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 sm:h-[70px] gap-2">
                
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

                <!-- Desktop Links: 2 TRANG CHÍNH (Trang chủ & Bản đồ) + NÚT TRUY VẤN ĐÃ LƯU -->
                <nav class="hidden lg:flex items-center gap-1 bg-slate-900/90 backdrop-blur-md p-1 rounded-xl shadow-md shrink-0">
                    <a href="../index.php" class="h-9 px-3.5 text-xs xl:text-sm font-medium rounded-lg text-slate-200 hover:bg-slate-800/80 hover:text-white transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-house-door text-brand-400 text-sm"></i>
                        <span>Trang chủ</span>
                    </a>
                    
                    <a href="map.php" class="h-9 px-3.5 text-xs xl:text-sm font-semibold rounded-lg bg-brand-600/50 text-white shadow-sm inline-flex items-center gap-1.5 transition hover:bg-brand-600/60 whitespace-nowrap shrink-0">
                        <i class="bi bi-map-fill text-emerald-400 text-sm"></i>
                        <span>Bản Đồ GTFS</span>
                    </a>

                    <button type="button" data-bs-toggle="modal" data-bs-target="#savedQueriesModal" 
                            class="h-9 px-3.5 text-xs xl:text-sm font-medium rounded-lg text-amber-300 hover:bg-slate-800/80 transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                        <i class="bi bi-bookmark-star-fill text-amber-400 text-sm"></i>
                        <span>Truy Vấn Đã Lưu</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-500/20 text-amber-300 font-bold"><?php echo count($saved_queries); ?></span>
                    </button>
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
            <a href="map.php" class="block px-3 py-2.5 rounded-xl text-base font-semibold text-white bg-brand-600/50 transition">
                <i class="bi bi-map-fill text-emerald-400 mr-2"></i> Bản Đồ GTFS
            </a>
            <?php if ($user && isset($user['role']) && $user['role'] === 'admin'): ?>
                <a href="../admin/gtfs.php" class="block px-3 py-2.5 rounded-xl text-base font-bold text-amber-400 hover:bg-slate-800 transition">
                    <i class="bi bi-speedometer2 mr-2"></i> Quản Trị GTFS
                </a>
            <?php endif; ?>
            <?php if ($user): ?>
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

    <!-- ============================================================= -->
    <!-- MAIN CONTENT CONTAINER (Bố cục rộng mở cho 3 khối song song)   -->
    <!-- ============================================================= -->
    <main class="flex-1 max-w-[1720px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

        <!-- Banner Tiêu Đề & Trạng Thái -->
        <div class="bg-gradient-to-br from-blue-100 via-blue-50 to-white rounded-2xl p-5 sm:p-6 shadow-sm border border-blue-200/60 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="inline-flex items-center gap-2 px-3 py-0.5 rounded-full bg-emerald-50 border border-emerald-200/80 text-emerald-800 text-[11px] font-bold tracking-wide uppercase mb-1">
                    <i class="bi bi-geo-alt-fill text-emerald-600"></i>
                    <span>Interactive GIS &amp; GTFS Unified Hub</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>Bản Đồ Tuyến Đường &amp; Tra Cứu GTFS</span>
                </h1>
                <p class="text-slate-600 text-xs sm:text-sm max-w-2xl leading-relaxed">
                    Trực quan hóa lộ trình trên OpenStreetMap, tra cứu tuyến xe &amp; trạm dừng tối giản, và quản lý các truy vấn đã lưu trên cùng một hàng màn hình.
                </p>
            </div>

            <!-- Nút Truy Vấn Đã Lưu & Badge Phiên Bản Active -->
            <div class="shrink-0 flex items-center gap-2.5 flex-wrap">
                <button type="button" data-bs-toggle="modal" data-bs-target="#savedQueriesModal" 
                        class="px-3.5 py-1.5 rounded-full bg-white hover:bg-amber-50 text-amber-900 font-bold text-xs shadow-xs border border-amber-200/90 transition inline-flex items-center gap-2">
                    <i class="bi bi-bookmark-star-fill text-amber-500 text-sm"></i>
                    <span>Truy Vấn Đã Lưu</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                        <?php echo count($saved_queries); ?>
                    </span>
                </button>

                <?php if ($active_version): ?>
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-emerald-200/80 shadow-xs text-xs font-semibold text-emerald-800">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span>Phiên bản: <strong><?php echo htmlspecialchars($active_version['version_name']); ?></strong></span>
                    </div>
                <?php else: ?>
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-800">
                        <i class="bi bi-exclamation-triangle-fill text-amber-500"></i>
                        <span>Chưa kích hoạt GTFS</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$active_version): ?>
            <div class="p-8 rounded-3xl bg-amber-50 border border-amber-200 text-amber-900 text-center space-y-2">
                <i class="bi bi-exclamation-triangle-fill text-3xl text-amber-500"></i>
                <h3 class="text-base font-bold">Chưa Có Phiên Bản Lịch Trình Nào Được Kích Hoạt</h3>
                <p class="text-xs text-amber-700 max-w-md mx-auto">
                    Quản trị viên cần kích hoạt một bản GTFS trong trang Quản Trị GTFS để hệ thống tải dữ liệu và dựng bản đồ tuyến đường.
                </p>
            </div>
        <?php else: ?>

            <!-- ============================================================= -->
            <!-- 3 KHỐI SONG SONG TRÊN 1 HÀNG: TRA CỨU | LẦN DỪNG | BẢN ĐỒ     -->
            <!-- ============================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 xl:gap-5 items-start">
                
                <!-- KHỐI 1 (BÊN TRÁI): TRA CỨU TUYẾN & TRẠM (Col 4 khi chưa chọn tuyến / Col 3 khi đã chọn) -->
                <div id="tab-pane-browse" class="<?php echo $browse_col_class; ?> bg-white rounded-3xl shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07),0_2px_8px_rgba(15,23,42,0.04)] border border-slate-200/80 p-4 sm:p-5 space-y-3 transition-all duration-300">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-sm font-bold shadow-2xs">
                                <i class="bi bi-compass-fill"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-extrabold text-slate-900">Tra Cứu Tuyến &amp; Trạm</h2>
                                <span class="text-[10px] text-slate-400">Dữ liệu GTFS tối giản hoá</span>
                            </div>
                        </div>
                        <!-- Subtab Switcher: Tuyến / Trạm -->
                        <div class="inline-flex p-0.5 bg-slate-100 rounded-lg text-xs font-bold shrink-0">
                            <a href="map.php?subtab=routes<?php echo $browse_keyword !== '' ? '&browse_q=' . urlencode($browse_keyword) : ''; ?>" 
                               class="px-2.5 py-1 rounded-md transition inline-flex items-center gap-1 text-[11px] <?php echo $browse_subtab === 'routes' ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                                <i class="bi bi-bus-front"></i>
                                <span>Tuyến (<?php echo $total_routes_count ?: count($browse_routes); ?>)</span>
                            </a>
                            <a href="map.php?subtab=stops<?php echo $browse_keyword !== '' ? '&browse_q=' . urlencode($browse_keyword) : ''; ?>" 
                               class="px-2.5 py-1 rounded-md transition inline-flex items-center gap-1 text-[11px] <?php echo $browse_subtab === 'stops' ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                                <i class="bi bi-geo-alt-fill"></i>
                                <span>Trạm (<?php echo $total_stops_count ?: count($browse_stops); ?>)</span>

                            </a>
                        </div>
                    </div>

                    <!-- Form Tìm Kiếm Nhanh -->
                    <form method="GET" action="map.php" class="flex items-center gap-1.5">
                        <input type="hidden" name="subtab" value="<?php echo htmlspecialchars($browse_subtab); ?>">
                        <div class="relative flex-1">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <i class="bi bi-search text-xs"></i>
                            </span>
                            <input type="text" name="browse_q" value="<?php echo htmlspecialchars($browse_keyword); ?>" 
                                   placeholder="<?php echo $browse_subtab === 'stops' ? 'Tìm tên hoặc mã trạm...' : 'Tìm tên hoặc số hiệu tuyến...'; ?>" 
                                   class="w-full pl-8 pr-3 py-1.5 bg-slate-50 focus:bg-white rounded-xl border border-slate-200 focus:border-brand-500 text-slate-800 text-xs font-medium transition">
                        </div>
                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-bold text-xs shadow-xs transition">
                            Tìm
                        </button>
                        <?php if ($browse_keyword !== ''): ?>
                            <a href="map.php?subtab=<?php echo htmlspecialchars($browse_subtab); ?>" class="p-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs transition" title="Xóa tìm kiếm">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        <?php endif; ?>
                    </form>

                    <!-- Hidden inputs/containers to preserve compatibility with existing JS Leaflet listeners -->
                    <div class="hidden">
                        <input type="text" id="search" value="">
                        <select id="type-filter"><option value="">Tất cả</option></select>
                        <div id="route-list"></div>
                    </div>

                    <!-- Danh Sách Tuyến hoặc Trạm (Cuộn mượt mà max-h-[580px]) -->
                    <div class="max-h-[580px] overflow-y-auto pr-1 space-y-2">
                        <?php if ($browse_subtab === 'routes'): ?>
                            <?php if (empty($browse_routes)): ?>
                                <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 text-center text-xs text-slate-500">
                                    Không tìm thấy tuyến xe nào phù hợp.
                                </div>
                            <?php else: ?>
                                <?php foreach ($browse_routes as $r): ?>
                                    <div class="p-3 rounded-2xl bg-slate-50/80 hover:bg-slate-100/80 border border-slate-200/80 transition flex flex-col justify-between gap-2">
                                        <div>
                                            <div class="flex items-center justify-between gap-1.5 mb-1">
                                                <span class="px-2 py-0.5 rounded-lg text-xs font-extrabold text-white" style="background-color: <?php echo !empty($r['route_color']) ? '#' . ltrim($r['route_color'], '#') : '#4f46e5'; ?>;">
                                                    Tuyến <?php echo htmlspecialchars($r['route_short_name'] ?: $r['route_id']); ?>
                                                </span>
                                                <span class="text-[10px] text-slate-400 font-mono">
                                                    ID: <?php echo htmlspecialchars($r['route_id']); ?>
                                                </span>
                                            </div>
                                            <h4 class="text-xs font-bold text-slate-800 line-clamp-2">
                                                <?php echo htmlspecialchars($r['route_long_name'] ?: 'Tuyến ' . $r['route_short_name']); ?>
                                            </h4>
                                            <div class="mt-1 text-[10px] text-slate-500 flex items-center gap-1.5">
                                                <span><i class="bi bi-tag mr-0.5"></i><?php echo route_type_name((int)$r['route_type']); ?></span>
                                                <?php if (!empty($r['agency_name'])): ?>
                                                    <span>&bull;</span>
                                                    <span class="truncate"><?php echo htmlspecialchars($r['agency_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <button type="button" onclick="selectRouteOnMap('<?php echo htmlspecialchars($r['route_id']); ?>')" 
                                                class="w-full py-1.5 px-2.5 rounded-xl bg-brand-50 hover:bg-brand-600 hover:text-white text-brand-700 font-bold text-[11px] transition flex items-center justify-center gap-1 border border-brand-200/60 hover:border-transparent shadow-2xs">
                                            <i class="bi bi-map-fill"></i>
                                            <span>Xem Lộ Trình Trên Bản Đồ</span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (empty($browse_stops)): ?>
                                <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 text-center text-xs text-slate-500">
                                    Không tìm thấy trạm dừng nào phù hợp.
                                </div>
                            <?php else: ?>
                                <?php foreach ($browse_stops as $s): 
                                    $passing_routes = get_routes_at_stop($s['stop_id'], $active_version['id']);
                                ?>
                                    <div class="p-3 rounded-2xl bg-slate-50/80 hover:bg-slate-100/80 border border-slate-200/80 transition flex flex-col justify-between gap-2">
                                        <div>
                                            <div class="flex items-center justify-between gap-1 mb-1">
                                                <span class="text-xs font-bold text-emerald-700 flex items-center gap-1">
                                                    <i class="bi bi-geo-alt-fill text-emerald-500"></i>
                                                    Trạm Dừng
                                                </span>
                                                <span class="text-[10px] font-mono text-slate-400 bg-white border border-slate-200 px-1 py-0.2 rounded">
                                                    <?php echo htmlspecialchars($s['stop_id']); ?>
                                                </span>
                                            </div>
                                            <h4 class="text-xs font-bold text-slate-900 leading-snug">
                                                <?php echo htmlspecialchars($s['stop_name']); ?>
                                            </h4>
                                            <div class="text-[10px] text-slate-500 mt-0.5">
                                                Tọa độ: <?php echo round((float)$s['stop_lat'], 4); ?>, <?php echo round((float)$s['stop_lon'], 4); ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1.5 pt-1">
                                            <button type="button" onclick="viewStopOnMap(<?php echo (float)$s['stop_lat']; ?>, <?php echo (float)$s['stop_lon']; ?>, '<?php echo addslashes($s['stop_name']); ?>', '<?php echo addslashes($s['stop_id']); ?>')" 
                                                    class="flex-1 py-1 px-2 rounded-lg bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold text-[11px] transition flex items-center justify-center gap-1 border border-emerald-200/60">
                                                <i class="bi bi-geo-alt"></i>
                                                <span>Định vị</span>
                                            </button>
                                            <a href="next_trip.php?stop_id=<?php echo urlencode($s['stop_id']); ?>" 
                                               class="py-1 px-2 rounded-lg bg-white hover:bg-slate-200 text-slate-700 font-semibold text-[11px] border border-slate-200 transition flex items-center gap-1" title="Xem chuyến kế tiếp">
                                                <i class="bi bi-clock-history"></i>
                                                <span>Chuyến</span>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Phân trang cho danh sách Trạm dừng (subtab=stops) -->
                                <?php if ($stops_total_pages > 1): 
                                    $base_query = ['subtab' => 'stops'];
                                    if ($browse_keyword !== '') $base_query['browse_q'] = $browse_keyword;
                                    if (!empty($initial_route)) $base_query['route_id'] = $initial_route;
                                ?>
                                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                                        <span class="text-[11px] text-slate-500">
                                            Trang <strong><?php echo $stops_page; ?></strong>/<?php echo $stops_total_pages; ?> (<?php echo $stops_total; ?> trạm)
                                        </span>
                                        <div class="inline-flex items-center gap-1">
                                            <?php if ($stops_page > 1): ?>
                                                <a href="map.php?<?php echo http_build_query(array_merge($base_query, ['page' => $stops_page - 1])); ?>" 
                                                   class="p-1 px-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Trang trước">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php 
                                            $start_p = max(1, $stops_page - 1);
                                            $end_p = min($stops_total_pages, $stops_page + 1);
                                            for ($p = $start_p; $p <= $end_p; $p++): 
                                            ?>
                                                <a href="map.php?<?php echo http_build_query(array_merge($base_query, ['page' => $p])); ?>" 
                                                   class="px-2.5 py-0.5 rounded-lg text-xs font-bold transition <?php echo $p === $stops_page ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'; ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            <?php endfor; ?>

                                            <?php if ($stops_page < $stops_total_pages): ?>
                                                <a href="map.php?<?php echo http_build_query(array_merge($base_query, ['page' => $stops_page + 1])); ?>" 
                                                   class="p-1 px-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Trang sau">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KHỐI 2 (Ở GIỮA): DANH SÁCH LẦN DỪNG CỦA TUYẾN (Ẩn ban đầu, chỉ hiện khi chọn tuyến) -->
                <div id="stop-panel-wrap" <?php echo $wrap_initial_style; ?> class="lg:col-span-4 xl:col-span-3 bg-white rounded-3xl shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07),0_2px_8px_rgba(15,23,42,0.04)] border border-slate-200/80 p-4 sm:p-5 space-y-3 transition-all duration-300">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold shadow-2xs">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-extrabold text-slate-900 truncate" id="stop-panel-title">
                                    Danh Sách Lần Dừng
                                </h2>
                                <span class="text-[10px] text-slate-400">Thứ tự các trạm đón/trả</span>
                            </div>
                        </div>
                        <button type="button" id="btn-stop-panel-close" onclick="clearSelection()" style="display:none;" 
                                class="p-1 px-2 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 text-[11px] font-semibold transition border border-slate-200/80 flex items-center gap-1">
                            <i class="bi bi-x-circle text-rose-500"></i>
                            <span>Bỏ chọn</span>
                        </button>
                    </div>

                    <!-- Placeholder khi chưa chọn tuyến -->
                    <div id="stop-panel-empty" class="p-6 rounded-2xl bg-slate-50/70 border border-slate-200/70 text-center space-y-2">
                        <div class="w-10 h-10 mx-auto rounded-xl bg-white border border-slate-200/80 flex items-center justify-center text-slate-400 shadow-2xs">
                            <i class="bi bi-cursor-fill text-lg text-brand-500"></i>
                        </div>
                        <h3 class="text-xs font-bold text-slate-800">Chưa Chọn Tuyến Xe</h3>
                        <p class="text-[11px] text-slate-500 leading-relaxed max-w-xs mx-auto">
                            Nhấn <strong class="text-brand-600">Xem Lộ Trình</strong> ở danh sách bên trái hoặc click trực tiếp vào tuyến trên bản đồ để xem chi tiết các trạm dừng.
                        </p>
                    </div>

                    <!-- Danh sách trạm dừng khi đã chọn tuyến -->
                    <div id="stop-panel" style="display:none;" class="space-y-1.5 pr-1 max-h-[580px] overflow-y-auto"></div>
                </div>

                <!-- KHỐI 3 (BÊN PHẢI): BẢN ĐỒ TƯƠNG TÁC (Col 8 khi chưa chọn tuyến / Col 5-6 khi đã chọn) -->
                <div id="tab-pane-map" class="<?php echo $map_col_class; ?> bg-white rounded-3xl shadow-[0_12px_36px_-6px_rgba(15,23,42,0.07),0_2px_8px_rgba(15,23,42,0.04)] border border-slate-200/80 p-4 sm:p-5 space-y-3 transition-all duration-300">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center text-sm font-bold shadow-2xs">
                                <i class="bi bi-map-fill text-emerald-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-extrabold text-slate-900">Bản Đồ Tuyến Đường</h2>
                                <span class="text-[10px] text-slate-400">Hình học lộ trình &amp; trạm dừng</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="fitAll()" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition inline-flex items-center gap-1" title="Xem toàn cảnh">
                                <i class="bi bi-arrows-fullscreen"></i>
                                <span class="hidden xl:inline">Toàn cảnh</span>
                            </button>
                            <span class="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full" id="count-label">
                                Đang tải...
                            </span>
                        </div>
                    </div>

                    <!-- Toggle Chiều đi / Chiều về -->
                    <div class="flex items-center gap-1.5 p-1 bg-slate-100/80 rounded-xl text-xs font-semibold text-slate-700">
                        <label class="flex-1 flex items-center justify-center gap-1.5 py-1 px-1.5 rounded-lg cursor-pointer transition select-none has-[:checked]:bg-white has-[:checked]:text-brand-700 has-[:checked]:shadow-2xs text-[11px]">
                            <input type="checkbox" id="dir0" checked class="rounded text-brand-600 focus:ring-brand-500 border-slate-300">
                            <span>Chiều đi (nét liền)</span>
                        </label>
                        <label class="flex-1 flex items-center justify-center gap-1.5 py-1 px-1.5 rounded-lg cursor-pointer transition select-none has-[:checked]:bg-white has-[:checked]:text-purple-700 has-[:checked]:shadow-2xs text-[11px]">
                            <input type="checkbox" id="dir1" checked class="rounded text-purple-600 focus:ring-purple-500 border-slate-300">
                            <span>Chiều về (nét đứt)</span>
                        </label>
                    </div>

                    <button type="button" id="btn-clear" style="display:none;" 
                            class="w-full py-1.5 px-3 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs transition border border-rose-200 flex items-center justify-center gap-1.5">
                        <i class="bi bi-x-circle-fill"></i>
                        <span>Bỏ chọn tuyến đang xem</span>
                    </button>

                    <!-- Khung Bản Đồ Leaflet -->
                    <div id="map" class="rounded-2xl overflow-hidden border border-slate-100"></div>

                    <!-- Chú thích bản đồ -->
                    <div class="pt-1 flex flex-wrap items-center justify-between text-[11px] text-slate-500 gap-1.5">
                        <div class="flex items-center gap-2.5 flex-wrap font-medium text-[10px]">
                            <span class="flex items-center gap-1"><span class="w-3 h-1 bg-brand-600 rounded"></span>Đi</span>
                            <span class="flex items-center gap-1"><span class="w-3 h-1 border-t-2 border-dashed border-purple-600"></span>Về</span>
                            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-600"></span>Đầu</span>
                            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-rose-600"></span>Cuối</span>
                        </div>
                        <span class="text-[10px] text-slate-400">Click tuyến/trạm để xem</span>
                    </div>
                </div>

            </div>

            <!-- ============================================================= -->
            <!-- MODAL TRUY VẤN ĐÃ LƯU                                         -->
            <!-- ============================================================= -->
            <div class="modal fade" id="savedQueriesModal" tabindex="-1" aria-labelledby="savedQueriesModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                    <div class="modal-content rounded-3xl border-0 shadow-2xl overflow-hidden">
                        <div class="modal-header bg-slate-900 text-white px-5 py-4 border-0">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-sm font-bold">
                                    <i class="bi bi-bookmark-star-fill"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title text-base font-extrabold text-white" id="savedQueriesModalLabel">Truy Vấn Mẫu Đã Lưu</h5>
                                    <span class="text-xs text-slate-400">Quản lý và chạy lại các truy vấn lịch trình quen thuộc</span>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body p-5 bg-slate-50 space-y-4">
                            <?php if (!$user): ?>
                                <div class="p-8 rounded-2xl bg-white border border-slate-200 text-center space-y-3 shadow-xs">
                                    <i class="bi bi-shield-lock text-3xl text-amber-500"></i>
                                    <h3 class="text-sm font-bold text-slate-800">Cần Đăng Nhập</h3>
                                    <p class="text-xs text-slate-500">Đăng nhập tài khoản để lưu lại các trạm và lịch trình xe quen thuộc.</p>
                                    <a href="../auth/login.php" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold transition">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                        <span>Đăng nhập ngay</span>
                                    </a>
                                </div>
                            <?php else: ?>

                                <!-- Kết quả chạy truy vấn (nếu có) -->
                                <?php if ($run_results): ?>
                                    <div class="p-4 bg-amber-50 rounded-2xl border border-amber-300 space-y-2.5 shadow-xs">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-amber-950 flex items-center gap-1.5">
                                                <i class="bi bi-play-circle-fill text-amber-600 text-sm"></i>
                                                <span>Kết quả: <strong><?php echo htmlspecialchars($run_results['query_name']); ?></strong></span>
                                            </span>
                                            <span class="text-[11px] font-mono text-amber-800 bg-amber-100 px-2 py-0.5 rounded-md font-semibold">
                                                <?php echo htmlspecialchars($run_results['date']); ?> <?php echo htmlspecialchars($run_results['time']); ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-slate-700">
                                            Trạm: <strong><?php echo htmlspecialchars($run_results['stop']['stop_name'] ?? 'N/A'); ?></strong> (Mã: <code><?php echo htmlspecialchars($run_results['stop']['stop_id'] ?? 'N/A'); ?></code>)
                                        </div>
                                        <?php if (empty($run_results['trips'])): ?>
                                            <p class="text-xs text-slate-500 italic">Không tìm thấy chuyến nào sắp khởi hành sau mốc thời gian này.</p>
                                        <?php else: ?>
                                            <div class="space-y-1.5 max-h-[220px] overflow-y-auto pr-1">
                                                <?php foreach ($run_results['trips'] as $t): ?>
                                                    <div class="p-2.5 rounded-xl bg-white border border-slate-200 text-xs flex items-center justify-between shadow-2xs">
                                                        <div class="flex items-center gap-2 truncate">
                                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold text-white bg-brand-600">
                                                                <?php echo htmlspecialchars($t['route_short_name'] ?: $t['route_id']); ?>
                                                            </span>
                                                            <span class="font-bold text-slate-800 truncate"><?php echo htmlspecialchars($t['trip_headsign'] ?: $t['route_long_name']); ?></span>
                                                        </div>
                                                        <span class="font-mono text-xs text-brand-600 font-bold shrink-0"><?php echo htmlspecialchars($t['departure_time']); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Danh sách truy vấn đã lưu -->
                                <?php if (empty($saved_queries)): ?>
                                    <div class="p-8 rounded-2xl bg-white border border-slate-200 text-center text-xs text-slate-500 space-y-2 shadow-xs">
                                        <i class="bi bi-bookmark-dash text-3xl text-slate-300"></i>
                                        <p class="font-medium">Bạn chưa lưu truy vấn nào.</p>
                                        <a href="next_trip.php" class="inline-block text-xs text-brand-600 font-bold hover:underline">
                                            Sang trang Chuyến Kế Tiếp để tìm và lưu truy vấn &rarr;
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-2.5 max-h-[360px] overflow-y-auto pr-1">
                                        <?php foreach ($saved_queries as $sq): 
                                            $params = json_decode($sq['parameters'], true) ?: [];
                                        ?>
                                            <div class="p-3.5 rounded-2xl bg-white hover:bg-slate-50 border border-slate-200 transition flex items-center justify-between gap-3 shadow-2xs">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-100 text-amber-800">
                                                            <?php echo htmlspecialchars($sq['query_type']); ?>
                                                        </span>
                                                        <span class="text-[10px] text-slate-400">
                                                            <?php echo date('d/m/Y H:i', strtotime($sq['created_at'])); ?>
                                                        </span>
                                                    </div>
                                                    <h4 class="text-xs font-bold text-slate-900 truncate">
                                                        <?php echo htmlspecialchars($sq['query_name']); ?>
                                                    </h4>
                                                    <?php if (!empty($params['stop_id'])): ?>
                                                        <div class="text-[11px] text-slate-500 mt-0.5">Trạm: <span class="font-mono font-semibold"><?php echo htmlspecialchars($params['stop_id']); ?></span></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex items-center gap-2 shrink-0">
                                                    <a href="map.php?run=<?php echo $sq['id']; ?>" class="py-1.5 px-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs transition flex items-center gap-1 shadow-2xs">
                                                        <i class="bi bi-play-fill text-sm"></i>
                                                        <span>Chạy lại</span>
                                                    </a>
                                                    <form method="POST" action="map.php" onsubmit="return confirm('Bạn có chắc chắn muốn xóa truy vấn này không?');" class="m-0">
                                                        <input type="hidden" name="action" value="delete_query">
                                                        <input type="hidden" name="query_id" value="<?php echo $sq['id']; ?>">
                                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition" title="Xóa truy vấn">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>

                        <div class="modal-footer bg-white px-5 py-3 border-t border-slate-100 flex items-center justify-between">
                            <a href="next_trip.php" class="text-xs font-bold text-brand-600 hover:underline inline-flex items-center gap-1">
                                <i class="bi bi-plus-circle"></i>
                                <span>Tạo truy vấn mới</span>
                            </a>
                            <button type="button" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition" data-bs-dismiss="modal">
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </main>

<?php if ($active_version): ?>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    const INITIAL_ROUTE = <?php echo json_encode($initial_route, JSON_UNESCAPED_UNICODE); ?>;
    const FALLBACK = ['#4f46e5', '#0284c7', '#059669', '#d97706', '#7c3aed', '#0d9488', '#e11d48', '#2563eb', '#9333ea', '#ea580c'];

    const map = L.map('map', {
        zoomControl: true
    }).setView([35.68, 139.5], 11);

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
        if (!st || st.length === 0) return '#';
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
                    opacity: dim ? 0.15 : (isSel ? 0.95 : 0.65),
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
                    fillColor: first ? '#10b981' : (last ? '#ef4444' : col)
                });
                m.bindPopup(
                    '<div class="text-xs space-y-1.5">' +
                    '<div class="font-bold text-slate-900 text-sm flex items-center gap-1.5"><i class="bi bi-geo-alt-fill text-rose-500"></i>' + esc(s.name) + '</div>' +
                    '<div class="text-slate-500 font-mono text-[11px]">Mã trạm: <strong>' + esc(s.id) + '</strong></div>' +
                    '<div class="pt-1 flex flex-col gap-1 border-t border-slate-100">' +
                    '<a href="next_trip.php?stop_id=' + encodeURIComponent(s.id) + '" class="text-brand-600 font-bold hover:underline inline-flex items-center gap-1"><i class="bi bi-clock-history"></i>Xem chuyến kế tiếp tại trạm &rarr;</a>' +
                    '<a href="' + gmapsStop(s) + '" target="_blank" rel="noopener" class="text-slate-500 hover:text-slate-700 inline-flex items-center gap-1"><i class="bi bi-google"></i>Mở trong Google Maps</a>' +
                    '</div>' +
                    '</div>'
                );
                m.addTo(stopLayer);
            });
        });
    }

    function renderStopPanel(r) {
        const wrap = document.getElementById('stop-panel-wrap');
        const panel = document.getElementById('stop-panel');
        const empty = document.getElementById('stop-panel-empty');
        const closeBtn = document.getElementById('btn-stop-panel-close');
        if (!r) {
            if (empty) empty.style.display = '';
            if (panel) { panel.innerHTML = ''; panel.style.display = 'none'; }
            if (closeBtn) closeBtn.style.display = 'none';
            document.getElementById('stop-panel-title').textContent = 'Danh Sách Lần Dừng';
            return;
        }
        if (empty) empty.style.display = 'none';
        if (panel) panel.style.display = '';
        if (closeBtn) closeBtn.style.display = '';
        document.getElementById('stop-panel-title').textContent = 'Lộ trình tuyến ' + (r.short || r.route_id) + ' (' + r.type_name + ')';
        panel.innerHTML = '';
        r.lines.forEach(line => {
            const h = document.createElement('div');
            h.className = 'p-2.5 bg-slate-100/80 rounded-xl mb-1.5 flex items-center justify-between gap-2 text-xs font-bold text-slate-800';
            const title = (line.direction === 1 ? 'Chiều về' : (line.direction === 0 ? 'Chiều đi' : 'Lộ trình'))
                + (line.headsign ? ' → ' + line.headsign : '');
            h.innerHTML = '<span class="truncate">' + esc(title) + '</span>' +
                '<a class="px-2 py-0.5 rounded-lg bg-white border border-slate-200 text-brand-600 hover:bg-brand-50 text-[11px] font-semibold text-nowrap flex items-center gap-1 shadow-2xs" target="_blank" rel="noopener" href="' + gmapsRoute(line) + '">' +
                '<i class="bi bi-google"></i>Maps</a>';
            panel.appendChild(h);
            line.stops.forEach((s, idx) => {
                const row = document.createElement('div');
                row.className = 'stop-row flex items-center justify-between p-2 rounded-xl hover:bg-slate-100/70 text-xs transition-colors cursor-pointer border-b border-slate-100 last:border-b-0';
                row.innerHTML = '<div class="flex items-center gap-2 truncate">' +
                    '<span class="w-5 h-5 rounded-full bg-slate-200/80 text-slate-600 text-[10px] font-bold flex items-center justify-center shrink-0">' + (idx + 1) + '</span>' +
                    '<span class="font-medium text-slate-800 truncate">' + esc(s.name) + '</span>' +
                    '</div>' +
                    '<a class="text-slate-400 hover:text-brand-600 ms-2 p-1" title="Mở trong Google Maps" target="_blank" rel="noopener" href="' + gmapsStop(s) + '"><i class="bi bi-box-arrow-up-right"></i></a>';
                row.querySelector('a').addEventListener('click', e => e.stopPropagation());
                row.addEventListener('click', () => {
                    map.flyTo([s.lat, s.lon], Math.max(map.getZoom(), 16));
                    L.popup().setLatLng([s.lat, s.lon]).setContent(
                        '<div class="text-xs space-y-1">' +
                        '<strong class="text-sm text-slate-900">' + esc(s.name) + '</strong><br>' +
                        '<span class="text-slate-500 font-mono">Mã: ' + esc(s.id) + '</span><br>' +
                        '<a href="next_trip.php?stop_id=' + encodeURIComponent(s.id) + '" class="text-brand-600 font-bold hover:underline pt-1 inline-block">Xem chuyến kế tiếp &rarr;</a>' +
                        '</div>'
                    ).openOn(map);
                });
                panel.appendChild(row);
            });
        });
    }

    function setThreeColumnLayout(active) {
        const browse = document.getElementById('tab-pane-browse');
        const wrap = document.getElementById('stop-panel-wrap');
        const mapPane = document.getElementById('tab-pane-map');
        if (!browse || !wrap || !mapPane) return;

        if (active) {
            wrap.style.display = '';
            browse.classList.remove('lg:col-span-4', 'xl:col-span-4');
            browse.classList.add('lg:col-span-3', 'xl:col-span-3');

            mapPane.classList.remove('lg:col-span-8', 'xl:col-span-8');
            mapPane.classList.add('lg:col-span-5', 'xl:col-span-6');
        } else {
            wrap.style.display = 'none';
            browse.classList.remove('lg:col-span-3', 'xl:col-span-3');
            browse.classList.add('lg:col-span-4', 'xl:col-span-4');

            mapPane.classList.remove('lg:col-span-5', 'xl:col-span-6');
            mapPane.classList.add('lg:col-span-8', 'xl:col-span-8');
        }
        if (typeof map !== 'undefined' && map) {
            setTimeout(() => { map.invalidateSize(); }, 250);
        }
    }

    function selectRoute(id, scrollList) {
        selectedId = id;
        const r = routes.find(x => x.route_id === id) || null;
        Object.keys(listItems).forEach(k => listItems[k].classList.toggle('active', k === id));
        document.getElementById('btn-clear').style.display = r ? '' : 'none';
        setThreeColumnLayout(!!r);
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
        setThreeColumnLayout(false);
        stopLayer.clearLayers();
        renderStopPanel(null);
        drawLines();
        fitAll();
    }

    window.fitAll = function() {
        const pts = [];
        routes.forEach(r => r.lines.forEach(l => l.stops.forEach(s => pts.push([s.lat, s.lon]))));
        if (pts.length) map.fitBounds(pts, {padding: [30, 30]});
    };

    function buildList() {
        const list = document.getElementById('route-list');
        list.innerHTML = '';
        routes.forEach((r, i) => {
            const el = document.createElement('div');
            el.className = 'route-item p-2.5 rounded-2xl bg-slate-50/70 hover:bg-slate-100/80 border border-slate-200/80 transition-all flex items-start gap-2.5 cursor-pointer text-xs mb-1.5';
            el.innerHTML = '<div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs text-white shrink-0 shadow-xs" style="background:' + colorOf(r, i) + '">' +
                esc(r.short || r.route_id) + '</div>' +
                '<div class="flex-1 min-w-0">' +
                '<div class="flex items-center justify-between gap-1">' +
                '<span class="font-bold text-slate-900 truncate">Tuyến ' + esc(r.short || r.route_id) + '</span>' +
                '<span class="text-[10px] px-1.5 py-0.2 rounded-md bg-white border border-slate-200 font-semibold text-slate-500 shrink-0">' + esc(r.type_name) + '</span>' +
                '</div>' +
                (r.long ? '<div class="text-[11px] text-slate-500 truncate mt-0.5">' + esc(r.long) + '</div>' : '') +
                '</div>';
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

    window._leafletMap = map;
    window._selectRouteInternal = selectRoute;
    window._routesData = () => routes;

    fetch('map.php?data=1')
        .then(res => res.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            routes = data.routes;
            if (routes.length === 0) {
                document.getElementById('count-label').textContent = 'Không có dữ liệu tuyến';
                return;
            }
            buildTypeFilter();
            buildList();
            drawLines();
            window.fitAll();
            if (data.without_geometry > 0) {
                document.getElementById('count-label').title = data.without_geometry + ' tuyến không có đủ toạ độ trạm nên không hiển thị.';
            }
            if (INITIAL_ROUTE && routes.some(r => r.route_id === INITIAL_ROUTE)) {
                selectRoute(INITIAL_ROUTE, true);
            }
        })
        .catch(err => {
            document.getElementById('count-label').textContent = 'Lỗi tải bản đồ (' + err.message + ')';
        });
})();

// Tương tác trực tiếp giữa 3 khối song song trên cùng 1 hàng
window.selectRouteOnMap = function(routeId) {
    if (typeof window._selectRouteInternal === 'function') {
        window._selectRouteInternal(routeId, true);
    }
};

window.viewStopOnMap = function(lat, lon, name, id) {
    if (window._leafletMap) {
        window._leafletMap.invalidateSize();
        window._leafletMap.flyTo([lat, lon], 16);
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        L.popup().setLatLng([lat, lon]).setContent(
            '<div class="text-xs space-y-1.5">' +
            '<div class="font-bold text-slate-900 text-sm flex items-center gap-1.5"><i class="bi bi-geo-alt-fill text-rose-500"></i>' + esc(name) + '</div>' +
            '<div class="text-slate-500 font-mono text-[11px]">Mã trạm: <strong>' + esc(id) + '</strong></div>' +
            '<div class="pt-1 flex flex-col gap-1 border-t border-slate-100">' +
            '<a href="next_trip.php?stop_id=' + encodeURIComponent(id) + '" class="text-brand-600 font-bold hover:underline inline-flex items-center gap-1"><i class="bi bi-clock-history"></i>Xem chuyến kế tiếp tại trạm &rarr;</a>' +
            '</div>' +
            '</div>'
        ).openOn(window._leafletMap);
    }
};

// Mobile drawer menu toggle
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

<?php if ($run_results): ?>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('savedQueriesModal');
    if (el && typeof bootstrap !== 'undefined') {
        new bootstrap.Modal(el).show();
    }
});
<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
