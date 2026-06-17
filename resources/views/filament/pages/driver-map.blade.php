<x-filament-panels::page>

@pushOnce('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<style>
    #driver-map { height: 560px; width: 100%; border-radius: 1rem; z-index: 0; }
    .driver-popup { font-size: 13px; line-height: 1.6; min-width: 160px; }
    .driver-popup .badge-online  { background: #22c55e; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
    .driver-popup .badge-offline { background: #94a3b8; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
    .pulse-green { width:14px; height:14px; background:#22c55e; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 3px rgba(34,197,94,.35); }
    .dot-gray    { width:12px; height:12px; background:#94a3b8; border-radius:50%; border:2px solid #fff; }
</style>
@endPushOnce

{{-- STATS BAR --}}
<div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
    @foreach ($stats as $cityName => $s)
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-400 dark:text-gray-500">{{ $cityName }}</p>
        <div class="mt-1 flex items-end gap-3">
            <div>
                <p class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $s['online'] }}</p>
                <p class="text-[10px] text-gray-400">Online</p>
            </div>
            <div class="pb-0.5 text-gray-300 dark:text-gray-600">/</div>
            <div>
                <p class="text-2xl font-bold text-gray-400 dark:text-gray-500">{{ $s['offline'] }}</p>
                <p class="text-[10px] text-gray-400">Offline</p>
            </div>
            @if(($s['no_location'] ?? 0) > 0)
            <div class="pb-0.5 text-gray-300 dark:text-gray-600">/</div>
            <div title="Chưa có tọa độ GPS">
                <p class="text-2xl font-bold text-warning-500 dark:text-warning-400">{{ $s['no_location'] }}</p>
                <p class="text-[10px] text-gray-400">Chưa GPS</p>
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>

{{-- TOOLBAR --}}
<div class="mb-4 flex flex-wrap items-center gap-3">
    <select wire:model.live="cityId"
        class="rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
        <option value="">Tất cả khu vực</option>
        @foreach ($cities as $city)
            <option value="{{ $city['id'] }}">{{ $city['name'] }}</option>
        @endforeach
    </select>

    <div class="flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs text-gray-500 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <span class="inline-block h-2.5 w-2.5 rounded-full bg-success-500"></span> Online
        <span class="ml-2 inline-block h-2.5 w-2.5 rounded-full bg-gray-400"></span> Offline
    </div>

    <div class="ml-auto flex items-center gap-2 text-xs text-gray-400">
        <span wire:loading wire:target="loadDrivers" class="text-primary-500">Đang cập nhật...</span>
        <span wire:loading.remove wire:target="loadDrivers">
            {{ count(array_filter($drivers, fn($d) => $d['is_online'])) }} online
            · {{ count($drivers) }} có GPS
        </span>
        <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800">Tự động 10s</span>
    </div>
</div>

{{-- MAP --}}
<div class="overflow-hidden rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700"
    wire:poll.10000ms="loadDrivers"
    x-data="driverMap(@js($drivers))"
    x-init="initMap()"
    @drivers-updated.window="updateMarkers($event.detail.drivers)">
    <div id="driver-map"></div>
</div>

@pushOnce('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script>
function driverMap(initialDrivers) {
    return {
        map: null,
        cluster: null,
        markers: {},

        initMap() {
            this.map = L.map('driver-map', { zoomControl: true }).setView([10.0341, 105.7225], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);

            this.cluster = L.markerClusterGroup({ maxClusterRadius: 50 });
            this.map.addLayer(this.cluster);

            this.updateMarkers(initialDrivers);
        },

        makeIcon(isOnline) {
            const html = isOnline
                ? '<div class="pulse-green"></div>'
                : '<div class="dot-gray"></div>';
            return L.divIcon({ html, className: '', iconSize: [14, 14], iconAnchor: [7, 7] });
        },

        updateMarkers(drivers) {
            if (!this.map) return;

            const incoming = {};
            drivers.forEach(d => { incoming[d.id] = d; });

            // Xoá marker không còn trong danh sách
            Object.keys(this.markers).forEach(id => {
                if (!incoming[id]) {
                    this.cluster.removeLayer(this.markers[id]);
                    delete this.markers[id];
                }
            });

            // Thêm / cập nhật marker
            drivers.forEach(d => {
                const popup = `
                    <div class="driver-popup">
                        <strong>${d.name}</strong><br>
                        <span class="${d.is_online ? 'badge-online' : 'badge-offline'}">${d.is_online ? '🟢 Online' : '⚫ Offline'}</span><br>
                        📞 ${d.phone}<br>
                        ⭐ Điểm: ${d.driver_score}
                    </div>`;

                if (this.markers[d.id]) {
                    this.markers[d.id].setLatLng([d.lat, d.lng]);
                    this.markers[d.id].setIcon(this.makeIcon(d.is_online));
                    this.markers[d.id].setPopupContent(popup);
                } else {
                    const m = L.marker([d.lat, d.lng], { icon: this.makeIcon(d.is_online) })
                        .bindPopup(popup);
                    this.cluster.addLayer(m);
                    this.markers[d.id] = m;
                }
            });

            // Fit map nếu có markers và chưa có interaction
            if (drivers.length > 0 && !this._fitted) {
                const latlngs = drivers.map(d => [d.lat, d.lng]);
                this.map.fitBounds(L.latLngBounds(latlngs), { padding: [40, 40], maxZoom: 14 });
                this._fitted = true;
            }
        },
    };
}
</script>
@endPushOnce

</x-filament-panels::page>
