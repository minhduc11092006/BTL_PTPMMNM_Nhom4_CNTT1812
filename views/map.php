<?php
// map.php - Bản đồ các tuyến đường: vẽ lộ trình tuyến và các trạm dừng lên bản đồ (Leaflet + OpenStreetMap)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gtfs_functions.php';

$user = current_user();
$active_version = get_active_feed_version();

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
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="../index.php">
                <i class="bi bi-bus-front-fill text-warning"></i> Tra Cứu Giao Thông Công Cộng
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="../index.php" class="btn btn-sm btn-outline-light"><i class="bi bi-house-door me-1"></i> Trang chủ</a>
                <a href="gtfs_browse.php" class="btn btn-sm btn-outline-light"><i class="bi bi-compass me-1"></i> Tuyến & Trạm</a>
                <a href="map.php" class="btn btn-sm btn-light fw-bold text-primary"><i class="bi bi-map-fill me-1"></i> Bản Đồ</a>
                <a href="next_trip.php" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-clock-history me-1"></i> Chuyến Kế Tiếp</a>
                <?php if ($user): ?>
                    <a href="saved_queries.php" class="btn btn-sm btn-outline-light"><i class="bi bi-bookmark-star-fill me-1"></i> Truy Vấn Đã Lưu</a>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a href="../admin/gtfs.php" class="btn btn-sm btn-light fw-bold text-dark"><i class="bi bi-speedometer2"></i> Quản Trị</a>
                    <?php endif; ?>
                    <a href="../auth/logout.php" class="btn btn-sm btn-outline-light">Đăng xuất</a>
                <?php else: ?>
                    <a href="../auth/login.php" class="btn btn-sm btn-light fw-semibold">Đăng nhập</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

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
