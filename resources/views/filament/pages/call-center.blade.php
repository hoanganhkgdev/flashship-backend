<x-filament-panels::page>

@php
    $ready      = ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'];
    $activeSvc  = $this::services()[$serviceType];
@endphp

{{-- ══ TABS ══════════════════════════════════════════════════════════════════ --}}
<div class="mb-5">
    <div class="flex flex-wrap gap-2">
        @foreach ($this::services() as $key => $svc)
        @php $active = $serviceType === $key; @endphp
        <button
            wire:click="selectService('{{ $key }}')"
            wire:key="svc-{{ $key }}"
            style="{{ $active
                ? 'background:'.$svc['color'].';box-shadow:0 2px 10px '.$svc['color'].'55;'
                : 'background:#fff;border:1.5px solid #e5e7eb;' }}"
            class="flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-2 text-xs font-semibold transition-all duration-200 focus:outline-none active:scale-95 {{ $active ? 'text-white' : 'text-gray-500 dark:text-gray-400 dark:bg-gray-800 dark:border-gray-700' }}"
        >
            <x-dynamic-component :component="$svc['icon']" class="h-3.5 w-3.5 flex-shrink-0" />
            {{ $svc['label'] }}
        </button>
        @endforeach
    </div>
</div>

{{-- ══ BANNER THÀNH CÔNG ═══════════════════════════════════════════════════ --}}
@if ($resultOrderCode)
<div class="mb-5 overflow-hidden rounded-2xl shadow-lg" style="background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);">
    <div class="relative flex flex-col sm:flex-row sm:items-center gap-4 p-5">
        <div class="flex items-center gap-4 flex-1">
            <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-white/25">
                <x-heroicon-o-check-circle class="h-6 w-6 text-white" />
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-white/65">Đặt đơn thành công</p>
                <p class="text-2xl font-black tracking-wider text-white">{{ $resultOrderCode }}</p>
            </div>
        </div>
        @if ($resultFee !== null || $resultDistance)
        <div class="flex items-center gap-5 rounded-xl bg-white/15 px-4 py-2 sm:ml-0 ml-14">
            @if ($resultDistance)
            <div>
                <p class="text-[10px] font-semibold uppercase text-white/55">Khoảng cách</p>
                <p class="text-base font-bold text-white">{{ $resultDistance }}</p>
            </div>
            @endif
            @if ($resultFee !== null)
            <div class="border-l border-white/25 pl-5">
                <p class="text-[10px] font-semibold uppercase text-white/55">Phí ship</p>
                <p class="text-base font-bold text-white">{{ number_format($resultFee) }}đ</p>
            </div>
            @endif
        </div>
        @endif
        <button wire:click="clearResult"
            class="absolute right-4 top-4 sm:static flex h-8 w-8 items-center justify-center rounded-full text-white/60 hover:bg-white/20 hover:text-white transition">
            <x-heroicon-o-x-mark class="h-4 w-4" />
        </button>
    </div>
</div>
@endif

{{-- ══ BANNER LỖI ══════════════════════════════════════════════════════════ --}}
@if ($resultError)
<div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/40">
    <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-500" />
    <p class="text-sm font-medium text-red-700 dark:text-red-300">{{ $resultError }}</p>
</div>
@endif

{{-- ══ NỘI DUNG TAB ════════════════════════════════════════════════════════ --}}
@if (in_array($serviceType, $ready))

<form wire:submit="placeOrder">
        {{ $this->form }}

        {{-- ── Bản đồ preview ── --}}
        <div class="mt-4 rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700"
             id="cc-preview-wrap"
             style="height: 200px; {{ $pickupLat ? '' : 'display:none;' }}">
            <div id="cc-preview-map" style="width:100%; height:100%;"></div>
        </div>

        {{-- ── Action bar ── --}}
        <div class="mt-5 space-y-3">

            {{-- Đặt đơn — full width, lớn, màu theo dịch vụ --}}
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
                class="w-full rounded-2xl py-4 text-[15px] font-bold text-white transition-all duration-150 active:scale-[.98] focus:outline-none"
                style="background:linear-gradient(135deg, {{ $activeSvc['color'] }} 0%, {{ $activeSvc['color'] }}cc 100%); box-shadow:0 6px 22px {{ $activeSvc['color'] }}40;"
            >
                <span wire:loading.remove wire:target="placeOrder" class="flex items-center justify-center gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                    Đặt đơn ngay
                </span>
                <span wire:loading wire:target="placeOrder" class="flex items-center justify-center gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Đang xử lý...
                </span>
            </button>

        </div>
    </form>

@else

    <div class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 py-20 dark:border-gray-700 dark:bg-gray-800/30">
        <x-dynamic-component :component="$activeSvc['icon']" class="mb-3 h-12 w-12 text-gray-300 dark:text-gray-600" />
        <p class="text-base font-semibold text-gray-700 dark:text-gray-200">{{ $activeSvc['label'] }}</p>
        <p class="mt-1.5 text-sm text-gray-400">Dịch vụ này đang được phát triển</p>
    </div>

