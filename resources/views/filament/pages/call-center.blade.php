<x-filament-panels::page>

@php
    $activeSvc = $this::services()[$serviceType];
    $isAdmin   = $this->isAdmin();
    $cities    = \Illuminate\Support\Facades\DB::table('cities')->orderBy('name')->get(['id', 'name', 'lat', 'lng']);
    $currentCityName = $cities->firstWhere('id', $data['city_id'] ?? null)?->name ?? '';
    $defaultCenter = ['lat' => 10.0452, 'lng' => 105.7009];
    if (!empty($data['city_id'])) {
        $cityRow = \Illuminate\Support\Facades\DB::table('cities')->where('id', $data['city_id'])->first(['lat', 'lng']);
        if ($cityRow && $cityRow->lat) $defaultCenter = ['lat' => (float)$cityRow->lat, 'lng' => (float)$cityRow->lng];
    }
@endphp

{{-- ══ BANNER THÀNH CÔNG ═══════════════════════════════════════════════════ --}}
@if ($resultOrderCode)
<div class="mb-3 overflow-hidden rounded-2xl shadow-lg" style="background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);">
    <div class="relative flex items-center gap-4 p-4">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-white/25">
            <x-heroicon-o-check-circle class="h-5 w-5 text-white" />
        </div>
        <div class="flex-1">
            <p class="text-xs font-semibold uppercase tracking-widest text-white/65">Đặt đơn thành công</p>
            <p class="text-xl font-black tracking-wider text-white">{{ $resultOrderCode }}</p>
        </div>
        @if ($resultFee !== null)
        <span class="rounded-xl bg-white/15 px-3 py-1.5 text-sm font-bold text-white">{{ number_format($resultFee) }}đ</span>
        @endif
        <button wire:click="clearResult" class="flex h-8 w-8 items-center justify-center rounded-full text-white/60 hover:bg-white/20">
            <x-heroicon-o-x-mark class="h-4 w-4" />
        </button>
    </div>
</div>
@endif

@if ($resultError)
<div class="mb-3 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-3">
    <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-500" />
    <p class="text-sm font-medium text-red-700">{{ $resultError }}</p>
</div>
@endif

