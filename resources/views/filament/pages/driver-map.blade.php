<x-filament-panels::page>

<style>
    #driver-map-wrapper { position: relative; width: 100%; padding-top: 100%; }
    #driver-map { position: absolute; inset: 0; width: 100%; height: 100%; }
</style>

{{-- TOOLBAR --}}
<div class="mb-3 rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">

    {{-- Top row: city buttons --}}
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800 flex justify-center">
        <div class="flex items-center gap-1.5 rounded-xl bg-gray-100 p-1 dark:bg-gray-800 w-fit">
            @foreach ($cities as $city)
            @if ($fixedCityId)
                {{-- city_manager: chỉ 1 button, không click được --}}
                <span id="city-btn-{{ $city['id'] }}"
                    class="city-btn rounded-lg px-4 py-1.5 text-sm font-medium"
                    style="background:#ffffff; color:#ea580c; box-shadow:0 1px 3px rgba(0,0,0,0.12); cursor:default;">
                    {{ $city['name'] }}
                </span>
            @else
                <button onclick="setCityFilter('{{ $city['id'] }}', { lat: {{ $city['lat'] }}, lng: {{ $city['lng'] }} })"
                    id="city-btn-{{ $city['id'] }}"
                    class="city-btn rounded-lg px-4 py-1.5 text-sm font-medium transition-all"
                    style="color: #6b7280;">
                    {{ $city['name'] }}
                </button>
            @endif
            @endforeach
        </div>
    </div>

    {{-- Bottom row: toggle + stats + realtime --}}
    <div class="flex items-center justify-between px-4 py-1.5">
        {{-- Offline toggle --}}
        <div class="flex cursor-pointer items-center gap-2" onclick="toggleOffline()" title="Hiện tài xế offline">
            <div class="relative" style="width:36px; height:20px;">
                <div id="toggle-track" style="position:absolute;inset:0;border-radius:999px;background:#f97316;transition:background .2s;"></div>
                <div id="toggle-thumb" style="position:absolute;top:2px;left:18px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.2);transition:left .2s;"></div>
            </div>
            <span style="color:#6b7280; font-size:12px; font-weight:500; user-select:none;">Hiện offline</span>
            <input type="checkbox" id="show-offline" checked class="hidden">
        </div>

        <div id="map-counter" class="rounded-full bg-gray-100 px-3 py-1 dark:bg-gray-800"
            style="font-size:11px; color:#6b7280;">Đang kết nối...</div>
    </div>
</div>

<script>
var _activeCityId = '{{ $fixedCityId ?? 2 }}';

function toggleOffline() {
    var cb    = document.getElementById('show-offline');
    var track = document.getElementById('toggle-track');
    var thumb = document.getElementById('toggle-thumb');
    cb.checked = !cb.checked;
    if (cb.checked) {
        track.style.background = '#f97316';
        thumb.style.left = '18px';
    } else {
        track.style.background = '#d1d5db';
        thumb.style.left = '2px';
    }
    if (window._renderMarkers) window._renderMarkers();
}
function setCityFilter(cityId, latlng) {
    _activeCityId = cityId;
    document.querySelectorAll('.city-btn').forEach(function(btn) {
        btn.style.background = 'transparent';
        btn.style.color = '#6b7280';
        btn.style.boxShadow = 'none';
    });
    var active = document.getElementById('city-btn-' + cityId);
    if (active) {
        active.style.background = '#ffffff';
        active.style.color = '#ea580c';
        active.style.boxShadow = '0 1px 3px rgba(0,0,0,0.12)';
    }
    if (latlng && window._map) {
        window._map.panTo(latlng);
        window._map.setZoom(13);
    }
    if (window._renderMarkers) window._renderMarkers();
}
// Active button mặc định
(function() {
    var btn = document.getElementById('city-btn-' + _activeCityId);
    if (btn) {
        btn.style.background = '#ffffff';
        btn.style.color = '#ea580c';
        btn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.12)';
    }
})();
</script>

