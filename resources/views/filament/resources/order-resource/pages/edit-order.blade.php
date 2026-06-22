<x-filament-panels::page>

    {{ $this->form }}

    <x-filament-panels::form.actions
        :actions="$this->getCachedFormActions()"
        :full-width="$this->hasFullWidthFormActions()"
    />

@php
    $order = $this->record;
    $cityCenter = ['lat' => 10.0452, 'lng' => 105.7469];
    if ($order->city) {
        $cityCenter = ['lat' => (float) $order->city->lat, 'lng' => (float) $order->city->lng];
    }
@endphp
<div
    id="edit-order-data"
    data-pickup-lat="{{ $order->pickup_lat ?? '' }}"
    data-pickup-lng="{{ $order->pickup_lng ?? '' }}"
    data-city-lat="{{ $cityCenter['lat'] }}"
    data-city-lng="{{ $cityCenter['lng'] }}"
    style="display:none;"
></div>

{{-- ══ MAP PICKER MODAL ══════════════════════════════════════════════════ --}}
<div
    id="edit-map-modal"
    style="display:none; position:fixed; inset:0; z-index:99999; background:#000;"
>
    <div id="edit-picker-map" style="position:absolute; inset:0; width:100%; height:100%;"></div>

    {{-- Pin cố định giữa --}}
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -100%); z-index:10; pointer-events:none;">
        <svg width="36" height="48" viewBox="0 0 36 48" fill="none">
            <path d="M18 0C8.06 0 0 8.06 0 18C0 31.5 18 48 18 48C18 48 36 31.5 36 18C36 8.06 27.94 0 18 0Z" fill="#E8720C"/>
            <circle cx="18" cy="18" r="8" fill="white"/>
            <circle cx="18" cy="18" r="4" fill="#E8720C"/>
        </svg>
        <div style="width:12px; height:4px; background:rgba(0,0,0,0.25); border-radius:50%; margin:0 auto; margin-top:-2px;"></div>
    </div>

    {{-- Header --}}
    <div style="position:absolute; top:0; left:0; right:0; z-index:20; padding:12px 16px; display:flex; align-items:center; gap:12px; background:linear-gradient(to bottom, rgba(0,0,0,0.55) 0%, transparent 100%);">
        <button onclick="editCloseMap()" style="width:38px; height:38px; border-radius:50%; border:none; background:rgba(255,255,255,0.95); cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.2);">
            <svg width="18" height="18" fill="none" stroke="#374151" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <span id="edit-modal-title" style="font-size:15px; font-weight:700; color:#fff; text-shadow:0 1px 3px rgba(0,0,0,0.4);">Chọn điểm</span>
    </div>

    {{-- Search bar --}}
    <div style="position:absolute; top:66px; left:16px; right:16px; z-index:20;">
        <div style="display:flex; align-items:center; gap:10px; background:#fff; border-radius:14px; padding:10px 14px; box-shadow:0 4px 20px rgba(0,0,0,0.18);">
            <svg width="18" height="18" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
            <input id="edit-modal-search" type="text" placeholder="Tìm địa chỉ..." autocomplete="off" style="flex:1; border:none; outline:none; font-size:14px; color:#111827; background:transparent;" />
        </div>
    </div>

    {{-- GPS button --}}
    <div style="position:absolute; right:16px; bottom:180px; z-index:20;">
        <button onclick="editGotoMyLocation()" style="width:44px; height:44px; border-radius:50%; border:none; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 12px rgba(0,0,0,0.2);">
            <svg width="20" height="20" fill="none" stroke="#E8720C" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="9" stroke-dasharray="2 2"/></svg>
        </button>
    </div>

    {{-- Bottom sheet --}}
    <div style="position:absolute; bottom:0; left:0; right:0; z-index:20; background:#fff; border-radius:20px 20px 0 0; padding:8px 20px 28px; box-shadow:0 -4px 24px rgba(0,0,0,0.12);">
        <div style="width:36px; height:4px; background:#e5e7eb; border-radius:2px; margin:0 auto 16px;"></div>
        <div style="display:flex; align-items:flex-start; gap:10px; margin-bottom:16px; min-height:40px;">
            <div style="width:36px; height:36px; border-radius:50%; background:#fff4ed; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px;">
                <svg width="16" height="16" fill="#E8720C" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
            </div>
            <div style="flex:1;">
                <p id="edit-picker-label" style="font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Địa chỉ</p>
                <p id="edit-picker-addr" style="font-size:14px; font-weight:500; color:#111827; line-height:1.4;">Đang xác định vị trí...</p>
            </div>
        </div>
        <button onclick="editConfirmMap()" style="width:100%; background:#E8720C; color:#fff; border:none; border-radius:14px; padding:15px; font-size:15px; font-weight:700; cursor:pointer; box-shadow:0 4px 14px rgba(232,114,12,0.35);">
            Xác nhận địa chỉ này
        </button>
    </div>
</div>

<script>
let _editMap, _editGeocoder, _editGeoTimer;
let _editAddr = '', _editLat = 0, _editLng = 0;
let _editMapsReady = false;
let _editMapMode = 'pickup';

