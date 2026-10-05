<x-filament-panels::page>

<header class="fs-page-header">
    <div>
        <p class="fs-page-header__eyebrow">Theo dõi thời gian thực</p>
        <h1 class="fs-page-header__title">Bản đồ tài xế</h1>
        <p class="fs-page-header__description">Vị trí GPS, trạng thái tài xế và đơn đang chờ trong khu vực · chọn một đơn để thấy tài xế rảnh gần nhất.</p>
    </div>
</header>

<style>
    .dm-stats { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
    .dm-stat { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #e5e7eb; border-radius:13px; background:#fff; text-align:left; cursor:pointer; transition:.15s; }
    .dm-stat:hover { border-color:#fed7aa; }
    .dm-stat.active { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
    .dm-stat i { width:10px; height:10px; flex:none; border-radius:50%; }
    .dm-stat div { min-width:0; }
    .dm-stat strong { display:block; color:#0f172a; font-size:var(--fs-lg); font-weight:650; line-height:1; }
    .dm-stat span { color:#64748b; font-size:var(--fs-xs); }
    .dm-layout { display:grid; grid-template-columns:minmax(300px,31%) minmax(0,1fr); min-height:680px; overflow:hidden; border:1px solid #e5e7eb; border-radius:16px; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.05); }
    .dm-sidebar { display:flex; min-width:0; min-height:0; flex-direction:column; border-right:1px solid #e5e7eb; background:#f8fafc; }
    .dm-tabs { display:grid; grid-template-columns:1fr 1fr; border-bottom:1px solid #e5e7eb; }
    .dm-tab { padding:10px 8px; border-bottom:2px solid transparent; color:#64748b; font-size:var(--fs-sm); font-weight:650; cursor:pointer; }
    .dm-tab.active { border-bottom-color:#f97316; color:#c2410c; }
    .dm-tab b { margin-left:4px; padding:1px 7px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:var(--fs-xs); }
    .dm-tools { display:grid; gap:8px; padding:12px; border-bottom:1px solid #e5e7eb; }
    .dm-search { width:100%; padding:9px 11px; border:1px solid #dbe2ea; border-radius:10px; background:#fff; color:#0f172a; font-size:var(--fs-sm); outline:none; }
    .dm-search:focus { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
    .dm-filters { display:flex; flex-wrap:wrap; gap:5px; }
    .dm-filter { flex:none; padding:5px 9px; border:1px solid #e2e8f0; border-radius:999px; background:#fff; color:#64748b; font-size:var(--fs-xs); cursor:pointer; }
    .dm-filter.active { border-color:#fed7aa; background:#fff7ed; color:#c2410c; }
    .dm-list { flex:1; min-height:0; overflow-y:auto; padding:6px; }
    .dm-empty { padding:30px 12px; color:#94a3b8; font-size:var(--fs-sm); text-align:center; }
    .dm-driver { display:grid; grid-template-columns:40px minmax(0,1fr); gap:10px; width:100%; padding:9px; border:1px solid transparent; border-radius:11px; text-align:left; transition:.15s; }
    .dm-driver:hover,.dm-driver.active { border-color:#fed7aa; background:#fff; }
    .dm-avatar { position:relative; width:40px; height:40px; overflow:hidden; border:2px solid var(--dm-color); border-radius:50%; background:#e2e8f0; }
    .dm-avatar img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
    .dm-avatar > span { display:grid; width:100%; height:100%; place-items:center; color:#64748b; font-weight:700; }
    .dm-driver__top { display:flex; min-width:0; align-items:center; justify-content:space-between; gap:6px; }
    .dm-driver__name { overflow:hidden; color:#0f172a; font-size:var(--fs-sm); font-weight:600; text-overflow:ellipsis; white-space:nowrap; }
    .dm-driver__ago { flex:none; color:#94a3b8; font-size:var(--fs-xs); }
    .dm-driver__meta { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-top:4px; color:#64748b; font-size:var(--fs-xs); }
    .dm-status { color:var(--dm-color); }
    .dm-order { display:grid; gap:6px; width:100%; margin-bottom:6px; padding:10px; border:1px solid #e2e8f0; border-left:3px solid #ef4444; border-radius:11px; background:#fff; text-align:left; cursor:pointer; transition:.15s; }
    .dm-order:hover,.dm-order.active { border-color:#fed7aa; border-left-color:#ef4444; }
    .dm-order.active { box-shadow:0 0 0 2px rgba(249,115,22,.15); }
    .dm-order__top { display:flex; align-items:center; gap:6px; }
    .dm-order__top b { color:#ea580c; font-size:var(--fs-sm); }
    .dm-order__top em { margin-left:auto; color:#dc2626; font-size:var(--fs-xs); font-style:normal; font-weight:700; }
    .dm-tag { padding:1px 7px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:var(--fs-xs); font-weight:700; }
    .dm-tag.soft { background:#fef3c7; color:#b45309; }
    .dm-order__addr { color:#475569; font-size:var(--fs-xs); line-height:1.35; }
    .dm-order__note { overflow:hidden; padding:1px 7px; border-left:2px solid #14b8a6; border-radius:4px; background:rgba(20,184,166,.1); color:#0f766e; font-size:var(--fs-xs); text-overflow:ellipsis; white-space:nowrap; }
    .dm-order__acts { display:flex; flex-wrap:wrap; gap:6px; }
    .dm-btn { padding:4px 10px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; color:#334155; font-size:var(--fs-xs); font-weight:600; cursor:pointer; text-decoration:none; }
    .dm-btn.primary { border-color:#0ea5e9; background:#0ea5e9; color:#fff; }
    .dm-near { display:grid; gap:4px; margin-top:2px; padding-top:8px; border-top:1px dashed #cbd5e1; }
    .dm-near__title { color:#64748b; font-size:var(--fs-xs); font-weight:700; }
    .dm-near__row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; align-items:center; gap:8px; padding:4px 0; font-size:var(--fs-xs); }
    .dm-near__row strong { overflow:hidden; color:#0f172a; text-overflow:ellipsis; white-space:nowrap; }
    .dm-near__row span { color:#64748b; white-space:nowrap; }
    .dm-near__note { color:#94a3b8; font-size:var(--fs-xs); }
    #driver-map-wrapper { position:relative; width:100%; min-height:680px; }
    #driver-map { position: absolute; inset: 0; width: 100%; height: 100%; }
    #driver-map-counter { position:absolute; top:12px; left:12px; z-index:5; background:#fff; border-radius:999px; padding:6px 14px; font-size:12.5px; font-weight:600; color:#374151; box-shadow:0 1px 4px rgba(0,0,0,0.18); }
    .dm-map-actions { position:absolute; top:12px; right:56px; z-index:5; display:flex; gap:6px; }
    .dm-map-action { padding:7px 10px; border:none; border-radius:9px; background:#fff; color:#475569; box-shadow:0 1px 5px rgba(0,0,0,.18); font-size:var(--fs-xs); cursor:pointer; }
    .dark .dm-stat,.dark .dm-layout,.dark .dm-driver:hover,.dark .dm-driver.active,.dark .dm-search,.dark .dm-filter,.dark .dm-order,.dark .dm-btn { border-color:#293142; background:#171b25; }
    .dark .dm-order { border-left-color:#ef4444; }
    .dark .dm-sidebar { border-color:#293142; background:#121620; }
    .dark .dm-tools,.dark .dm-tabs,.dark .dm-near { border-color:#293142; }
    .dark .dm-stat strong,.dark .dm-driver__name,.dark .dm-search,.dark .dm-near__row strong,.dark .dm-btn { color:#f8fafc; }
    .dark .dm-btn.primary { border-color:#0ea5e9; background:#0ea5e9; color:#fff; }
    .dark .dm-order__addr { color:#cbd5e1; }
    .dark .dm-tab.active,.dark .dm-filter.active { color:#fdba74; }
    .dark .dm-order__note { color:#5eead4; }
    .dark #driver-map-counter,.dark .dm-map-action { background:#1f2937; color:#e5e7eb; }
    @media(max-width:1100px){.dm-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:900px){.dm-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.dm-layout{display:flex; min-height:auto; flex-direction:column}.dm-sidebar{max-height:340px; border-right:none; border-bottom:1px solid #e5e7eb}.dm-list{min-height:220px}#driver-map-wrapper{min-height:55vh}}
</style>

{{-- Làm mới trạng thái đang đi đơn / đơn chờ mỗi 15s — vị trí GPS đã realtime qua Firebase riêng. --}}
<div wire:poll.15s.visible="loadDriversMeta" style="display:none"></div>

<div wire:ignore>
    <div class="dm-stats">
        <button type="button" class="dm-stat" data-stat="all"><i style="background:#22c55e"></i><div><strong id="dm-stat-online">0</strong><span>Đang online</span></div></button>
        <button type="button" class="dm-stat" data-stat="free"><i style="background:#16a34a"></i><div><strong id="dm-stat-free">0</strong><span>Đang rảnh</span></div></button>
        <button type="button" class="dm-stat" data-stat="busy"><i style="background:#3b82f6"></i><div><strong id="dm-stat-busy">0</strong><span>Đang giao đơn</span></div></button>
        <button type="button" class="dm-stat" data-stat="stale" title="Có GPS nhưng quá 3 phút chưa cập nhật"><i style="background:#f59e0b"></i><div><strong id="dm-stat-stale">0</strong><span>GPS chậm</span></div></button>
        <button type="button" class="dm-stat" data-stat="lost" title="Bật online trong hệ thống nhưng không có vị trí trên Firebase"><i style="background:#ef4444"></i><div><strong id="dm-stat-lost">0</strong><span>Mất kết nối</span></div></button>
        <button type="button" class="dm-stat" data-stat="orders"><i style="background:#f97316"></i><div><strong id="dm-stat-orders">{{ count($ordersMeta) }}</strong><span>Đơn đang chờ</span></div></button>
    </div>
    <div class="dm-layout">
        <aside class="dm-sidebar">
            <div class="dm-tabs">
                <button type="button" class="dm-tab active" data-tab="drivers">Tài xế</button>
                <button type="button" class="dm-tab" data-tab="orders">Đơn chờ <b id="dm-tab-orders">{{ count($ordersMeta) }}</b></button>
            </div>
            <div id="dm-tools" class="dm-tools">
                <input id="dm-search" class="dm-search" type="search" placeholder="Tìm tên hoặc số điện thoại...">
                <div class="dm-filters">
                    <button class="dm-filter active" data-filter="all">Tất cả</button>
                    <button class="dm-filter" data-filter="free">Đang rảnh</button>
                    <button class="dm-filter" data-filter="busy">Đang giao</button>
                    <button class="dm-filter" data-filter="stale">GPS chậm</button>
                    <button class="dm-filter" data-filter="lost">Mất kết nối</button>
                </div>
            </div>
            <div id="dm-driver-list" class="dm-list"><div class="dm-empty">Đang tải vị trí tài xế...</div></div>
            <div id="dm-order-list" class="dm-list" style="display:none"></div>
        </aside>
        <div id="driver-map-wrapper">
            <div id="driver-map"></div>
            <div id="driver-map-counter">— online</div>
            <div class="dm-map-actions"><button id="dm-fit-all" class="dm-map-action" type="button">Hiện tất cả</button></div>
        </div>
    </div>
</div>

@if (!app()->runningInConsole())
<script>
(function () {
    var cfg = {
        firebase:    {!! json_encode($this->getFirebaseConfig()) !!},
        driversMeta: {!! json_encode($driversMeta) !!},
        ordersMeta:  {!! json_encode($ordersMeta) !!},
        wireId:      {!! json_encode($this->getId()) !!},
        center:      { lat: {{ $cities[0]['lat'] ?? 10.0125 }}, lng: {{ $cities[0]['lng'] ?? 105.0809 }} },
    };

    var COLORS = { free: '#22c55e', busy: '#3b82f6', stale: '#f59e0b', lost: '#ef4444' };
    var LABELS = { free: 'Đang rảnh', busy: 'Đang giao đơn', stale: 'GPS chậm', lost: 'Mất kết nối' };
    var STALE_MS = 180000;
    var DARK_STYLE = [
        { elementType: 'geometry', stylers: [{ color: '#1d2433' }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#94a3b8' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#1d2433' }] },
        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2c364a' }] },
        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#3a4660' }] },
        { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0f1624' }] },
        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    ];
    var LIGHT_STYLE = [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }, { featureType: 'transit', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] }];

    function init() {
        if (window._dmTeardown) { try { window._dmTeardown(); } catch (e) {} }

        var mapEl = document.getElementById('driver-map');
        if (!mapEl) return;

        var map, infoWindow, circle, themeObserver, locRef, locHandler;
        var markers = {}, orderMarkers = {}, iconCache = {}, eligible = {};
        var rtdbGps = {};
        var dbMeta = cfg.driversMeta || {};
        var ordersMeta = cfg.ordersMeta || [];
        var activeFilter = 'all', searchTerm = '', tab = 'drivers', selectedOrder = null, fitted = false;

        var $ = function (id) { return document.getElementById(id); };
        function escapeHtml(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
        function initial(meta, id) { var n = (meta && meta.name || '').trim(); return n ? n.charAt(0).toUpperCase() : '#'; }
        function isDark() { return document.documentElement.classList.contains('dark'); }

        // updated_at của Firebase là mili-giây
        function formatAgo(ms) {
            if (!ms) return 'Chưa rõ';
            var m = Math.max(0, Math.round((Date.now() - ms) / 60000));
            if (m < 1) return 'Vừa xong';
            if (m < 60) return m + ' phút trước';
            return Math.round(m / 60) + ' giờ trước';
        }
        function km(a, b) {
            var R = 6371, rad = Math.PI / 180;
            var dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
            var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return 2 * R * Math.asin(Math.sqrt(h));
        }

        /** free | busy | stale (có GPS) · lost (bật online nhưng không có GPS) · null (offline). */
        function classify(id) {
            var meta = dbMeta[id] || {}, gps = rtdbGps[id];
            if (gps && gps.is_online === true) {
                if (!gps.updated_at || (Date.now() - gps.updated_at) > STALE_MS) return 'stale';
                return meta.busy === true ? 'busy' : 'free';
            }
            return meta.is_online === true ? 'lost' : null;
        }
        function hasPosition(state) { return state === 'free' || state === 'busy' || state === 'stale'; }

        // ── Bản đồ ───────────────────────────────────────────────────────────
        map = window._map = new google.maps.Map(mapEl, {
            center: cfg.center, zoom: 13, mapTypeControl: false, streetViewControl: false, fullscreenControl: true,
            styles: isDark() ? DARK_STYLE : LIGHT_STYLE,
        });
        infoWindow = new google.maps.InfoWindow();
        themeObserver = new MutationObserver(function () { map.setOptions({ styles: isDark() ? DARK_STYLE : LIGHT_STYLE }); });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        function fitMapHeight() {
            var w = $('driver-map-wrapper'); if (!w) return;
            var top = w.getBoundingClientRect().top + window.scrollY;
            w.style.height = Math.max(400, window.innerHeight - top - 24) + 'px';
        }
        fitMapHeight();
        window.addEventListener('resize', fitMapHeight);

        function fitAll() {
            var b = new google.maps.LatLngBounds(), n = 0;
            Object.keys(markers).forEach(function (id) { if (markers[id].getVisible()) { b.extend(markers[id].getPosition()); n++; } });
            Object.keys(orderMarkers).forEach(function (id) { b.extend(orderMarkers[id].getPosition()); n++; });
            if (!n) return;
            map.fitBounds(b, 60);
            google.maps.event.addListenerOnce(map, 'idle', function () { if (map.getZoom() > 15) map.setZoom(15); });
        }

        // ── Icon tài xế (avatar tròn viền màu; không có ảnh thì chữ cái đầu) ──
        function buildIcon(id, meta, state, cb) {
            var key = id + '_' + state + '_' + (meta.avatar ? 'a' : 'n');
            if (iconCache[key]) { cb(iconCache[key]); return; }
            var S = 40, B = 3, color = COLORS[state];
            var canvas = document.createElement('canvas'); canvas.width = S; canvas.height = S;
            var ctx = canvas.getContext('2d');
            function draw(img) {
                ctx.clearRect(0, 0, S, S);
                ctx.beginPath(); ctx.arc(S / 2, S / 2, S / 2 - 1, 0, Math.PI * 2); ctx.fillStyle = color; ctx.fill();
                ctx.save(); ctx.beginPath(); ctx.arc(S / 2, S / 2, S / 2 - B, 0, Math.PI * 2); ctx.clip();
                if (img) { ctx.drawImage(img, B, B, S - B * 2, S - B * 2); }
                else {
                    ctx.fillStyle = '#e5e7eb'; ctx.fillRect(0, 0, S, S);
                    ctx.fillStyle = color; ctx.font = 'bold 18px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(initial(meta, id), S / 2, S / 2 + 1);
                }
                ctx.restore();
                var icon;
                try { icon = { url: canvas.toDataURL(), scaledSize: new google.maps.Size(S, S), anchor: new google.maps.Point(S / 2, S / 2) }; }
                catch (e) { icon = { path: google.maps.SymbolPath.CIRCLE, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 9 }; }
                iconCache[key] = icon; cb(icon);
            }
            if (meta.avatar) {
                var img = new Image(); img.crossOrigin = 'anonymous';
                img.onload = function () { draw(img); }; img.onerror = function () { draw(null); };
                img.src = meta.avatar;
            } else draw(null);
        }

        function driverPopup(id) {
            var meta = dbMeta[id] || {}, gps = rtdbGps[id], state = classify(id) || 'lost', color = COLORS[state];
            var phone = (meta.phone || '').replace(/\D+/g, '');
            var avatar = '<div style="position:relative;width:56px;height:56px;margin:0 auto;border-radius:50%;overflow:hidden;background:#e5e7eb;border:2.5px solid ' + color + ';display:grid;place-items:center;font-weight:700;color:' + color + ';font-size:20px">' + escapeHtml(initial(meta, id)) +
                (meta.avatar ? '<img src="' + escapeHtml(meta.avatar) + '" onerror="this.remove()" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">' : '') + '</div>';
            var row = function (icon, html) { return '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151"><span style="width:16px;text-align:center;flex-shrink:0">' + icon + '</span>' + html + '</div>'; };
            var link = 'color:#2563eb;text-decoration:none;font-weight:600';
            return '<div style="width:220px;font-family:inherit;padding:2px">' +
                '<div style="text-align:center;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid #f0f0f0">' + avatar +
                '<div style="margin-top:8px;font-size:14.5px;font-weight:700;color:#111827">' + escapeHtml(meta.name || ('#' + id)) + '</div>' +
                '<span style="display:inline-block;margin-top:5px;background:' + color + ';color:#fff;padding:2px 11px;border-radius:999px;font-size:12px;font-weight:600">' + LABELS[state] + '</span></div>' +
                '<div style="display:flex;flex-direction:column;gap:7px">' +
                (phone ? row('📞', '<a href="tel:' + phone + '" style="' + link + '">' + escapeHtml(meta.phone) + '</a><a href="https://zalo.me/' + phone + '" target="_blank" rel="noopener" style="' + link + ';margin-left:auto">Zalo</a>') : row('📞', '<span style="color:#9ca3af">—</span>')) +
                row('⭐', 'Điểm: ' + (meta.driver_score != null ? meta.driver_score : '—')) +
                row('🗓', '<span>' + escapeHtml(meta.shift_names || 'Chưa đăng ký ca') + '</span>') +
                (meta.active_order_code ? row('📦', '<a href="' + escapeHtml(meta.order_url || '#') + '" style="' + link + '">Đơn #' + escapeHtml(meta.active_order_code) + '</a>') : '') +
                row('🕐', '<span style="color:#6b7280">' + formatAgo(gps && gps.updated_at) + '</span>') +
                (meta.url ? '<a href="' + escapeHtml(meta.url) + '" style="' + link + ';text-align:center;margin-top:4px">Xem hồ sơ tài xế →</a>' : '') +
                '</div></div>';
        }

        // ── Marker tài xế ────────────────────────────────────────────────────
        function renderMarkers() {
            // CHỈ lấy id từ dbMeta (đã lọc đúng khu vực ở PHP): node "locations" trên Firebase chứa mọi khu vực,
            // dùng nó để vẽ sẽ lộ vị trí tài xế ngoài phạm vi được xem.
            var ids = Object.keys(dbMeta).map(Number);
            var idSet = new Set(ids);
            Object.keys(markers).forEach(function (id) { if (!idSet.has(Number(id))) { markers[id].setMap(null); delete markers[id]; } });

            ids.forEach(function (id) {
                var meta = dbMeta[id] || {}, gps = rtdbGps[id], state = classify(id);
                if (!hasPosition(state) || gps.lat == null || gps.lng == null) { if (markers[id]) markers[id].setVisible(false); return; }
                var pos = { lat: gps.lat, lng: gps.lng };
                var m = markers[id];
                if (!m) {
                    m = markers[id] = new google.maps.Marker({
                        position: pos, map: map, visible: true, zIndex: 10, title: meta.name || ('#' + id),
                        icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: COLORS[state], fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 9 },
                    });
                    m.addListener('click', function () { infoWindow.setContent(driverPopup(id)); infoWindow.open(map, m); });
                } else { m.setPosition(pos); m.setVisible(true); m.setTitle(meta.name || ('#' + id)); }
                buildIcon(id, meta, state, function (icon) { if (markers[id]) markers[id].setIcon(icon); });
            });

            if (!fitted && Object.keys(markers).some(function (id) { return markers[id].getVisible(); })) { fitted = true; fitAll(); }
            renderDriverList();
            if (selectedOrder) renderOrderList();
        }

        // ── Đơn chờ ──────────────────────────────────────────────────────────
        function orderInfo(o) {
            return '<div style="font-family:inherit;min-width:200px"><b>#' + escapeHtml(o.code) + '</b><br><span style="font-size:12px">' + escapeHtml(o.address || '—') + '</span><br>' +
                '<span style="font-size:12px;color:#dc2626">Chờ ' + o.minutes + ' phút' + (o.stopped ? ' · hệ thống đã dừng tìm' : (o.offered ? ' · đang hỏi tài xế' : ' · chưa ai nhận')) + '</span><br>' +
                '<button type="button" onclick="_dmSelectOrder(' + o.id + ',true)" style="margin-top:6px;padding:4px 10px;border-radius:8px;background:#0ea5e9;color:#fff;font-size:12px;border:0;cursor:pointer">Xem tài xế gần nhất</button></div>';
        }
        function renderOrderMarkers() {
            var seen = {};
            ordersMeta.forEach(function (o) {
                seen[o.id] = true;
                var pos = { lat: o.lat, lng: o.lng }, m = orderMarkers[o.id];
                if (!m) {
                    m = orderMarkers[o.id] = new google.maps.Marker({
                        map: map, position: pos, zIndex: 999,
                        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: '#ef4444', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
                    });
                    m.addListener('click', function () { _select(o.id, false); infoWindow.setContent(orderInfo(m._order)); infoWindow.open(map, m); });
                }
                m._order = o; m.setPosition(pos);
            });
            Object.keys(orderMarkers).forEach(function (id) { if (!seen[id]) { orderMarkers[id].setMap(null); delete orderMarkers[id]; } });
        }

        /** Tài xế gần điểm lấy nhất: ưu tiên người rảnh; chỉ khi không ai rảnh mới gợi ý người đang giao. */
        function nearestDrivers(o) {
            var rows = [];
            Object.keys(dbMeta).forEach(function (rawId) {
                var id = Number(rawId), state = classify(id), gps = rtdbGps[id];
                if (state !== 'free' && state !== 'busy') return;
                if (Array.isArray(eligible[o.id]) && eligible[o.id].indexOf(id) === -1) return; // bị loại khỏi hộp thoại gán (nợ, khóa điểm, nghỉ phép...)
                rows.push({ id: id, state: state, meta: dbMeta[id], gps: gps, km: km({ lat: o.lat, lng: o.lng }, { lat: gps.lat, lng: gps.lng }) });
            });
            var free = rows.filter(function (r) { return r.state === 'free'; }).sort(function (a, b) { return a.km - b.km; });
            if (free.length) return { free: true, rows: free.slice(0, 5) };
            return { free: false, rows: rows.sort(function (a, b) { return a.km - b.km; }).slice(0, 5) };
        }

        function renderOrderList() {
            var list = $('dm-order-list'); if (!list) return;
            if (!ordersMeta.length) { list.innerHTML = '<div class="dm-empty">Không có đơn nào đang chờ tài xế.</div>'; return; }
            list.innerHTML = ordersMeta.map(function (o) {
                var active = selectedOrder === o.id, near = '';
                if (active) {
                    var n = nearestDrivers(o);
                    near = '<div class="dm-near"><div class="dm-near__title">' + (eligible[o.id] === undefined ? 'Đang kiểm tra điều kiện nhận đơn…' : n.rows.length ? (n.free ? 'Tài xế rảnh gần nhất' : 'Không có ai rảnh — tài xế đang giao gần nhất') : '') + '</div>' +
                        (n.rows.length ? n.rows.map(function (r) {
                            return '<div class="dm-near__row"><strong>' + escapeHtml(r.meta.name || ('#' + r.id)) + '</strong><span>' + r.km.toFixed(1).replace('.', ',') + ' km · ' + escapeHtml(formatAgo(r.gps.updated_at)) + '</span>' +
                                '<button type="button" class="dm-btn primary" onclick="event.stopPropagation();_dmAssign(' + o.id + ',' + r.id + ')">Gán</button></div>';
                        }).join('') : '<div class="dm-near__note">Chưa có tài xế nào có vị trí. Dùng nút "Gán tài xế" để chọn tay.</div>') +
                        '<div class="dm-near__note">Khoảng cách là đường chim bay, chỉ để ước lượng.</div></div>';
                }
                return '<div class="dm-order' + (active ? ' active' : '') + '" data-order-id="' + o.id + '" role="button" tabindex="0">' +
                    '<div class="dm-order__top"><b>#' + escapeHtml(o.code) + '</b>' + (o.stopped ? '<span class="dm-tag">Hệ thống đã dừng tìm</span>' : (o.offered ? '<span class="dm-tag soft">Đang hỏi tài xế</span>' : '')) + '<em>Chờ ' + o.minutes + ' phút</em></div>' +
                    '<div class="dm-order__addr">↑ ' + escapeHtml(o.address || '—') + '<br>↓ ' + escapeHtml(o.delivery || '—') + '</div>' +
                    (o.note ? '<div class="dm-order__note" title="' + escapeHtml(o.note) + '">Ghi chú: ' + escapeHtml(o.note) + '</div>' : '') +
                    '<div class="dm-order__acts"><button type="button" class="dm-btn primary" onclick="event.stopPropagation();_dmAssign(' + o.id + ')">Gán tài xế</button><a class="dm-btn" href="' + escapeHtml(o.url) + '" onclick="event.stopPropagation()">Mở đơn</a><span class="dm-near__note" style="align-self:center">' + Number(o.fee).toLocaleString('vi-VN') + 'đ</span></div>' +
                    near + '</div>';
            }).join('');
            list.querySelectorAll('.dm-order').forEach(function (el) {
                el.addEventListener('click', function () { _select(Number(el.dataset.orderId), true); });
            });
        }

        function _select(id, pan) {
            var o = ordersMeta.filter(function (x) { return x.id === id; })[0];
            if (!o) return;
            selectedOrder = id;
            if (circle) circle.setMap(null);
            circle = new google.maps.Circle({ map: map, center: { lat: o.lat, lng: o.lng }, radius: 2000, strokeColor: '#ef4444', strokeOpacity: .8, strokeWeight: 2, fillColor: '#ef4444', fillOpacity: .08, clickable: false });
            if (pan) { map.panTo({ lat: o.lat, lng: o.lng }); if (map.getZoom() < 14) map.setZoom(14); }
            setTab('orders');
            renderOrderList();
            if (eligible[id] === undefined && window.Livewire && cfg.wireId) {
                // Lọc gợi ý theo đúng điều kiện của hộp thoại gán; lỗi mạng thì giữ nguyên gợi ý theo vị trí
                Promise.resolve(window.Livewire.find(cfg.wireId).call('eligibleDriverIds', id)).then(function (ids) {
                    eligible[id] = Array.isArray(ids) ? ids.map(Number) : null;
                    if (selectedOrder === id) renderOrderList();
                }).catch(function () { eligible[id] = null; if (selectedOrder === id) renderOrderList(); });
            }
            var el = document.querySelector('.dm-order[data-order-id="' + id + '"]');
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }
        function clearSelection() { selectedOrder = null; if (circle) { circle.setMap(null); circle = null; } }

        window._dmSelectOrder = function (id, pan) { _select(id, pan); };
        window._dmAssign = function (orderId, driverId) {
            if (!(window.Livewire && cfg.wireId)) return;
            var args = { order: orderId };
            if (driverId) args.driver = driverId;
            window.Livewire.find(cfg.wireId).call('mountAction', 'assign', args);
        };

        // ── Danh sách tài xế + số liệu ───────────────────────────────────────
        function renderDriverList() {
            var rows = [], counts = { free: 0, busy: 0, stale: 0, lost: 0 };
            Object.keys(dbMeta).forEach(function (rawId) {
                var id = Number(rawId), state = classify(id);
                if (!state) return;
                counts[state]++;
                rows.push({ id: id, meta: dbMeta[id] || {}, gps: rtdbGps[id], state: state });
            });
            var online = counts.free + counts.busy + counts.stale;
            var set = { 'dm-stat-online': online, 'dm-stat-free': counts.free, 'dm-stat-busy': counts.busy, 'dm-stat-stale': counts.stale, 'dm-stat-lost': counts.lost, 'dm-stat-orders': ordersMeta.length, 'dm-tab-orders': ordersMeta.length };
            Object.keys(set).forEach(function (k) { var el = $(k); if (el) el.textContent = set[k]; });
            var counter = $('driver-map-counter');
            if (counter) counter.textContent = online + ' đang online' + (counts.lost ? ' · ' + counts.lost + ' mất kết nối' : '');

            rows = rows.filter(function (r) {
                var okFilter = activeFilter === 'all' || activeFilter === r.state;
                var hay = ((r.meta.name || '') + ' ' + (r.meta.phone || '')).toLowerCase();
                return okFilter && (!searchTerm || hay.indexOf(searchTerm) !== -1);
            }).sort(function (a, b) {
                var w = { lost: 0, stale: 1, busy: 2, free: 3 };
                if (w[a.state] !== w[b.state]) return w[a.state] - w[b.state];
                return (a.meta.name || '').localeCompare(b.meta.name || '', 'vi');
            });

            var list = $('dm-driver-list'); if (!list) return;
            if (!rows.length) { list.innerHTML = '<div class="dm-empty">Không tìm thấy tài xế phù hợp.</div>'; return; }
            list.innerHTML = rows.map(function (r) {
                var color = COLORS[r.state];
                var ago = r.gps && r.gps.updated_at ? formatAgo(r.gps.updated_at) : (r.state === 'lost' ? 'Chưa có vị trí' : '');
                var order = r.meta.active_order_code ? ' · #' + escapeHtml(r.meta.active_order_code) : '';
                return '<button type="button" class="dm-driver" data-driver-id="' + r.id + '" style="--dm-color:' + color + '">' +
                    '<span class="dm-avatar"><span>' + escapeHtml(initial(r.meta, r.id)) + '</span>' + (r.meta.avatar ? '<img src="' + escapeHtml(r.meta.avatar) + '" alt="" onerror="this.remove()">' : '') + '</span>' +
                    '<span><span class="dm-driver__top"><span class="dm-driver__name">' + escapeHtml(r.meta.name || ('#' + r.id)) + '</span><span class="dm-driver__ago">' + escapeHtml(ago) + '</span></span>' +
                    '<span class="dm-driver__meta"><span class="dm-status">' + LABELS[r.state] + order + '</span><span>' + escapeHtml(r.meta.phone || '—') + '</span></span></span></button>';
            }).join('');
            list.querySelectorAll('.dm-driver').forEach(function (button) {
                button.addEventListener('click', function () {
                    list.querySelectorAll('.dm-driver').forEach(function (i) { i.classList.remove('active'); });
                    button.classList.add('active');
                    var id = Number(button.dataset.driverId), marker = markers[id];
                    if (marker && marker.getVisible()) {
                        map.panTo(marker.getPosition()); map.setZoom(Math.max(map.getZoom(), 15));
                        google.maps.event.trigger(marker, 'click');
                    } else {
                        // Mất kết nối: không có vị trí để bay tới, hiện thông tin liên hệ ở giữa bản đồ
                        infoWindow.setContent(driverPopup(id)); infoWindow.setPosition(map.getCenter()); infoWindow.open(map);
                    }
                });
            });
        }

        // ── Tab / bộ lọc / thẻ số liệu ───────────────────────────────────────
        function setTab(t) {
            tab = t;
            document.querySelectorAll('.dm-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === t); });
            $('dm-tools').style.display = t === 'drivers' ? '' : 'none';
            $('dm-driver-list').style.display = t === 'drivers' ? '' : 'none';
            $('dm-order-list').style.display = t === 'orders' ? '' : 'none';
            if (t === 'orders') renderOrderList(); else if (selectedOrder) { clearSelection(); }
            document.querySelectorAll('.dm-stat').forEach(function (b) { b.classList.toggle('active', (t === 'orders' && b.dataset.stat === 'orders') || (t === 'drivers' && b.dataset.stat === activeFilter)); });
        }
        function setFilter(f) {
            activeFilter = f;
            document.querySelectorAll('.dm-filter').forEach(function (b) { b.classList.toggle('active', b.dataset.filter === f); });
            setTab('drivers'); renderDriverList();
        }
        document.querySelectorAll('.dm-tab').forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });
        document.querySelectorAll('.dm-filter').forEach(function (b) { b.addEventListener('click', function () { setFilter(b.dataset.filter); }); });
        document.querySelectorAll('.dm-stat').forEach(function (b) {
            b.addEventListener('click', function () { b.dataset.stat === 'orders' ? setTab('orders') : setFilter(b.dataset.stat); });
        });
        $('dm-search').addEventListener('input', function () { searchTerm = this.value.trim().toLowerCase(); renderDriverList(); });
        $('dm-fit-all').addEventListener('click', fitAll);
        setTab('drivers'); setFilter('all');

        // ── Dữ liệu ──────────────────────────────────────────────────────────
        function onMeta(e) { dbMeta = e.detail.meta || {}; renderMarkers(); }
        function onOrders(e) {
            ordersMeta = e.detail.orders || [];
            if (selectedOrder && !ordersMeta.some(function (o) { return o.id === selectedOrder; })) clearSelection();
            renderOrderMarkers(); renderDriverList(); if (tab === 'orders') renderOrderList();
        }
        window.addEventListener('metaUpdated', onMeta);
        window.addEventListener('ordersUpdated', onOrders);

        if (!firebase.apps.length) firebase.initializeApp(cfg.firebase);
        locRef = firebase.database().ref('locations');
        locHandler = function (snap) {
            var raw = snap.val() || {}, next = {};
            Object.keys(raw).forEach(function (key) {
                var id = parseInt(key.replace('driver_', ''), 10), v = raw[key];
                if (!isNaN(id) && v && v.lat && v.lng) next[id] = { lat: v.lat, lng: v.lng, updated_at: v.updated_at, is_online: v.is_online };
            });
            rtdbGps = next; renderMarkers();
        };
        locRef.on('value', locHandler, function (err) { console.error('[DriverMap] Firebase read failed:', err); });

        renderMarkers(); renderOrderMarkers();

        window._dmTeardown = function () {
            try { locRef.off('value', locHandler); } catch (e) {}
            window.removeEventListener('metaUpdated', onMeta);
            window.removeEventListener('ordersUpdated', onOrders);
            window.removeEventListener('resize', fitMapHeight);
            if (themeObserver) themeObserver.disconnect();
            window._dmTeardown = null;
        };
    }

    function whenReady() {
        if (window.firebase && firebase.database && window.google && window.google.maps) { init(); return; }
        setTimeout(whenReady, 100);
    }
    function loadScript(src, cb) {
        var s = document.createElement('script'); s.src = src; s.onload = cb; document.head.appendChild(s);
    }
    if (window.firebase && firebase.database) whenReady();
    else if (window._dmFirebaseLoading) whenReady();
    else {
        window._dmFirebaseLoading = true;
        loadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js', function () {
            loadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-database-compat.js', whenReady);
        });
    }
})();
</script>
@endif

</x-filament-panels::page>