{{-- MAP: wire:ignore — Livewire không được đụng vào đây --}}
<div wire:ignore>
    <div id="driver-map-wrapper">
        <div id="driver-map"></div>
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

        map = window._map = new google.maps.Map(document.getElementById('driver-map'), {
            center: { lat: {{ $cities[0]['lat'] ?? 10.0125 }}, lng: {{ $cities[0]['lng'] ?? 105.0809 }} },
            zoom: 13,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            styles: [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }],
        });
        infoWindow = new google.maps.InfoWindow();

        // Firebase real-time GPS — path: flashship_main/locations/driver_{id}
        rtdb.ref('flashship_main/locations').on('value', function(snapshot) {
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
        });

        document.getElementById('show-offline').addEventListener('change', renderMarkers);
        window._renderMarkers = renderMarkers;

        // Cache icon canvas theo driverId + trạng thái online
        var iconCache = {};

        function buildIcon(driverId, avatarUrl, isOnline, callback) {
            var key = driverId + (isOnline ? '_on' : '_off');
            if (iconCache[key]) { callback(iconCache[key]); return; }

            var S = 40, B = 3;
            var canvas = document.createElement('canvas');
            canvas.width = S; canvas.height = S;
            var ctx = canvas.getContext('2d');

            function draw(img) {
                ctx.clearRect(0, 0, S, S);
                // Viền màu
                ctx.beginPath();
                ctx.arc(S/2, S/2, S/2 - 1, 0, Math.PI*2);
                ctx.fillStyle = isOnline ? '#22c55e' : '#94a3b8';
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
                    ctx.fillStyle = isOnline ? '#22c55e' : '#9ca3af';
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
                iconCache[key] = icon;
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
            var cityFilter  = window._activeCityId || '';
            var showOffline = document.getElementById('show-offline').checked;

            var allIds = new Set(
                Object.keys(dbMeta).map(Number).concat(Object.keys(rtdbGps).map(Number))
            );

            Object.keys(markers).forEach(function(id) {
                if (!allIds.has(Number(id))) { markers[id].setMap(null); delete markers[id]; }
            });

            var visibleCount = 0, onlineCount = 0, offlineCount = 0;

            allIds.forEach(function(id) {
                var meta = dbMeta[id]  || {};
                var gps  = rtdbGps[id] || {};
                var lat  = gps.lat  != null ? gps.lat  : meta.lat;
                var lng  = gps.lng  != null ? gps.lng  : meta.lng;
                if (!lat || !lng) return;

                // Firebase is_online ưu tiên hơn DB (real-time hơn)
                var isOnline = gps.is_online !== undefined ? (gps.is_online === true) : (meta.is_online === true);
                var passCity   = !cityFilter || String(meta.city_id) === cityFilter;
                var passOnline = showOffline || isOnline;
                var visible    = passCity && passOnline;

                if (visible) { visibleCount++; if (isOnline) onlineCount++; else offlineCount++; }

                var fallbackIcon = {
                    path: google.maps.SymbolPath.CIRCLE,
                    fillColor: isOnline ? '#22c55e' : '#94a3b8',
                    fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2,
                    scale: isOnline ? 9 : 7,
                };

                if (markers[id]) {
                    markers[id].setPosition({ lat: lat, lng: lng });
                    markers[id].setVisible(visible);
                    markers[id].setZIndex(isOnline ? 10 : 1);
                    markers[id]._meta = meta;
                    markers[id]._gps  = gps;
                    // Cập nhật icon nếu online status thay đổi
                    buildIcon(id, meta.avatar, isOnline, function(icon) {
                        if (markers[id]) markers[id].setIcon(icon);
                    });
                } else {
                    var m = new google.maps.Marker({
                        position: { lat: lat, lng: lng },
                        map: map, icon: fallbackIcon, visible: visible,
                        title: meta.name || ('#' + id),
                        zIndex: isOnline ? 10 : 1,
                    });
                    m._meta = meta; m._gps = gps;
                    // Load avatar async và cập nhật icon
                    (function(marker, driverId, meta, isOnline) {
                        buildIcon(driverId, meta.avatar, isOnline, function(icon) {
                            marker.setIcon(icon);
                        });
                        marker.addListener('click', function() {
                            var ago = marker._gps && marker._gps.updated_at
                                ? Math.round((Date.now() / 1000 - marker._gps.updated_at) / 60) + ' phút trước'
                                : 'GPS từ DB';
                            var color = marker._meta.is_online ? '#22c55e' : '#94a3b8';
                            var avatarHtml = marker._meta.avatar
                                ? '<img src="' + marker._meta.avatar + '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid ' + color + ';margin-bottom:6px;">'
                                : '<div style="width:48px;height:48px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;margin-bottom:6px;font-size:20px;">👤</div>';
                            infoWindow.setContent(
                                '<div style="font-size:13px;line-height:1.8;min-width:180px;padding:4px 0;text-align:center">' +
                                avatarHtml +
                                '<strong style="font-size:14px;display:block">' + (marker._meta.name || '#' + driverId) + '</strong>' +
                                '<span style="background:' + color + ';color:#fff;padding:1px 10px;border-radius:999px;font-size:11px;display:inline-block;margin-bottom:4px">' +
                                (marker._meta.is_online ? '🟢 Online' : '⚫ Offline') + '</span><br>' +
                                (marker._meta.phone ? '<a href="https://zalo.me/' + marker._meta.phone + '" target="_blank" style="color:#0068ff;text-decoration:none;">📞 ' + marker._meta.phone + '</a><br>' : '<span style="color:#6b7280">📞 —</span><br>') +
                                '<span style="color:#6b7280">⭐ Điểm: ' + (marker._meta.driver_score != null ? marker._meta.driver_score : '—') + '</span><br>' +
                                '<span style="color:#6b7280">🕐 ' + ago + '</span></div>'
                            );
                            infoWindow.open(map, marker);
                        });
                    })(m, id, meta, isOnline);
                    markers[id] = m;
                }
            });

            var el = document.getElementById('map-counter');
            if (el) el.textContent = onlineCount + ' online  ·  ' + offlineCount + ' offline  ·  ' + visibleCount + ' tổng cộng';
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
