<x-filament-panels::page>

<header class="fs-page-header">
    <div>
        <p class="fs-page-header__eyebrow">Theo dõi thời gian thực</p>
        <h1 class="fs-page-header__title">Bản đồ tài xế</h1>
        <p class="fs-page-header__description">Vị trí GPS, trạng thái sẵn sàng và tình trạng giao đơn của tài xế trong khu vực.</p>
    </div>
</header>

<style>
    .dm-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
    .dm-stat { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #e5e7eb; border-radius:13px; background:#fff; }
    .dm-stat i { width:10px; height:10px; flex:none; border-radius:50%; }
    .dm-stat div { min-width:0; }
    .dm-stat strong { display:block; color:#0f172a; font-size:18px; font-weight:650; line-height:1; }
    .dm-stat span { color:#64748b; font-size:12px; }
    .dm-layout { display:grid; grid-template-columns:minmax(260px,31%) minmax(0,1fr); min-height:680px; overflow:hidden; border:1px solid #e5e7eb; border-radius:16px; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.05); }
    .dm-sidebar { display:flex; min-width:0; flex-direction:column; border-right:1px solid #e5e7eb; background:#f8fafc; }
    .dm-tools { display:grid; gap:8px; padding:12px; border-bottom:1px solid #e5e7eb; }
    .dm-search { width:100%; padding:9px 11px; border:1px solid #dbe2ea; border-radius:10px; background:#fff; color:#0f172a; font-size:13px; outline:none; }
    .dm-search:focus { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
    .dm-filters { display:flex; gap:5px; overflow-x:auto; }
    .dm-filter { flex:none; padding:5px 9px; border:1px solid #e2e8f0; border-radius:999px; background:#fff; color:#64748b; font-size:11px; }
    .dm-filter.active { border-color:#fed7aa; background:#fff7ed; color:#c2410c; }
    .dm-list { flex:1; overflow-y:auto; padding:6px; }
    .dm-empty { padding:30px 12px; color:#94a3b8; font-size:13px; text-align:center; }
    .dm-driver { display:grid; grid-template-columns:40px minmax(0,1fr); gap:10px; width:100%; padding:9px; border:1px solid transparent; border-radius:11px; text-align:left; transition:.15s; }
    .dm-driver:hover,.dm-driver.active { border-color:#fed7aa; background:#fff; }
    .dm-avatar { position:relative; width:40px; height:40px; overflow:hidden; border:2px solid var(--dm-color); border-radius:50%; background:#e2e8f0; }
    .dm-avatar img { width:100%; height:100%; object-fit:cover; }
    .dm-avatar span { display:grid; width:100%; height:100%; place-items:center; color:#64748b; }
    .dm-driver__top { display:flex; min-width:0; align-items:center; justify-content:space-between; gap:6px; }
    .dm-driver__name { overflow:hidden; color:#0f172a; font-size:13px; font-weight:600; text-overflow:ellipsis; white-space:nowrap; }
    .dm-driver__ago { flex:none; color:#94a3b8; font-size:10px; }
    .dm-driver__meta { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-top:4px; color:#64748b; font-size:11px; }
    .dm-status { color:var(--dm-color); }
    #driver-map-wrapper { position:relative; width:100%; min-height:680px; }
    #driver-map { position: absolute; inset: 0; width: 100%; height: 100%; }
    #driver-map-counter {
        position: absolute; top: 12px; left: 12px; z-index: 5;
        background: #fff; border-radius: 999px; padding: 6px 14px;
        font-size: 12.5px; font-weight: 600; color: #374151;
        box-shadow: 0 1px 4px rgba(0,0,0,0.18);
    }
    .dm-map-actions { position:absolute; top:12px; right:12px; z-index:5; display:flex; gap:6px; }
    .dm-map-action { padding:7px 10px; border:none; border-radius:9px; background:#fff; color:#475569; box-shadow:0 1px 5px rgba(0,0,0,.18); font-size:11px; cursor:pointer; }
    .dark .dm-stat,.dark .dm-layout,.dark .dm-driver:hover,.dark .dm-driver.active,.dark .dm-search,.dark .dm-filter { border-color:#293142; background:#171b25; }
    .dark .dm-sidebar { border-color:#293142; background:#121620; }
    .dark .dm-tools { border-color:#293142; }
    .dark .dm-stat strong,.dark .dm-driver__name,.dark .dm-search { color:#f8fafc; }
    .dark #driver-map-counter { background:#1f2937; color:#e5e7eb; }
    @media(max-width:900px){.dm-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.dm-layout{display:flex; min-height:auto; flex-direction:column}.dm-sidebar{max-height:340px; border-right:none; border-bottom:1px solid #e5e7eb}.dm-list{min-height:220px}#driver-map-wrapper{min-height:55vh}}
</style>

{{-- Làm mới trạng thái "đang đi đơn / đang rảnh" mỗi 15s — vị trí GPS đã
     realtime qua Firebase riêng, chỉ mục này cần Livewire poll vì lấy từ
     bảng orders (MySQL), không có kênh realtime sẵn như GPS. --}}
<div wire:poll.15s="loadDriversMeta" style="display:none"></div>

<div wire:ignore>
    <div class="dm-stats">
        <div class="dm-stat"><i style="background:#22c55e"></i><div><strong id="dm-stat-online">0</strong><span>Đang online</span></div></div>
        <div class="dm-stat"><i style="background:#16a34a"></i><div><strong id="dm-stat-free">0</strong><span>Đang rảnh</span></div></div>
        <div class="dm-stat"><i style="background:#3b82f6"></i><div><strong id="dm-stat-busy">0</strong><span>Đang giao đơn</span></div></div>
        <div class="dm-stat"><i style="background:#f59e0b"></i><div><strong id="dm-stat-stale">0</strong><span>GPS chậm</span></div></div>
    </div>
    <div class="dm-layout">
        <aside class="dm-sidebar">
            <div class="dm-tools">
                <input id="dm-search" class="dm-search" type="search" placeholder="Tìm tên hoặc số điện thoại...">
                <div class="dm-filters">
                    <button class="dm-filter active" data-filter="all">Tất cả</button>
                    <button class="dm-filter" data-filter="free">Đang rảnh</button>
                    <button class="dm-filter" data-filter="busy">Đang giao</button>
                    <button class="dm-filter" data-filter="stale">GPS chậm</button>
                </div>
            </div>
            <div id="dm-driver-list" class="dm-list"><div class="dm-empty">Đang tải vị trí tài xế...</div></div>
        </aside>
        <div id="driver-map-wrapper">
            <div id="driver-map"></div>
            <div id="driver-map-counter">— online</div>
            <div class="dm-map-actions"><button id="dm-fit-all" class="dm-map-action" type="button">Hiện tất cả</button></div>
        </div>
    </div>
</div>

{{-- Config ban đầu — chỉ set nếu chưa init --}}
<script>
    if (!window._mapReady) {
        window._mapCfg = {
            firebase:    {!! json_encode($this->getFirebaseConfig()) !!},
            mapsKey:     {!! json_encode($this->getGoogleMapsKey()) !!},
            driversMeta: {!! json_encode($driversMeta) !!},
        };
    }
</script>

{{-- Firebase SDK + init (Maps đã được load global bởi AdminPanelProvider) --}}
@if (!app()->runningInConsole())
<script>
if (!window._mapReady) {
    window._initDriverMap = function () {
        window._mapReady = true;

        var cfg     = window._mapCfg;
        var map, infoWindow;
        var markers = {};
        var rtdbGps = {};
        var dbMeta  = cfg.driversMeta;
        var activeFilter = 'all';
        var searchTerm = '';

        // Firebase init
        if (!firebase.apps.length) firebase.initializeApp(cfg.firebase);
        var rtdb = firebase.database();

        // Livewire event → update metadata
        window.addEventListener('metaUpdated', function(e) {
            dbMeta = e.detail.meta;
            renderMarkers();
        });

        // Bản đồ full chiều cao còn lại của viewport — tính lại mỗi khi resize
        function fitMapHeight() {
            var wrapper = document.getElementById('driver-map-wrapper');
            if (!wrapper) return;
            var top = wrapper.getBoundingClientRect().top + window.scrollY;
            var h   = window.innerHeight - top - 24;
            wrapper.style.height = Math.max(400, h) + 'px';
        }
        fitMapHeight();
        window.addEventListener('resize', fitMapHeight);

        map = window._map = new google.maps.Map(document.getElementById('driver-map'), {
            center: { lat: {{ $cities[0]['lat'] ?? 10.0125 }}, lng: {{ $cities[0]['lng'] ?? 105.0809 }} },
            zoom: 13,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            styles: [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }],
        });
        infoWindow = new google.maps.InfoWindow();

        var searchInput = document.getElementById('dm-search');
        if (searchInput) searchInput.addEventListener('input', function () {
            searchTerm = this.value.trim().toLowerCase();
            renderDriverList();
        });
        document.querySelectorAll('.dm-filter').forEach(function(button) {
            button.addEventListener('click', function() {
                document.querySelectorAll('.dm-filter').forEach(function(item) { item.classList.remove('active'); });
                button.classList.add('active');
                activeFilter = button.dataset.filter || 'all';
                renderDriverList();
            });
        });
        var fitAllButton = document.getElementById('dm-fit-all');
        if (fitAllButton) fitAllButton.addEventListener('click', function() {
            var bounds = new google.maps.LatLngBounds();
            var count = 0;
            Object.values(markers).forEach(function(marker) {
                if (marker.getVisible()) { bounds.extend(marker.getPosition()); count++; }
            });
            if (count) map.fitBounds(bounds);
        });

        // Firebase real-time GPS — path: locations/driver_{id}
        rtdb.ref('locations').on('value', function(snapshot) {
            var raw = snapshot.val() || {};
            var newGps = {};
            Object.entries(raw).forEach(function(entry) {
                var key = entry[0]; // "driver_123"
                var val = entry[1];
                var id  = parseInt(key.replace('driver_', ''), 10);
                if (!isNaN(id) && val && val.lat && val.lng) {
                    newGps[id] = { lat: val.lat, lng: val.lng, updated_at: val.updated_at, is_online: val.is_online };
                }
            });
            rtdbGps = newGps;
            renderMarkers();
        }, function(error) {
            console.error('[DriverMap] Firebase read failed:', error);
        });

        window._renderMarkers = renderMarkers;

        // Xanh lá = đang rảnh, xanh dương = đang đi đơn.
        function statusColor(isBusy, isStale) {
            if (isStale) return '#f59e0b';
            return isBusy ? '#3b82f6' : '#22c55e';
        }

        function isGpsStale(gps) {
            return !gps || !gps.updated_at || (Date.now() - gps.updated_at) > 180000;
        }

        // updated_at của Firebase là mili-giây (ServerValue.timestamp) —
        // Date.now() cũng mili-giây, trừ thẳng cho nhau rồi mới quy đổi phút.
        function formatAgo(updatedAtMs) {
            if (!updatedAtMs) return 'Chưa rõ';
            var diffMin = Math.max(0, Math.round((Date.now() - updatedAtMs) / 60000));
            if (diffMin < 1)  return 'Vừa xong';
            if (diffMin < 60) return diffMin + ' phút trước';
            return Math.round(diffMin / 60) + ' giờ trước';
        }

        // Cache icon canvas theo driverId + trạng thái bận (đổi màu viền khi
        // nhận/trả đơn nên cần cache riêng theo cả 2 trạng thái).
        var iconCache = {};

        function buildIcon(driverId, avatarUrl, isBusy, isStale, callback) {
            var cacheKey = driverId + (isStale ? '_stale' : (isBusy ? '_busy' : '_free'));
            if (iconCache[cacheKey]) { callback(iconCache[cacheKey]); return; }

            var S = 40, B = 3;
            var canvas = document.createElement('canvas');
            canvas.width = S; canvas.height = S;
            var ctx = canvas.getContext('2d');
            var color = statusColor(isBusy, isStale);

            function draw(img) {
                ctx.clearRect(0, 0, S, S);
                // Viền màu theo trạng thái
                ctx.beginPath();
                ctx.arc(S/2, S/2, S/2 - 1, 0, Math.PI*2);
                ctx.fillStyle = color;
                ctx.fill();
                // Clip avatar
                ctx.save();
                ctx.beginPath();
                ctx.arc(S/2, S/2, S/2 - B, 0, Math.PI*2);
                ctx.clip();
                if (img) {
                    ctx.drawImage(img, B, B, S - B*2, S - B*2);
                } else {
                    ctx.fillStyle = '#e5e7eb';
                    ctx.fillRect(0, 0, S, S);
                    ctx.fillStyle = color;
                    ctx.font = 'bold 16px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('?', S/2, S/2);
                }
                ctx.restore();
                var icon = {
                    url: canvas.toDataURL(),
                    scaledSize: new google.maps.Size(S, S),
                    anchor: new google.maps.Point(S/2, S/2),
                };
                iconCache[cacheKey] = icon;
                callback(icon);
            }

            if (avatarUrl) {
                var img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = function() { draw(img); };
                img.onerror = function() { draw(null); };
                img.src = avatarUrl;
            } else {
                draw(null);
            }
        }

        // Render ngay từ DB meta (không chờ Firebase)
        renderMarkers();

        function renderMarkers() {
            if (!map) return;

            // CHỈ lấy id từ dbMeta (đã lọc đúng khu vực ở PHP) — rtdbGps đọc
            // thẳng toàn bộ node "locations" trên Firebase, KHÔNG lọc theo
            // khu vực. Trước đây gộp cả 2 nguồn (concat) khiến city_manager
            // vẫn thấy được vị trí GPS thật (live, di chuyển) của tài xế
            // ngoài khu vực mình — dbMeta rỗng cho id đó nên tên/SĐT trống,
            // nhưng toạ độ là thật. Chỉ dùng dbMeta đảm bảo không bao giờ vẽ
            // marker cho tài xế ngoài phạm vi được phép xem.
            var allIds = new Set(Object.keys(dbMeta).map(Number));

            Object.keys(markers).forEach(function(id) {
                if (!allIds.has(Number(id))) { markers[id].setMap(null); delete markers[id]; }
            });

            var onlineCount = 0;

            allIds.forEach(function(id) {
                var meta = dbMeta[id]  || {};
                var gps  = rtdbGps[id]; // KHÔNG fallback {} — phải phân biệt được "chưa có dữ liệu"

                // Online/offline CHỈ tin Firebase — không còn lấy is_online từ
                // MySQL nữa (2 nguồn độc lập, dễ lệch nhau — vd tài xế bấm
                // online trong app nhưng chưa kịp có GPS, hoặc dữ liệu Firebase
                // bị xoá tay nhưng MySQL vẫn còn is_online=1 cũ).
                var isOnline = !!gps && gps.is_online === true;

                if (!isOnline) {
                    if (markers[id]) markers[id].setVisible(false);
                    return;
                }

                var lat, lng;
                if (gps.lat != null && gps.lng != null) {
                    // Có vị trí live từ Firebase — luôn ưu tiên.
                    lat = gps.lat; lng = gps.lng;
                } else if (markers[id]) {
                    // Firebase tạm thời chưa có toạ độ ở lần render này (vừa
                    // online, chưa kịp gửi GPS) — giữ nguyên vị trí live gần
                    // nhất thay vì trống hẳn.
                    var pos = markers[id].getPosition();
                    lat = pos.lat(); lng = pos.lng();
                } else {
                    // Online nhưng chưa từng có toạ độ nào từ Firebase — chưa
                    // hiện được, không còn nguồn MySQL nào để tạm dùng nữa.
                    if (markers[id]) markers[id].setVisible(false);
                    return;
                }

                onlineCount++;
                var isBusy = meta.busy === true;
                var isStale = isGpsStale(gps);

                if (markers[id]) {
                    markers[id].setPosition({ lat: lat, lng: lng });
                    markers[id].setVisible(true);
                    markers[id]._meta = meta;
                    markers[id]._gps  = gps;
                    buildIcon(id, meta.avatar, isBusy, isStale, function(icon) {
                        if (markers[id]) markers[id].setIcon(icon);
                    });
                } else {
                    var fallbackIcon = {
                        path: google.maps.SymbolPath.CIRCLE,
                        fillColor: statusColor(isBusy, isStale),
                        fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2,
                        scale: 9,
                    };
                    var m = new google.maps.Marker({
                        position: { lat: lat, lng: lng },
                        map: map, icon: fallbackIcon, visible: true,
                        title: meta.name || ('#' + id),
                        zIndex: 10,
                    });
                    m._meta = meta; m._gps = gps;
                    (function(marker, driverId, meta) {
                        buildIcon(driverId, meta.avatar, isBusy, isStale, function(icon) {
                            marker.setIcon(icon);
                        });
                        marker.addListener('click', function() {
                            var ago   = formatAgo(marker._gps && marker._gps.updated_at);
                            var busy  = marker._meta.busy === true;
                            var stale = isGpsStale(marker._gps);
                            var color = statusColor(busy, stale);
                            var badge = stale ? '🟠 GPS chậm' : (busy ? '🔵 Đang đi đơn' : '🟢 Đang rảnh');
                            var avatarHtml = marker._meta.avatar
                                ? '<img src="' + marker._meta.avatar + '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2.5px solid ' + color + ';">'
                                : '<div style="width:56px;height:56px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:24px;border:2.5px solid ' + color + ';">👤</div>';
                            var row = function(icon, content) {
                                return '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;">' +
                                    '<span style="width:16px;text-align:center;flex-shrink:0">' + icon + '</span>' + content + '</div>';
                            };
                            infoWindow.setContent(
                                '<div style="width:200px;font-family:inherit;padding:2px;">' +
                                    '<div style="text-align:center;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid #f0f0f0;">' +
                                        avatarHtml +
                                        '<div style="margin-top:8px;font-size:14.5px;font-weight:700;color:#111827;">' + (marker._meta.name || '#' + driverId) + '</div>' +
                                        '<span style="display:inline-block;margin-top:5px;background:' + color + ';color:#fff;padding:2px 11px;border-radius:999px;font-size:11px;font-weight:600;">' + badge + '</span>' +
                                    '</div>' +
                                    '<div style="display:flex;flex-direction:column;gap:7px;">' +
                                        (marker._meta.phone
                                            ? row('📞', '<a href="https://zalo.me/' + marker._meta.phone + '" target="_blank" style="color:#2563eb;text-decoration:none;">' + marker._meta.phone + '</a>')
                                            : row('📞', '<span style="color:#9ca3af">—</span>')) +
                                        row('⭐', 'Điểm: ' + (marker._meta.driver_score != null ? marker._meta.driver_score : '—')) +
                                        row('🗓', '<span>' + (marker._meta.shift_names || 'Chưa đăng ký ca') + '</span>') +
                                        (marker._meta.active_order_code ? row('📦', 'Đơn #' + marker._meta.active_order_code) : '') +
                                        row('🕐', '<span style="color:#6b7280">' + ago + '</span>') +
                                    '</div>' +
                                '</div>'
                            );
                            infoWindow.open(map, marker);
                        });
                    })(m, id, meta);
                    markers[id] = m;
                }
            });

            var counterEl = document.getElementById('driver-map-counter');
            if (counterEl) counterEl.textContent = onlineCount + ' đang online';
            renderDriverList();
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        function renderDriverList() {
            var rows = [];
            Object.keys(dbMeta).forEach(function(rawId) {
                var id = Number(rawId);
                var meta = dbMeta[id] || {};
                var gps = rtdbGps[id];
                if (!gps || gps.is_online !== true) return;
                rows.push({ id:id, meta:meta, gps:gps, stale:isGpsStale(gps) });
            });

            var online = rows.length;
            var busy = rows.filter(function(row) { return row.meta.busy === true; }).length;
            var stale = rows.filter(function(row) { return row.stale; }).length;
            var free = rows.filter(function(row) { return row.meta.busy !== true && !row.stale; }).length;
            var counts = { 'dm-stat-online':online, 'dm-stat-free':free, 'dm-stat-busy':busy, 'dm-stat-stale':stale };
            Object.keys(counts).forEach(function(id) { var el=document.getElementById(id); if(el) el.textContent=counts[id]; });

            rows = rows.filter(function(row) {
                var state = row.stale ? 'stale' : (row.meta.busy ? 'busy' : 'free');
                var matchesFilter = activeFilter === 'all' || activeFilter === state;
                var haystack = ((row.meta.name || '') + ' ' + (row.meta.phone || '')).toLowerCase();
                return matchesFilter && (!searchTerm || haystack.includes(searchTerm));
            }).sort(function(a,b) {
                if (a.stale !== b.stale) return a.stale ? -1 : 1;
                return (a.meta.name || '').localeCompare(b.meta.name || '', 'vi');
            });

            var list = document.getElementById('dm-driver-list');
            if (!list) return;
            if (!rows.length) {
                list.innerHTML = '<div class="dm-empty">Không tìm thấy tài xế phù hợp.</div>';
                return;
            }
            list.innerHTML = rows.map(function(row) {
                var state = row.stale ? 'GPS chậm' : (row.meta.busy ? 'Đang giao đơn' : 'Đang rảnh');
                var color = statusColor(row.meta.busy === true, row.stale);
                var avatar = row.meta.avatar
                    ? '<img src="' + escapeHtml(row.meta.avatar) + '" alt="">'
                    : '<span>👤</span>';
                var order = row.meta.active_order_code ? ' · #' + escapeHtml(row.meta.active_order_code) : '';
                return '<button type="button" class="dm-driver" data-driver-id="' + row.id + '" style="--dm-color:' + color + '">' +
                    '<span class="dm-avatar">' + avatar + '</span>' +
                    '<span><span class="dm-driver__top"><span class="dm-driver__name">' + escapeHtml(row.meta.name || ('#' + row.id)) + '</span><span class="dm-driver__ago">' + escapeHtml(formatAgo(row.gps.updated_at)) + '</span></span>' +
                    '<span class="dm-driver__meta"><span class="dm-status">' + state + order + '</span><span>' + escapeHtml(row.meta.phone || '—') + '</span></span></span></button>';
            }).join('');
            list.querySelectorAll('.dm-driver').forEach(function(button) {
                button.addEventListener('click', function() {
                    list.querySelectorAll('.dm-driver').forEach(function(item) { item.classList.remove('active'); });
                    button.classList.add('active');
                    var marker = markers[button.dataset.driverId];
                    if (!marker) return;
                    map.panTo(marker.getPosition());
                    map.setZoom(Math.max(map.getZoom(), 15));
                    google.maps.event.trigger(marker, 'click');
                });
            });
        }
    };

    // Load Firebase SDK rồi gọi init (Maps đã sẵn sàng từ global AdminPanelProvider)
    var s1 = document.createElement('script');
    s1.src = 'https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js';
    s1.onload = function() {
        var s2 = document.createElement('script');
        s2.src = 'https://www.gstatic.com/firebasejs/10.12.0/firebase-database-compat.js';
        s2.onload = function() {
            // Maps đã load xong từ AdminPanelProvider → gọi thẳng
            if (window.google && window.google.maps) {
                window._initDriverMap();
            } else {
                // Fallback: chờ Maps load nếu chưa xong
                var check = setInterval(function() {
                    if (window.google && window.google.maps) {
                        clearInterval(check);
                        window._initDriverMap();
                    }
                }, 100);
            }
        };
        document.head.appendChild(s2);
    };
    document.head.appendChild(s1);
}
</script>
@endif

</x-filament-panels::page>