@endif

<x-filament-actions::modals />

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- Center theo thành phố — element này NẰM TRONG Livewire, được morph    --}}
{{-- Modal bị move ra body nên không được Livewire cập nhật                --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@php
    $defaultCenter = ['lat' => 10.7769, 'lng' => 106.7009]; // HCM fallback
    if (!empty($data['city_id'])) {
        $cityRow = \Illuminate\Support\Facades\DB::table('cities')
            ->where('id', $data['city_id'])
            ->first(['lat', 'lng']);
        if ($cityRow && $cityRow->lat) {
            $defaultCenter = ['lat' => (float)$cityRow->lat, 'lng' => (float)$cityRow->lng];
        }
    }
@endphp
<div
    id="cc-city-anchor"
    data-lat="{{ $defaultCenter['lat'] }}"
    data-lng="{{ $defaultCenter['lng'] }}"
    style="display:none;"
></div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- MAP PICKER — fullscreen, pin cố định giữa, kéo map để chọn           --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div
    id="cc-map-modal"
    style="display:none; position:fixed; inset:0; z-index:99999; background:#000;"
>

    {{-- Map (toàn màn hình) --}}
    <div id="cc-picker-map" style="position:absolute; inset:0; width:100%; height:100%;"></div>

    {{-- Pin cố định ở giữa (kéo map, pin không di chuyển) --}}
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -100%); z-index:10; pointer-events:none;">
        <svg width="36" height="48" viewBox="0 0 36 48" fill="none">
            <path d="M18 0C8.06 0 0 8.06 0 18C0 31.5 18 48 18 48C18 48 36 31.5 36 18C36 8.06 27.94 0 18 0Z" fill="#E8720C"/>
            <circle cx="18" cy="18" r="8" fill="white"/>
            <circle cx="18" cy="18" r="4" fill="#E8720C"/>
        </svg>
        {{-- Bóng đổ --}}
        <div style="width:12px; height:4px; background:rgba(0,0,0,0.25); border-radius:50%; margin:0 auto; margin-top:-2px;"></div>
    </div>

    {{-- Header nổi --}}
    <div style="position:absolute; top:0; left:0; right:0; z-index:20; padding:12px 16px; display:flex; align-items:center; gap:12px; background:linear-gradient(to bottom, rgba(0,0,0,0.55) 0%, transparent 100%);">
        <button
            onclick="ccCloseMapModal()"
            style="width:38px; height:38px; border-radius:50%; border:none; background:rgba(255,255,255,0.95); cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.2); flex-shrink:0;"
        >
            <svg width="18" height="18" fill="none" stroke="#374151" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
        <span id="cc-modal-title" style="font-size:15px; font-weight:700; color:#fff; text-shadow:0 1px 3px rgba(0,0,0,0.4);">Chọn điểm lấy hàng</span>
    </div>

    {{-- Search bar nổi --}}
    <div style="position:absolute; top:66px; left:16px; right:16px; z-index:20;">
        <div style="display:flex; align-items:center; gap:10px; background:#fff; border-radius:14px; padding:10px 14px; box-shadow:0 4px 20px rgba(0,0,0,0.18);">
            <svg width="18" height="18" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;">
                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            </svg>
            <input
                id="cc-modal-search"
                type="text"
                placeholder="Tìm địa chỉ..."
                autocomplete="off"
                style="flex:1; border:none; outline:none; font-size:14px; color:#111827; background:transparent;"
            />
        </div>
    </div>

    {{-- Nút định vị GPS --}}
    <div style="position:absolute; right:16px; bottom:180px; z-index:20;">
        <button
            onclick="ccGotoMyLocation()"
            style="width:44px; height:44px; border-radius:50%; border:none; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 12px rgba(0,0,0,0.2);"
            title="Vị trí của tôi"
        >
            <svg width="20" height="20" fill="none" stroke="#E8720C" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="9" stroke-dasharray="2 2"/>
            </svg>
        </button>
    </div>

    {{-- Bottom sheet --}}
    <div style="position:absolute; bottom:0; left:0; right:0; z-index:20; background:#fff; border-radius:20px 20px 0 0; padding:8px 20px 28px; box-shadow:0 -4px 24px rgba(0,0,0,0.12);">
        {{-- Drag handle --}}
        <div style="width:36px; height:4px; background:#e5e7eb; border-radius:2px; margin:0 auto 16px;"></div>

        {{-- Địa chỉ đang chọn --}}
        <div style="display:flex; align-items:flex-start; gap:10px; margin-bottom:16px; min-height:40px;">
            <div style="width:36px; height:36px; border-radius:50%; background:#fff4ed; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px;">
                <svg width="16" height="16" fill="#E8720C" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div style="flex:1;">
                <p style="font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Điểm lấy hàng</p>
                <p id="cc-picker-addr-text" style="font-size:14px; font-weight:500; color:#111827; line-height:1.4;">Đang xác định vị trí...</p>
            </div>
        </div>

        {{-- Confirm button --}}
        <button
            onclick="ccConfirmMapSelection()"
            style="width:100%; background:#E8720C; color:#fff; border:none; border-radius:14px; padding:15px; font-size:15px; font-weight:700; cursor:pointer; letter-spacing:.02em; transition:opacity .15s; box-shadow:0 4px 14px rgba(232,114,12,0.35);"
            onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'"
        >
            Xác nhận địa chỉ này
        </button>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- GOOGLE MAPS + AUTOCOMPLETE JS                                          --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<script>
// ── State ─────────────────────────────────────────────────────────────────────
let _ccMap, _ccGeocoder, _ccGeocodeTimer;
let _ccAddr = '', _ccLat = 0, _ccLng = 0;
let _ccMapsReady = false;
let _ccMapMode = 'pickup'; // 'pickup' | 'delivery'

// ── Open / Close ───────────────────────────────────────────────────────────────
function _ccCityCenter() {
    // Đọc từ #cc-city-anchor — element này NẰM TRONG Livewire component
    // nên được Livewire morph cập nhật mỗi khi user đổi khu vực.
    // (Modal bị move ra body → Livewire không thể cập nhật attribute của nó)
    const anchor = document.getElementById('cc-city-anchor');
    return {
        lat: parseFloat(anchor?.dataset?.lat || '10.7769'),
        lng: parseFloat(anchor?.dataset?.lng || '106.7009'),
    };
}

window.ccOpenMapModal = function (mode = 'pickup') {
    _ccMapMode = mode;
    _ccAddr    = '';

    const modal = document.getElementById('cc-map-modal');
    const title = modal.querySelector('#cc-modal-title');
    if (title) title.textContent = mode === 'delivery' ? 'Chọn điểm giao hàng' : 'Chọn điểm lấy hàng';

    const addrEl = document.getElementById('cc-picker-addr-text');
    if (addrEl) addrEl.textContent = 'Đang xác định vị trí...';

    modal.style.display = 'block';

    // Luôn tạo lại map mỗi lần mở — tránh tile bị đen khi reopen sau khi đã close
    _ccMap = null;
    const mapDiv = document.getElementById('cc-picker-map');
    if (mapDiv) mapDiv.innerHTML = '';

    setTimeout(() => {
        if (!_ccMapsReady) return;
        _ccInitMap(_ccCityCenter());
    }, 150);
};

window.ccCloseMapModal = function () {
    document.getElementById('cc-map-modal').style.display = 'none';
};

// ── Init fullscreen map ────────────────────────────────────────────────────────
function _ccInitMap(center) {
    _ccLat = center.lat;
    _ccLng = center.lng;

    _ccMap = new google.maps.Map(document.getElementById('cc-picker-map'), {
        center: center,
        zoom: 15,
        mapTypeControl: false,
        fullscreenControl: false,
        streetViewControl: false,
        zoomControl: true,
        zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER },
        gestureHandling: 'greedy',
    });

    _ccGeocoder = new google.maps.Geocoder();

    // Khi map idle → reverse geocode tâm bản đồ (pin cố định ở giữa màn hình)
    _ccMap.addListener('idle', () => {
        const c = _ccMap.getCenter();
        _ccLat = c.lat();
        _ccLng = c.lng();

        const el = document.getElementById('cc-picker-addr-text');
        if (el) el.textContent = 'Đang xác định địa chỉ...';

        clearTimeout(_ccGeocodeTimer);
        _ccGeocodeTimer = setTimeout(() => {
            _ccGeocoder.geocode({ location: { lat: _ccLat, lng: _ccLng } }, (res, st) => {
                _ccAddr = (st === 'OK' && res[0]) ? res[0].formatted_address : `${_ccLat.toFixed(5)}, ${_ccLng.toFixed(5)}`;
                if (el) el.textContent = _ccAddr;
            });
        }, 400);
    });

    // Autocomplete trong modal search
    const searchEl = document.getElementById('cc-modal-search');
    if (searchEl) {
        const ac = new google.maps.places.Autocomplete(searchEl, { componentRestrictions: { country: 'vn' } });
        ac.bindTo('bounds', _ccMap);
        ac.addListener('place_changed', () => {
            const p = ac.getPlace();
            if (!p.geometry?.location) return;
            _ccMap.panTo(p.geometry.location);
            _ccMap.setZoom(17);
            searchEl.blur();
        });
    }
}

