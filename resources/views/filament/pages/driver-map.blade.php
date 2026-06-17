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
<div class="overflow-hidden rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700">
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
    const markers    = {};   // id → google.maps.Marker
    let   allDrivers = {};   // id → driver data từ RTDB

    // ── Firebase ───────────────────────────────────────────────────────────────
    firebase.initializeApp(FIREBASE_CONFIG);
    const rtdb = firebase.database();

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

        // Firebase real-time listener
        rtdb.ref('drivers').on('value', snapshot => {
            allDrivers = snapshot.val() || {};
            renderMarkers();
        });

        document.getElementById('city-filter').addEventListener('change', renderMarkers);
        document.getElementById('show-offline').addEventListener('change', renderMarkers);
    };

    function renderMarkers() {
        if (!map) return;

        const cityFilter  = document.getElementById('city-filter').value;
        const showOffline = document.getElementById('show-offline').checked;

        let visibleCount = 0, onlineCount = 0;

        // Xóa marker không còn trong data
        Object.keys(markers).forEach(id => {
            if (!allDrivers[id]) { markers[id].setMap(null); delete markers[id]; }
        });

        Object.entries(allDrivers).forEach(([id, d]) => {
            if (!d.lat || !d.lng) return;

            const passCity   = !cityFilter || String(d.city_id) === cityFilter;
            const passOnline = showOffline || d.is_online;
            const visible    = passCity && passOnline;

            if (visible) { visibleCount++; if (d.is_online) onlineCount++; }

            if (markers[id]) {
                markers[id].setPosition({ lat: d.lat, lng: d.lng });
                markers[id].setIcon(makeIcon(d.is_online));
                markers[id].setVisible(visible);
                markers[id]._d = d;
            } else {
                const m = new google.maps.Marker({
                    position: { lat: d.lat, lng: d.lng },
                    map,
                    icon:    makeIcon(d.is_online),
                    visible: visible,
                    title:   d.name || `#${id}`,
                });
                m._d = d;

                m.addListener('click', () => {
                    const ago = m._d.updated_at
                        ? Math.round((Date.now() / 1000 - m._d.updated_at) / 60)
                        : null;
                    infoWindow.setContent(`
                        <div style="font-size:13px;line-height:1.8;min-width:170px;padding:2px 0">
                            <strong style="font-size:14px">${m._d.name || '#' + id}</strong><br>
                            <span style="background:${m._d.is_online ? '#22c55e' : '#94a3b8'};color:#fff;padding:1px 10px;border-radius:999px;font-size:11px;display:inline-block;margin-bottom:4px">
                                ${m._d.is_online ? '🟢 Online' : '⚫ Offline'}
                            </span><br>
                            📞 ${m._d.phone || '—'}<br>
                            ⭐ Điểm: ${m._d.driver_score ?? '—'}<br>
                            🕐 ${ago !== null ? ago + ' phút trước' : '—'}
                        </div>`);
                    infoWindow.open(map, m);
                });

                markers[id] = m;
            }
        });

        document.getElementById('map-counter').textContent =
            `${onlineCount} online · ${visibleCount} hiển thị`;
    }

    function makeIcon(isOnline) {
        return {
            path:          google.maps.SymbolPath.CIRCLE,
            fillColor:     isOnline ? '#22c55e' : '#94a3b8',
            fillOpacity:   1,
            strokeColor:   '#fff',
            strokeWeight:  2,
            scale:         isOnline ? 9 : 7,
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
