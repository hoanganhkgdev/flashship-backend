<x-filament-panels::page>

@pushOnce('styles')
<style>
    #driver-map { height: 580px; width: 100%; border-radius: 1rem; }
</style>
@endPushOnce

{{-- STATS BAR --}}
<div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-4" wire:poll.30000ms="loadStats">
    @foreach ($stats as $cityName => $s)
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-400">{{ $cityName }}</p>
        <div class="mt-1 flex items-end gap-3">
            <div>
                <p class="text-2xl font-bold text-success-600">{{ $s['online'] }}</p>
                <p class="text-[10px] text-gray-400">Online</p>
            </div>
            <div class="pb-1 text-gray-300">/</div>
            <div>
                <p class="text-2xl font-bold text-gray-400">{{ $s['offline'] }}</p>
                <p class="text-[10px] text-gray-400">Offline</p>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- TOOLBAR --}}
<div class="mb-3 flex flex-wrap items-center gap-3">
    <select id="city-filter"
        class="rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
        <option value="">Tất cả khu vực</option>
        @foreach ($cities as $city)
            <option value="{{ $city['id'] }}">{{ $city['name'] }}</option>
        @endforeach
    </select>

    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <input type="checkbox" id="show-offline" checked class="rounded border-gray-300">
        Hiện tài xế offline
    </label>

    <div class="flex items-center gap-3 text-xs text-gray-500">
        <span class="flex items-center gap-1.5">
            <span class="inline-block h-3 w-3 rounded-full bg-green-500"></span> Online
        </span>
        <span class="flex items-center gap-1.5">
            <span class="inline-block h-3 w-3 rounded-full bg-gray-400"></span> Offline
        </span>
    </div>

    <div id="map-counter" class="ml-auto rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500 dark:bg-gray-800">
        Đang kết nối...
    </div>

    <div class="flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
        <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-green-500"></span>
        Real-time · Firebase
    </div>
</div>

{{-- MAP --}}
<div class="overflow-hidden rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700"
    @meta-updated.window="updateMeta($event.detail.meta)">
    <div id="driver-map"></div>
</div>