// ── GPS: về vị trí hiện tại ───────────────────────────────────────────────────
window.ccGotoMyLocation = function () {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(pos => {
        if (_ccMap) _ccMap.panTo({ lat: pos.coords.latitude, lng: pos.coords.longitude });
    });
};

// ── Xác nhận ─────────────────────────────────────────────────────────────────
window.ccConfirmMapSelection = function () {
    if (!_ccAddr || _ccAddr === 'Đang xác định địa chỉ...') return;
    if (_ccMapMode === 'delivery') {
        @this.call('setDeliveryLocation', _ccAddr, _ccLat, _ccLng);
    } else {
        @this.call('setPickupLocation', _ccAddr, _ccLat, _ccLng);
    }
    ccCloseMapModal();
};

// ── Autocomplete ──────────────────────────────────────────────────────────────
const _acElements = new WeakSet();
function _initAutocomplete(inputId, livewireMethod) {
    const input = document.getElementById(inputId);
    if (!input || _acElements.has(input) || !_ccMapsReady) return;
    _acElements.add(input);
    const center = _ccCityCenter();
    const bounds = new google.maps.LatLngBounds(
        new google.maps.LatLng(center.lat - 0.15, center.lng - 0.15),
        new google.maps.LatLng(center.lat + 0.15, center.lng + 0.15)
    );
    const ac = new google.maps.places.Autocomplete(input, {
        componentRestrictions: { country: 'vn' },
        bounds: bounds,
        strictBounds: true,
    });
    ac.addListener('place_changed', () => {
        const p = ac.getPlace();
        if (!p.geometry?.location) return;
        @this.call(livewireMethod,
            p.formatted_address || input.value,
            p.geometry.location.lat(),
            p.geometry.location.lng()
        );
    });
}