{{-- ══ FULLSCREEN MAP LAYOUT ═══════════════════════════════════════════════ --}}
<style>
    .cc-wrapper { position:relative; height:calc(100vh - 80px); min-height:600px; border-radius:16px; overflow:hidden; border:1px solid #e5e7eb; }
    .cc-map { position:absolute; inset:0; width:100%; height:100%; }
    .cc-panel { position:absolute; top:16px; left:16px; z-index:10; width:380px; display:flex; flex-direction:column; gap:10px; max-height:calc(100vh - 120px); overflow-y:auto; }
    .cc-panel::-webkit-scrollbar { width:0; }
    @media (max-width: 768px) {
        .cc-wrapper { height:auto; min-height:auto; display:flex; flex-direction:column; border-radius:12px; }
        .cc-map { position:relative; height:45vh; min-height:280px; }
        .cc-panel { position:relative; top:0; left:0; width:100%; padding:12px; max-height:none; overflow-y:visible; }
    }
</style>
<div class="cc-wrapper">

    {{-- Map --}}
    <div id="cc-main-map" wire:ignore class="cc-map"></div>

    {{-- Panel --}}
    <div class="cc-panel">

        {{-- Khu vực --}}
        @if ($isAdmin)
        <div style="background:rgba(255,255,255,0.97); border-radius:14px; padding:10px 16px; box-shadow:0 4px 20px rgba(0,0,0,0.12); display:flex; align-items:center; gap:10px;">
            <svg style="width:18px; height:18px; color:#6b7280; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            <select wire:model.live="data.city_id" onchange="_onCityChange(this.value)"
                style="flex:1; border:none; outline:none; font-size:15px; font-weight:700; color:#111; background:transparent; cursor:pointer;">
                @foreach ($cities as $city)
                <option value="{{ $city->id }}" {{ ($data['city_id'] ?? '') == $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                @endforeach
            </select>
        </div>
        @else
        <div style="background:rgba(255,255,255,0.97); border-radius:14px; padding:10px 16px; box-shadow:0 4px 20px rgba(0,0,0,0.12); display:flex; align-items:center; gap:10px;">
            <svg style="width:18px; height:18px; color:#6b7280; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            <span style="font-size:15px; font-weight:700; color:#111;">{{ $currentCityName }}</span>
        </div>
        @endif

        {{-- Service tabs --}}
        <div style="background:rgba(255,255,255,0.97); border-radius:14px; padding:6px; box-shadow:0 4px 20px rgba(0,0,0,0.12); display:grid; grid-template-columns:1fr 1fr 1fr; gap:4px;">
            @foreach ($this::services() as $key => $svc)
            @php $active = $serviceType === $key; @endphp
            <button wire:click="selectService('{{ $key }}')" wire:key="svc-{{ $key }}"
                style="{{ $active ? 'background:'.$svc['color'].'; box-shadow:0 2px 8px '.$svc['color'].'50;' : '' }}"
                class="rounded-lg py-2 text-[14px] font-semibold text-center transition-all {{ $active ? 'text-white' : 'text-gray-500 hover:bg-gray-100' }}">
                {{ $svc['label'] }}
            </button>
            @endforeach
        </div>

        {{-- Địa chỉ --}}
        @php
            $pickupPlaceholder = match($serviceType) {
                'shopping' => 'Điểm mua hàng...',
                'topup'    => 'Địa chỉ nạp...',
                'bike', 'motor', 'car' => 'Điểm đón...',
                default    => 'Điểm lấy hàng...',
            };
            $deliveryPlaceholder = match($serviceType) {
                'bike', 'motor', 'car' => 'Điểm đến...',
                default => 'Điểm giao hàng...',
            };
            $needDelivery = $serviceType !== 'topup';
        @endphp
        <div style="background:rgba(255,255,255,0.97); border-radius:14px; padding:6px 0; box-shadow:0 4px 20px rgba(0,0,0,0.12);">
            <div style="display:flex; align-items:center; gap:10px; padding:8px 16px; {{ $needDelivery ? 'border-bottom:1px solid #f0f0f0;' : '' }}">
                <div style="width:10px; height:10px; border-radius:50%; background:#FF6B35; flex-shrink:0; box-shadow:0 0 0 3px #FF6B3520;"></div>
                <input id="cc-pickup-input" type="text" placeholder="{{ $pickupPlaceholder }}"
                    autocomplete="off" onfocus="_activeInput='pickup'"
                    style="flex:1; border:none; outline:none; font-size:14px; color:#111; background:transparent; height:32px;"
                    value="{{ $data['pickup_address'] ?? '' }}" />
            </div>
            @if ($needDelivery)
            <div style="display:flex; align-items:center; gap:10px; padding:8px 16px;">
                <div style="width:10px; height:10px; border-radius:50%; background:#22c55e; flex-shrink:0; box-shadow:0 0 0 3px #22c55e20;"></div>
                <input id="cc-delivery-input" type="text" placeholder="{{ $deliveryPlaceholder }}"
                    autocomplete="off" onfocus="_activeInput='delivery'"
                    style="flex:1; border:none; outline:none; font-size:14px; color:#111; background:transparent; height:32px;"
                    value="{{ $data['delivery_address'] ?? '' }}" />
            </div>
            @endif
        </div>

        {{-- Form --}}
        <form wire:submit="placeOrder">
        @php
            $inputStyle = 'width:100%; border:none; border-bottom:1px solid #f0f0f0; padding:10px 16px; font-size:14px; outline:none; background:transparent;';
            $textareaStyle = 'width:100%; border:none; border-bottom:1px solid #f0f0f0; padding:10px 16px; font-size:14px; outline:none; resize:none; background:transparent;';
        @endphp

        <div style="background:rgba(255,255,255,0.97); border-radius:14px; box-shadow:0 4px 20px rgba(0,0,0,0.12); overflow:hidden;">

            @if ($serviceType === 'delivery')
            <input type="text" placeholder="SĐT điểm lấy" wire:model="data.pickup_phone" style="{{ $inputStyle }}" />
            <input type="text" placeholder="SĐT điểm giao" wire:model="data.delivery_phone" style="{{ $inputStyle }}" />
            <input type="number" placeholder="Phí ship (đ)" wire:model="data.shipping_fee" style="{{ $inputStyle }}" />
            <textarea placeholder="Ghi chú..." wire:model="data.order_note" rows="2" style="{{ $textareaStyle }}"></textarea>
            @endif

            @if ($serviceType === 'shopping')
            <textarea placeholder="Mô tả hàng cần mua..." wire:model="data.shopping_note" rows="2" style="{{ $textareaStyle }}"></textarea>
            <input type="text" placeholder="SĐT khách" wire:model="data.delivery_phone" style="{{ $inputStyle }}" />
            <input type="number" placeholder="Phí ship (đ)" wire:model="data.shipping_fee" style="{{ $inputStyle }}" />
            @endif

            @if ($serviceType === 'topup')
            <input type="number" placeholder="Số tiền nạp (đ)" wire:model="data.cod_amount" style="{{ $inputStyle }}" />
            <input type="text" placeholder="SĐT khách" wire:model="data.pickup_phone" style="{{ $inputStyle }}" />
            <input type="number" placeholder="Phí ship (đ)" wire:model="data.shipping_fee" style="{{ $inputStyle }}" />
            <textarea placeholder="Ghi chú (tuỳ chọn)..." wire:model="data.order_note" rows="2" style="{{ $textareaStyle }}"></textarea>
            @endif

            @if (in_array($serviceType, ['bike', 'motor', 'car']))
            <input type="text" placeholder="SĐT khách" wire:model="data.pickup_phone" style="{{ $inputStyle }}" />
            <input type="number" placeholder="Phí ship (đ)" wire:model="data.shipping_fee" style="{{ $inputStyle }}" />
            <textarea placeholder="Ghi chú cho tài xế..." wire:model="data.order_note" rows="2" style="{{ $textareaStyle }}"></textarea>
            @endif

        </div>

        <button type="submit" wire:loading.attr="disabled"
            class="w-full rounded-xl py-3.5 text-[15px] font-bold text-white transition-all active:scale-[.98] mt-3"
            style="background:{{ $activeSvc['color'] }}; box-shadow:0 4px 16px {{ $activeSvc['color'] }}50;">
            <span wire:loading.remove wire:target="placeOrder">Đặt đơn ngay</span>
            <span wire:loading wire:target="placeOrder">Đang xử lý...</span>
        </button>
        </form>
    </div>

    @if (!$isAdmin)
    <input type="hidden" wire:model="data.city_id" />
    @endif
</div>

<x-filament-actions::modals />

<script>
let _mainMap, _geocoder, _pickupMarker, _deliveryMarker;
let _driverMarkers = {};
let _mapsReady = false;
let _pickupAc = null, _deliveryAc = null;
let _activeInput = 'pickup';
let _directionsRenderer = null;
let _radiusCircle = null;
let _allDriverLocations = {};

const _cityCenter = { lat: {{ $defaultCenter['lat'] }}, lng: {{ $defaultCenter['lng'] }} };
const _cityCoords = {
    @foreach ($cities as $city)
    {{ $city->id }}: { lat: {{ (float)$city->lat }}, lng: {{ (float)$city->lng }} },
    @endforeach
};

function _onCityChange(cityId) {
    const c = _cityCoords[cityId];
    if (!_mainMap || !c) return;
    _cityCenter.lat = c.lat;
    _cityCenter.lng = c.lng;
    _mainMap.panTo(c);
    _mainMap.setZoom(14);
    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
    if (_deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; }
    if (_radiusCircle) { _radiusCircle.setMap(null); _radiusCircle = null; }
    Object.values(_driverMarkers).forEach(m => m.setMap(null));
    _driverMarkers = {};
    _clearRoute();
    _activeInput = 'pickup';
    const pi = document.getElementById('cc-pickup-input');
    const di = document.getElementById('cc-delivery-input');
    if (pi) pi.value = '';
    if (di) di.value = '';
    _updateAutocompleteBounds();
}

// ── Init map ─────────────────────────────────────────────────────────────────
function _initMainMap() {
    _mainMap = new google.maps.Map(document.getElementById('cc-main-map'), {
        center: _cityCenter, zoom: 14,
        mapTypeControl: false, fullscreenControl: false,
        streetViewControl: false,
        zoomControl: true,
        zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER },
        gestureHandling: 'greedy',
        styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }],
    });
    _geocoder = new google.maps.Geocoder();

    // Click map → set based on which input is focused
    _mainMap.addListener('click', (e) => {
        const lat = e.latLng.lat(), lng = e.latLng.lng();
        _geocoder.geocode({ location: { lat, lng } }, (res, st) => {
            const addr = (st === 'OK' && res[0]) ? res[0].formatted_address : `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            const pickupInput = document.getElementById('cc-pickup-input');
            const deliveryInput = document.getElementById('cc-delivery-input');
            if (_activeInput === 'delivery' && deliveryInput) {
                @this.call('setDeliveryLocation', addr, lat, lng);
                deliveryInput.value = addr;
                _setDeliveryPin(lat, lng);
            } else if (pickupInput) {
                @this.call('setPickupLocation', addr, lat, lng);
                pickupInput.value = addr;
                _setPickupPin(lat, lng);
            }
        });
    });

    _initSearchAutocomplete();
    _initDriverMarkers();
}

// ── Autocomplete ─────────────────────────────────────────────────────────────
function _makeBounds() {
    return new google.maps.LatLngBounds(
        new google.maps.LatLng(_cityCenter.lat - 0.15, _cityCenter.lng - 0.15),
        new google.maps.LatLng(_cityCenter.lat + 0.15, _cityCenter.lng + 0.15)
    );
}

function _updateAutocompleteBounds() {
    const bounds = _makeBounds();
    if (_pickupAc) _pickupAc.setBounds(bounds);
    if (_deliveryAc) _deliveryAc.setBounds(bounds);
}

function _initSearchAutocomplete() {
    const bounds = _makeBounds();
    const pickupInput = document.getElementById('cc-pickup-input');
    const deliveryInput = document.getElementById('cc-delivery-input');

    if (pickupInput && !pickupInput._acBound) {
        pickupInput._acBound = true;
        _pickupAc = new google.maps.places.Autocomplete(pickupInput, {
            componentRestrictions: { country: 'vn' }, bounds, strictBounds: true,
        });
        _pickupAc.addListener('place_changed', () => {
            const p = _pickupAc.getPlace();
            if (!p.geometry?.location) return;
            const lat = p.geometry.location.lat(), lng = p.geometry.location.lng();
            @this.call('setPickupLocation', p.formatted_address || pickupInput.value, lat, lng);
            _setPickupPin(lat, lng);
        });
    }

    if (deliveryInput && !deliveryInput._acBound) {
        deliveryInput._acBound = true;
        _deliveryAc = new google.maps.places.Autocomplete(deliveryInput, {
            componentRestrictions: { country: 'vn' }, bounds, strictBounds: true,
        });
        _deliveryAc.addListener('place_changed', () => {
            const p = _deliveryAc.getPlace();
            if (!p.geometry?.location) return;
            const lat = p.geometry.location.lat(), lng = p.geometry.location.lng();
            @this.call('setDeliveryLocation', p.formatted_address || deliveryInput.value, lat, lng);
            _setDeliveryPin(lat, lng);
        });
    }
}

// ── Markers ──────────────────────────────────────────────────────────────────
function _pinIcon(color, label) {
    return {
        url: 'data:image/svg+xml,' + encodeURIComponent(`
            <svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 36 48">
                <path d="M18 0C8.06 0 0 8.06 0 18c0 13.5 18 30 18 30s18-16.5 18-30C36 8.06 27.94 0 18 0z" fill="${color}"/>
                <circle cx="18" cy="18" r="9" fill="white"/>
                <text x="18" y="22.5" text-anchor="middle" font-size="13" font-weight="bold" fill="${color}" font-family="Arial">${label}</text>
            </svg>`),
        scaledSize: new google.maps.Size(36, 48),
        anchor: new google.maps.Point(18, 48),
    };
}

function _setPickupPin(lat, lng) {
    if (_pickupMarker) _pickupMarker.setMap(null);
    _pickupMarker = new google.maps.Marker({
        position: { lat, lng }, map: _mainMap,
        icon: _pinIcon('#FF6B35', 'A'),
        title: 'Điểm lấy',
    });
    _mainMap.panTo({ lat, lng });
    _mainMap.setZoom(15);
    _drawRadiusCircle(lat, lng);
    _filterDriversByRadius(lat, lng);
    _fitBounds();
}

function _setDeliveryPin(lat, lng) {
    if (_deliveryMarker) _deliveryMarker.setMap(null);
    _deliveryMarker = new google.maps.Marker({
        position: { lat, lng }, map: _mainMap,
        icon: _pinIcon('#22c55e', 'B'),
        title: 'Điểm giao',
    });
    _fitBounds();
}

function _fitBounds() {
    if (_pickupMarker && _deliveryMarker) {
        const bounds = new google.maps.LatLngBounds();
        bounds.extend(_pickupMarker.getPosition());
        bounds.extend(_deliveryMarker.getPosition());
        _mainMap.fitBounds(bounds, 80);
        _drawRoute(_pickupMarker.getPosition(), _deliveryMarker.getPosition());
    }
}

function _drawRoute(origin, destination) {
    if (!_directionsRenderer) {
        _directionsRenderer = new google.maps.DirectionsRenderer({
            map: _mainMap,
            suppressMarkers: true,
            polylineOptions: {
                strokeColor: '#4F46E5',
                strokeOpacity: 0.8,
                strokeWeight: 5,
            },
        });
    }
    new google.maps.DirectionsService().route({
        origin, destination,
        travelMode: google.maps.TravelMode.DRIVING,
    }, (result, status) => {
        if (status === 'OK') {
            _directionsRenderer.setDirections(result);
        }
    });
}

function _clearRoute() {
    if (_directionsRenderer) {
        _directionsRenderer.setDirections({ routes: [] });
    }
}

// ── Radius circle ───────────────────────────────────────────────────────────
function _drawRadiusCircle(lat, lng) {
    if (_radiusCircle) _radiusCircle.setMap(null);
    _radiusCircle = new google.maps.Circle({
        center: { lat, lng },
        radius: 2000,
        map: _mainMap,
        fillColor: '#4F46E5',
        fillOpacity: 0.06,
        strokeColor: '#4F46E5',
        strokeOpacity: 0.3,
        strokeWeight: 2,
        clickable: false,
    });
}

function _distanceKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function _filterDriversByRadius(lat, lng) {
    Object.values(_driverMarkers).forEach(m => m.setMap(null));
    _driverMarkers = {};

    const driverIcon = {
        url: 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32"><circle cx="16" cy="16" r="14" fill="%234F46E5" stroke="white" stroke-width="2"/><text x="16" y="21" text-anchor="middle" fill="white" font-size="14">🏍</text></svg>'),
        scaledSize: new google.maps.Size(32, 32),
    };

    Object.entries(_allDriverLocations).forEach(([key, loc]) => {
        if (!loc.lat || !loc.lng) return;
        const dist = _distanceKm(lat, lng, loc.lat, loc.lng);
        if (dist > 2) return;
        const id = key.replace('driver_', '');
        _driverMarkers[id] = new google.maps.Marker({
            position: { lat: loc.lat, lng: loc.lng },
            map: _mainMap,
            icon: driverIcon,
            title: (loc.name || `Tài xế #${id}`) + ` (${dist.toFixed(1)}km)`,
        });
    });
}

