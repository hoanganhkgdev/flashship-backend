<x-filament-panels::page>

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

            var allIds = new Set(
                Object.keys(dbMeta).map(Number).concat(Object.keys(rtdbGps).map(Number))
            );

            Object.keys(markers).forEach(function(id) {
                if (!allIds.has(Number(id))) { markers[id].setMap(null); delete markers[id]; }
            });

            var onlineCount = 0;

            allIds.forEach(function(id) {
                var meta = dbMeta[id]  || {};
                var gps  = rtdbGps[id] || {};
                var lat  = gps.lat  != null ? gps.lat  : meta.lat;
                var lng  = gps.lng  != null ? gps.lng  : meta.lng;

                // Firebase is_online ưu tiên hơn DB (real-time hơn) — chỉ hiện
                // tài xế đang online, ẩn hẳn (không chỉ mờ đi) tài xế offline.
                var isOnline = gps.is_online !== undefined ? (gps.is_online === true) : (meta.is_online === true);
                var visible  = isOnline && lat != null && lng != null;

                if (!visible) {
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
                            var ago = marker._gps && marker._gps.updated_at
                                ? Math.round((Date.now() / 1000 - marker._gps.updated_at) / 60) + ' phút trước'
                                : 'GPS từ DB';
                            var busy  = marker._meta.busy === true;
                            var color = statusColor(busy);
                            var badge = busy ? '🔵 Đang đi đơn' : '🟢 Đang rảnh';
                            var avatarHtml = marker._meta.avatar
                                ? '<img src="' + marker._meta.avatar + '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid ' + color + ';margin-bottom:6px;">'
                                : '<div style="width:48px;height:48px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;margin-bottom:6px;font-size:20px;">👤</div>';
                            infoWindow.setContent(
                                '<div style="font-size:13px;line-height:1.8;min-width:180px;padding:4px 0;text-align:center">' +
                                avatarHtml +
                                '<strong style="font-size:14px;display:block">' + (marker._meta.name || '#' + driverId) + '</strong>' +
                                '<span style="background:' + color + ';color:#fff;padding:1px 10px;border-radius:999px;font-size:11px;display:inline-block;margin-bottom:4px">' + badge + '</span><br>' +
                                (marker._meta.phone ? '<a href="https://zalo.me/' + marker._meta.phone + '" target="_blank" style="color:#0068ff;text-decoration:none;">📞 ' + marker._meta.phone + '</a><br>' : '<span style="color:#6b7280">📞 —</span><br>') +
                                '<span style="color:#6b7280">⭐ Điểm: ' + (marker._meta.driver_score != null ? marker._meta.driver_score : '—') + '</span><br>' +
                                '<span style="color:#6b7280">🕐 ' + ago + '</span></div>'
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