function _initAllAutocomplete() {
    _initAutocomplete('cc-pickup-addr',   'setPickupLocation');
    _initAutocomplete('cc-delivery-addr', 'setDeliveryLocation');
}

// ── Preview map ──────────────────────────────────────────────────────────────
let _previewMap, _pickupMarker, _deliveryMarker;

function _updatePreviewMap() {
    const el = document.getElementById('cc-preview-map');
    const wrap = document.getElementById('cc-preview-wrap');
    if (!el || !_ccMapsReady) return;

    const pickupLat = @this.pickupLat;
    const pickupLng = @this.pickupLng;
    const deliveryLat = @this.deliveryLat;
    const deliveryLng = @this.deliveryLng;

    if (!pickupLat) { if (wrap) wrap.style.display = 'none'; return; }
    if (wrap) wrap.style.display = '';

    const center = { lat: pickupLat, lng: pickupLng };

    if (!_previewMap || !el.hasChildNodes()) {
        _previewMap = new google.maps.Map(el, {
            center: center, zoom: 14,
            mapTypeControl: false, fullscreenControl: false,
            streetViewControl: false, zoomControl: false,
            gestureHandling: 'cooperative',
        });
    } else {
        _previewMap.setCenter(center);
        google.maps.event.trigger(_previewMap, 'resize');
    }

    if (_pickupMarker) _pickupMarker.setMap(null);
    _pickupMarker = new google.maps.Marker({
        position: center, map: _previewMap,
        label: { text: 'A', color: '#fff', fontWeight: 'bold' },
        title: 'Điểm lấy',
    });

    if (_deliveryMarker) _deliveryMarker.setMap(null);
    if (deliveryLat && deliveryLng) {
        _deliveryMarker = new google.maps.Marker({
            position: { lat: deliveryLat, lng: deliveryLng }, map: _previewMap,
            label: { text: 'B', color: '#fff', fontWeight: 'bold' },
            title: 'Điểm giao',
        });
        const bounds = new google.maps.LatLngBounds();
        bounds.extend(center);
        bounds.extend({ lat: deliveryLat, lng: deliveryLng });
        _previewMap.fitBounds(bounds, 40);
    }
}

// ── Google Maps callback ──────────────────────────────────────────────────────
window.ccInitGoogleMaps = function () {
    _ccMapsReady = true;
    const modal = document.getElementById('cc-map-modal');
    if (modal && modal.parentElement !== document.body) document.body.appendChild(modal);
    _initAllAutocomplete();
    _updatePreviewMap();
};

// ── Filament suffixAction events ──────────────────────────────────────────────
window.addEventListener('openMapPicker',         () => ccOpenMapModal('pickup'));
window.addEventListener('openDeliveryMapPicker', () => ccOpenMapModal('delivery'));

// ── Re-init autocomplete sau Livewire update ───────────────────────────────────
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => { setTimeout(_initAllAutocomplete, 60); setTimeout(_updatePreviewMap, 300); });
    });
});
</script>

{{-- Google Maps API --}}
<script
    src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&callback=ccInitGoogleMaps&loading=async"
    async defer
></script>

</x-filament-panels::page>
