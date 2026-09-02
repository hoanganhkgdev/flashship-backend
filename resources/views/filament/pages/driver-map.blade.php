<x-filament-panels::page>

<header class="fs-page-header">
    <div>
        <p class="fs-page-header__eyebrow">Theo dõi thời gian thực</p>
        <h1 class="fs-page-header__title">Bản đồ tài xế</h1>
        <p class="fs-page-header__description">Vị trí GPS, trạng thái sẵn sàng và tình trạng giao đơn của tài xế trong khu vực.</p>
    </div>
</header>

<style>
    #driver-map-wrapper { position: relative; width: 100%; min-height: 70vh; }
    #driver-map { position: absolute; inset: 0; width: 100%; height: 100%; }
    #driver-map-counter {
        position: absolute; top: 12px; left: 12px; z-index: 5;
        background: #fff; border-radius: 999px; padding: 6px 14px;
        font-size: 12.5px; font-weight: 600; color: #374151;
        box-shadow: 0 1px 4px rgba(0,0,0,0.18);
    }
    .dark #driver-map-counter { background: #1f2937; color: #e5e7eb; }
</style>

{{-- Làm mới trạng thái "đang đi đơn / đang rảnh" mỗi 15s — vị trí GPS đã
     realtime qua Firebase riêng, chỉ mục này cần Livewire poll vì lấy từ
     bảng orders (MySQL), không có kênh realtime sẵn như GPS. --}}
<div wire:poll.15s="loadDriversMeta" style="display:none"></div>

{{-- MAP: wire:ignore — Livewire không được đụng vào đây --}}
<div wire:ignore>
    <div id="driver-map-wrapper">
        <div id="driver-map"></div>
        <div id="driver-map-counter">— online</div>
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
        function statusColor(isBusy) { return isBusy ? '#3b82f6' : '#22c55e'; }

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

        function buildIcon(driverId, avatarUrl, isBusy, callback) {
            var cacheKey = driverId + (isBusy ? '_busy' : '_free');
            if (iconCache[cacheKey]) { callback(iconCache[cacheKey]); return; }

            var S = 40, B = 3;
            var canvas = document.createElement('canvas');
            canvas.width = S; canvas.height = S;
            var ctx = canvas.getContext('2d');
            var color = statusColor(isBusy);

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

                if (markers[id]) {
                    markers[id].setPosition({ lat: lat, lng: lng });
                    markers[id].setVisible(true);
                    markers[id]._meta = meta;
                    markers[id]._gps  = gps;
                    buildIcon(id, meta.avatar, isBusy, function(icon) {
                        if (markers[id]) markers[id].setIcon(icon);
                    });
                } else {
                    var fallbackIcon = {
                        path: google.maps.SymbolPath.CIRCLE,
                        fillColor: statusColor(isBusy),
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
                        buildIcon(driverId, meta.avatar, isBusy, function(icon) {
                            marker.setIcon(icon);
                        });
                        marker.addListener('click', function() {
                            var ago   = formatAgo(marker._gps && marker._gps.updated_at);
                            var busy  = marker._meta.busy === true;
                            var color = statusColor(busy);
                            var badge = busy ? '🔵 Đang đi đơn' : '🟢 Đang rảnh';
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