// ── Autocomplete trên input ──
function _editInitAutocomplete(inputId) {
    const input = document.getElementById(inputId);
    if (!input || input._acInit) return;
    input._acInit = true;
    const ac = new google.maps.places.Autocomplete(input, { componentRestrictions: { country: 'vn' } });
    ac.addListener('place_changed', () => {
        const p = ac.getPlace();
        if (!p.geometry?.location) return;
        input.value = p.formatted_address || input.value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function _editInitAll() {
    _editInitAutocomplete('edit-pickup-addr');
    _editInitAutocomplete('edit-delivery-addr');
}

// ── Map picker ──
window.editOpenMap = function (mode) {
    _editMapMode = mode;
    _editAddr = '';

    const modal = document.getElementById('edit-map-modal');
    document.getElementById('edit-modal-title').textContent = mode === 'delivery' ? 'Chọn điểm giao hàng' : 'Chọn điểm lấy hàng';
    document.getElementById('edit-picker-label').textContent = mode === 'delivery' ? 'Điểm giao hàng' : 'Điểm lấy hàng';
    document.getElementById('edit-picker-addr').textContent = 'Đang xác định vị trí...';

    modal.style.display = 'block';
    _editMap = null;
    document.getElementById('edit-picker-map').innerHTML = '';

    const data = document.getElementById('edit-order-data');
    const pickupLat = parseFloat(data?.dataset?.pickupLat);
    const pickupLng = parseFloat(data?.dataset?.pickupLng);
    const cityLat = parseFloat(data?.dataset?.cityLat || '10.0452');
    const cityLng = parseFloat(data?.dataset?.cityLng || '105.7469');

    let center;
    if (mode === 'pickup' && pickupLat && pickupLng) {
        center = { lat: pickupLat, lng: pickupLng };
    } else if (mode === 'delivery' && pickupLat && pickupLng) {
        center = { lat: pickupLat, lng: pickupLng };
    } else {
        center = { lat: cityLat, lng: cityLng };
    }

    setTimeout(() => {
        if (!_editMapsReady) return;
        _editInitMapPicker(center);
    }, 150);
};

window.editCloseMap = function () {
    document.getElementById('edit-map-modal').style.display = 'none';
};

function _editInitMapPicker(center) {
    _editLat = center.lat;
    _editLng = center.lng;

    _editMap = new google.maps.Map(document.getElementById('edit-picker-map'), {
        center: center,
        zoom: 15,
        mapTypeControl: false,
        fullscreenControl: false,
        streetViewControl: false,
        zoomControl: true,
        zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER },
        gestureHandling: 'greedy',
    });

    _editGeocoder = new google.maps.Geocoder();

    _editMap.addListener('idle', () => {
        const c = _editMap.getCenter();
        _editLat = c.lat();
        _editLng = c.lng();

        const el = document.getElementById('edit-picker-addr');
        if (el) el.textContent = 'Đang xác định địa chỉ...';

        clearTimeout(_editGeoTimer);
        _editGeoTimer = setTimeout(() => {
            _editGeocoder.geocode({ location: { lat: _editLat, lng: _editLng } }, (res, st) => {
                _editAddr = (st === 'OK' && res[0]) ? res[0].formatted_address : `${_editLat.toFixed(5)}, ${_editLng.toFixed(5)}`;
                if (el) el.textContent = _editAddr;
            });
        }, 400);
    });

    const searchEl = document.getElementById('edit-modal-search');
    if (searchEl) {
        searchEl.value = '';
        const ac = new google.maps.places.Autocomplete(searchEl, { componentRestrictions: { country: 'vn' } });
        ac.bindTo('bounds', _editMap);
        ac.addListener('place_changed', () => {
            const p = ac.getPlace();
            if (!p.geometry?.location) return;
            _editMap.panTo(p.geometry.location);
            _editMap.setZoom(17);
            searchEl.blur();
        });
    }
}

window.editGotoMyLocation = function () {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(pos => {
        if (_editMap) _editMap.panTo({ lat: pos.coords.latitude, lng: pos.coords.longitude });
    });
};

window.editConfirmMap = function () {
    if (!_editAddr || _editAddr === 'Đang xác định địa chỉ...') return;
    const inputId = _editMapMode === 'delivery' ? 'edit-delivery-addr' : 'edit-pickup-addr';
    const input = document.getElementById(inputId);
    if (input) {
        input.value = _editAddr;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    editCloseMap();
};

// ── Google Maps callback ──
window._editMapsReady = function () {
    _editMapsReady = true;
    const modal = document.getElementById('edit-map-modal');
    if (modal && modal.parentElement !== document.body) document.body.appendChild(modal);
    _editInitAll();
};

window.addEventListener('openEditPickupMap',   () => editOpenMap('pickup'));
window.addEventListener('openEditDeliveryMap', () => editOpenMap('delivery'));

document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => setTimeout(_editInitAll, 100));
    });
});
</script>

<script
    src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&callback=_editMapsReady&loading=async"
    async defer
></script>

</x-filament-panels::page>