// ── Driver markers (Firebase RTDB) ───────────────────────────────────────────
function _initDriverMarkers() {
    if (typeof firebase === 'undefined') return;

    const db = firebase.database();
    db.ref('flashship_main/locations').on('value', (snap) => {
        _allDriverLocations = snap.val() || {};

        if (_pickupMarker) {
            const pos = _pickupMarker.getPosition();
            _filterDriversByRadius(pos.lat(), pos.lng());
        }
    });
}

// ── Livewire events ──────────────────────────────────────────────────────────
window.addEventListener('openMapPicker', () => {});
window.addEventListener('openDeliveryMapPicker', () => {});


// Update markers khi Livewire state thay đổi
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            setTimeout(() => {
                const pLat = @this.pickupLat, pLng = @this.pickupLng;
                const dLat = @this.deliveryLat, dLng = @this.deliveryLng;
                if (pLat && pLng) {
                    _setPickupPin(pLat, pLng);
                } else {
                    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
                    if (_radiusCircle) { _radiusCircle.setMap(null); _radiusCircle = null; }
                    Object.values(_driverMarkers).forEach(m => m.setMap(null));
                    _driverMarkers = {};
                    const pi = document.getElementById('cc-pickup-input');
                    if (pi) pi.value = '';
                    _activeInput = 'pickup';
                }
                if (dLat && dLng) {
                    _setDeliveryPin(dLat, dLng);
                } else {
                    if (_deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; }
                    _clearRoute();
                    const di = document.getElementById('cc-delivery-input');
                    if (di) di.value = '';
                }
                if (_mapsReady) _initSearchAutocomplete();
            }, 150);
        });
    });
});

// ── Google Maps callback ─────────────────────────────────────────────────────
window.ccInitGoogleMaps = function () {
    _mapsReady = true;
    _initMainMap();

    // Restore pins if reorder
    const pLat = @this.pickupLat, pLng = @this.pickupLng;
    const dLat = @this.deliveryLat, dLng = @this.deliveryLng;
    if (pLat && pLng) _setPickupPin(pLat, pLng);
    if (dLat && dLng) _setDeliveryPin(dLat, dLng);
};
</script>

{{-- Firebase SDK --}}
<script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js"></script>
<script>
if (!firebase.apps.length) {
    firebase.initializeApp({
        apiKey: 'AIzaSyDSYWeYYO9oPK5I2HAkJ145eRp36WwnYaI',
        projectId: 'flashship-app',
        databaseURL: '{{ config("services.firebase.database_url") }}',
    });
}
</script>

{{-- Google Maps API --}}
<script
    src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&callback=ccInitGoogleMaps&loading=async"
    async defer
></script>

</x-filament-panels::page>