@pushOnce('scripts')
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-database-compat.js"></script>
<script>
(function () {
    const FIREBASE_CONFIG = @js($this->getFirebaseConfig());
    const MAPS_KEY        = @js($this->getGoogleMapsKey());

    // ── State ──────────────────────────────────────────────────────────────────
    let map, infoWindow;
    const markers = {};             // driverId (int) → google.maps.Marker
    let   rtdbGps = {};             // driverId → { lat, lng, updated_at }  ← từ Firebase
    let   dbMeta  = @js($driversMeta); // driverId → { name, phone, city_id, is_online, score, lat, lng }

    // ── Firebase ───────────────────────────────────────────────────────────────
    firebase.initializeApp(FIREBASE_CONFIG);
    const rtdb = firebase.database();

    // ── Livewire: cập nhật metadata khi poll 30s ───────────────────────────────
    window.updateMeta = function(meta) {
        dbMeta = meta;
        renderMarkers();
    };

    // ── Google Maps callback ───────────────────────────────────────────────────
    window.initMap = function () {
        map = new google.maps.Map(document.getElementById('driver-map'), {
            center: { lat: 10.0341, lng: 105.7225 },
            zoom: 13,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            styles: [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }],
        });
        infoWindow = new google.maps.InfoWindow();

        // Lắng nghe real-time GPS từ Firebase
        // Flutter ghi vào: flashship_main/locations/driver_{id}
        rtdb.ref('flashship_main/locations').on('value', snapshot => {
            const raw = snapshot.val() || {};
            const newGps = {};
            Object.entries(raw).forEach(([key, val]) => {
                // key = "driver_42" → id = 42
                const id = parseInt(key.replace('driver_', ''), 10);
                if (!isNaN(id) && val.lat && val.lng) {
                    newGps[id] = { lat: val.lat, lng: val.lng, updated_at: val.updated_at };
                }
            });
            rtdbGps = newGps;
            renderMarkers();
        });

        document.getElementById('city-filter').addEventListener('change', renderMarkers);
        document.getElementById('show-offline').addEventListener('change', renderMarkers);
    };

    function renderMarkers() {
        if (!map) return;

        const cityFilter  = document.getElementById('city-filter').value;
        const showOffline = document.getElementById('show-offline').checked;

        // Tập hợp tất cả driver IDs: từ DB meta + Firebase GPS
        const allIds = new Set([
            ...Object.keys(dbMeta).map(Number),
            ...Object.keys(rtdbGps).map(Number),
        ]);

        let visibleCount = 0, onlineCount = 0;

        // Xóa marker của driver không còn tồn tại
        Object.keys(markers).forEach(id => {
            if (!allIds.has(Number(id))) { markers[id].setMap(null); delete markers[id]; }
        });

        allIds.forEach(id => {
            const meta = dbMeta[id]  || {};
            const gps  = rtdbGps[id] || {};

            // Ưu tiên tọa độ từ Firebase (real-time), fallback DB
            const lat = gps.lat ?? meta.lat;
            const lng = gps.lng ?? meta.lng;
            if (!lat || !lng) return;

            const isOnline  = meta.is_online ?? false;
            const cityId    = meta.city_id ?? null;

            const passCity   = !cityFilter || String(cityId) === cityFilter;
            const passOnline = showOffline || isOnline;
            const visible    = passCity && passOnline;

            if (visible) { visibleCount++; if (isOnline) onlineCount++; }

            const icon = makeIcon(isOnline);

            if (markers[id]) {
                markers[id].setPosition({ lat, lng });
                markers[id].setIcon(icon);
                markers[id].setVisible(visible);
                markers[id]._meta = meta;
                markers[id]._gps  = gps;
            } else {
                const m = new google.maps.Marker({
                    position: { lat, lng },
                    map,
                    icon,
                    visible,
                    title: meta.name || `Tài xế #${id}`,
                    zIndex: isOnline ? 10 : 1,
                });
                m._meta = meta;
                m._gps  = gps;

                m.addListener('click', () => {
                    const ago = m._gps?.updated_at
                        ? Math.round((Date.now() - m._gps.updated_at) / 60000) + ' phút trước'
                        : (m._meta.lat ? 'GPS từ DB' : '—');
                    const color = m._meta.is_online ? '#22c55e' : '#94a3b8';
                    infoWindow.setContent(`
                        <div style="font-size:13px;line-height:1.8;min-width:170px;padding:2px 0">
                            <strong style="font-size:14px">${m._meta.name || '#' + id}</strong><br>
                            <span style="background:${color};color:#fff;padding:1px 10px;border-radius:999px;font-size:11px;display:inline-block;margin-bottom:4px">
                                ${m._meta.is_online ? '🟢 Online' : '⚫ Offline'}
                            </span><br>
                            📞 ${m._meta.phone || '—'}<br>
                            ⭐ Điểm: ${m._meta.driver_score ?? '—'}<br>
                            🕐 GPS: ${ago}
                        </div>`);
                    infoWindow.open(map, m);
                });

                markers[id] = m;
            }
        });

        const counter = document.getElementById('map-counter');
        if (counter) counter.textContent = `${onlineCount} online · ${visibleCount} hiển thị`;
    }

    function makeIcon(isOnline) {
        return {
            path:         google.maps.SymbolPath.CIRCLE,
            fillColor:    isOnline ? '#22c55e' : '#94a3b8',
            fillOpacity:  1,
            strokeColor:  '#fff',
            strokeWeight: 2,
            scale:        isOnline ? 9 : 7,
        };
    }

    // Load Google Maps SDK
    const s = document.createElement('script');
    s.src   = `https://maps.googleapis.com/maps/api/js?key=${MAPS_KEY}&callback=initMap&loading=async`;
    s.async = true;
    document.head.appendChild(s);
})();
</script>
@endPushOnce

</x-filament-panels::page>
