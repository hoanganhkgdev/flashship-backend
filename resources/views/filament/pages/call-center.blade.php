<x-filament-panels::page class="fs-call-center">
    <link rel="stylesheet" href="{{ asset('css/call-center.css') }}?v={{ filemtime(public_path('css/call-center.css')) }}">

@php
    $activeSvc = $this::services()[$serviceType];
    $cities    = \Illuminate\Support\Facades\DB::table('cities')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'lat', 'lng']);
    $currentCityName = $cities->firstWhere('id', $data['city_id'] ?? null)?->name ?? '';
    $defaultCenter = ['lat' => 10.0452, 'lng' => 105.7009];
    if (!empty($data['city_id'])) {
        $cityRow = $cities->firstWhere('id', $data['city_id']);
        if ($cityRow && $cityRow->lat) $defaultCenter = ['lat' => (float)$cityRow->lat, 'lng' => (float)$cityRow->lng];
    }
    $err = fn (string $k) => $fieldErrors[$k] ?? null;
    $isRide = in_array($serviceType, ['bike', 'motor', 'car']);
    $nightSurcharge = $this->currentNightSurcharge();
    $shortLabels = ['delivery' => 'Lấy hộ', 'shopping' => 'Mua hộ', 'topup' => 'Nạp/Rút', 'bike' => 'Xe ôm', 'motor' => 'Xe máy', 'car' => 'Ô tô']; // nhãn ngắn để vừa ô; tên đầy đủ nằm ở title
@endphp

{{-- Đầu trang một hàng mỏng: tổng đài viên cần chỗ cho form, không cần tiêu đề lớn. --}}
<div class="cc-top">
    <h1><x-heroicon-o-phone-arrow-up-right />Tổng đài đặt đơn</h1>
    <span class="cc-chip"><i class="cc-live-dot"></i>{{ $currentCityName ?: 'Chưa chọn khu vực' }}</span>
    <span class="cc-chip cc-chip--svc" style="--svc-color:{{ $activeSvc['color'] }}">{{ $activeSvc['label'] }}</span>
    <p class="cc-top__kbd"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> tạo đơn</p>
</div>

@if ($resultOrderCode && ! $resultWarning)
<div class="cc-banner ok" role="status">
    <x-heroicon-o-check-circle class="h-4 w-4" />
    <span>Đặt đơn thành công <b>#{{ $resultOrderCode }}</b>@if ($resultFee !== null) · {{ number_format($resultFee, 0, ',', '.') }}đ @endif</span>
    <button type="button" wire:click="clearResult" title="Đóng"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
</div>
@endif

@if ($resultWarning)
<div class="cc-banner warn" role="alert">
    <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
    <span>{{ $resultWarning }}</span>
    <button type="button" wire:click="clearResult" title="Đóng"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
</div>
@endif

@if ($resultError)
<div class="cc-banner err" role="alert">
    <x-heroicon-o-exclamation-circle class="h-4 w-4" />
    <span>{{ $resultError }}</span>
</div>
@endif

<div class="cc-wrapper">

    {{-- ═══ PANEL FORM ═══ --}}
    <div class="cc-form-panel">
      <form id="cc-form" class="cc-form" wire:submit="placeOrder"
            onkeydown="if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') { event.preventDefault(); if (event.target.type === 'tel') event.target.blur(); }">
        <div class="cc-scroll">

            {{-- 1. Dịch vụ --}}
            <section class="cc-section cc-section--service">
            <div class="cc-section__head">Dịch vụ</div>
            <div class="cc-service-tabs">
                @foreach ($this::services() as $key => $svc)
                @php $active = $serviceType === $key; @endphp
                <button type="button" wire:click="selectService('{{ $key }}')" wire:key="svc-{{ $key }}" title="{{ $svc['label'] }}" aria-pressed="{{ $active ? 'true' : 'false' }}"
                    class="cc-service-tab {{ $active ? 'active' : '' }}"
                    style="--service-color:{{ $svc['color'] }}">
                    <x-dynamic-component :component="$svc['icon']" />
                    <span>{{ $shortLabels[$key] ?? $svc['label'] }}</span>
                </button>
                @endforeach
            </div>
            </section>

            {{-- 2. Hành trình --}}
            @php
                $pickupLabel = match($serviceType) {
                    'shopping' => 'Điểm mua hàng',
                    'topup'    => 'Địa chỉ nạp',
                    'bike', 'motor', 'car' => 'Điểm đón',
                    default    => 'Điểm lấy hàng',
                };
                $pickupShort = match($serviceType) { 'shopping' => 'Mua tại', 'topup' => 'Nạp tại', 'bike', 'motor', 'car' => 'Đón', default => 'Lấy' };
            @endphp
            <section class="cc-section">
            <div class="cc-section__head">Hành trình <span>chọn từ gợi ý hoặc ghim trên bản đồ</span></div>
            <div class="cc-card cc-journey-card {{ $err('pickup_address') ? 'has-err' : '' }}">
                <div class="cc-address-row">
                    <div class="cc-address-dot" style="background:#FF6B35; box-shadow:0 0 0 3px #FF6B3520;"></div>
                    <label class="cc-addr-tag" for="cc-pickup-input" title="{{ $pickupLabel }}">{{ $pickupShort }}</label>
                    <div class="cc-address-col">
                        <input id="cc-pickup-input" type="text" placeholder="{{ in_array($serviceType, ['shopping', 'topup', 'bike', 'motor', 'car'], true) ? $pickupLabel.': tuỳ chọn — có thể ghi trong ghi chú' : $pickupLabel.': nhập địa chỉ...' }}" autocomplete="off"
                            value="{{ ($data['pickup_place_name'] ?? '') !== '' ? $data['pickup_place_name'] : ($data['pickup_address'] ?? '') }}" onblur="_syncTyped()" autofocus />
                        @if (($data['pickup_place_name'] ?? '') !== '')<p class="cc-addr-sub" title="{{ $data['pickup_address'] ?? '' }}">{{ $data['pickup_address'] ?? '' }}</p>@endif
                        @if ($err('pickup_address'))<p class="cc-err">{{ $err('pickup_address') }}</p>@endif
                    </div>
                    <button type="button" id="cc-pin-btn-pickup" class="cc-pin-btn" onclick="_togglePinMode()" title="Chọn điểm lấy hàng trên bản đồ">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18s6-5.686 6-10A6 6 0 1 0 4 8c0 4.314 6 10 6 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
                    </button>
                </div>
            </div>
            </section>

            {{-- 3. Chi tiết theo dịch vụ --}}
            <section class="cc-section">
            <div class="cc-section__head">Chi tiết đơn</div>
            <div class="cc-card">
                @if ($serviceType === 'delivery')
                <div class="cc-fld">
                    <label for="cc-order-note" class="cc-lbl">Ghi chú cho tài xế</label>
                    <textarea id="cc-order-note" class="cc-textarea" wire:model="data.order_note" rows="4" placeholder="Ví dụ: loại hàng, cách lấy, lưu ý khi giao..."></textarea>
                </div>
                @endif

                @if ($serviceType === 'shopping')
                <div class="cc-fld"><label for="cc-shopping-note" class="cc-lbl">Hàng cần mua</label>
                <textarea id="cc-shopping-note" class="cc-textarea {{ $err('shopping_note') ? 'has-err' : '' }}" wire:model="data.shopping_note" rows="4" placeholder="Ví dụ: 1 ly trà sữa size L, ít đá..."></textarea></div>
                @if ($err('shopping_note'))<p class="cc-err">{{ $err('shopping_note') }}</p>@endif
                @endif

                @if ($serviceType === 'topup')
                <div class="cc-fld">
                    <label for="cc-order-note" class="cc-lbl">Ghi chú cho tài xế <small>· bắt buộc</small></label>
                    <textarea id="cc-order-note" class="cc-textarea {{ $err('order_note') ? 'has-err' : '' }}" wire:model="data.order_note" rows="4" placeholder="Ví dụ: số tiền nạp, SĐT khách, lưu ý..."></textarea>
                    @if ($err('order_note'))<p class="cc-err">{{ $err('order_note') }}</p>@endif
                </div>
                @endif

                @if ($isRide)
                <div class="cc-fld">
                    <label for="cc-order-note" class="cc-lbl">Ghi chú cho tài xế <small>· bắt buộc</small></label>
                    <textarea id="cc-order-note" class="cc-textarea {{ $err('order_note') ? 'has-err' : '' }}" wire:model="data.order_note" rows="4" placeholder="Ví dụ: điểm đến, SĐT khách, lưu ý..."></textarea>
                    @if ($err('order_note'))<p class="cc-err">{{ $err('order_note') }}</p>@endif
                </div>
                @endif
            </div>
            </section>

            {{-- 5. Phí + tài xế --}}
            <section class="cc-section">
            <div class="cc-section__head">Phí và tài xế</div>
            <div class="cc-card cc-pricing-card {{ $err('assignedDriverId') ? 'has-err' : '' }}">
                <div class="cc-row2">
                    <div>
                        <label class="cc-lbl" for="cc-shipping-fee">Phí đơn</label>
                        <div class="cc-fee-wrap"><input id="cc-shipping-fee" type="number" min="0" max="{{ $this::MAX_FEE }}" class="cc-input {{ $err('shipping_fee') ? 'has-err' : '' }}" wire:model.blur="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
                        @if ($err('shipping_fee'))<p class="cc-err">{{ $err('shipping_fee') }}</p>
                        @elseif ($nightSurcharge > 0)<p class="cc-hint">+ {{ number_format($nightSurcharge, 0, ',', '.') }}đ phụ phí đêm (tự cộng khi có phí)</p>@endif
                    </div>
                    <div>
                        <label class="cc-lbl" for="cc-bonus-fee">Bonus tài xế <small>(admin trả)</small></label>
                        <div class="cc-fee-wrap"><input id="cc-bonus-fee" type="number" min="0" max="{{ $this::MAX_FEE }}" class="cc-input {{ $err('bonus_fee') ? 'has-err' : '' }}" wire:model.blur="data.bonus_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
                        @if ($err('bonus_fee'))<p class="cc-err">{{ $err('bonus_fee') }}</p>@endif
                    </div>
                </div>
                <div class="cc-fld cc-driver-field">
                    <label class="cc-lbl" for="cc-assigned-driver">Gán tài xế <small>(tuỳ chọn)</small></label>
                    <div class="cc-select-wrap">
                        <select id="cc-assigned-driver" wire:model="assignedDriverId" class="cc-input cc-select">
                            <option value="">Hệ thống tự chọn</option>
                            @foreach ($onlineDrivers as $d)
                            <option value="{{ $d['id'] }}">{{ $d['km'] !== null ? str_replace('.', ',', $d['km']).' km · ' : 'chưa có vị trí · ' }}{{ $d['label'] }}</option>
                            @endforeach
                            @if (! empty($unavailableDrivers))
                            <optgroup label="Chưa gán được">
                                @foreach ($unavailableDrivers as $d)
                                <option disabled>{{ $d['name'] }}{{ $d['phone'] ? ' · '.$d['phone'] : '' }} — {{ $d['reason'] }}</option>
                                @endforeach
                            </optgroup>
                            @endif
                        </select>
                        <svg class="cc-select-chevron" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                </div>
                @if ($err('assignedDriverId'))<p class="cc-err">{{ $err('assignedDriverId') }}</p>@endif
                <label class="cc-check cc-freeship" title="Khách không trả phí ship, công ty trả thay cho tài xế khi hoàn thành">
                    <input type="checkbox" wire:model.live="isFreeship" /> <strong>Freeship</strong> <span>công ty trả phí ship thay khách</span>
                </label>
            </div>
            </section>
        </div>

        {{-- Thanh đặt đơn dính đáy --}}
        <div class="cc-bar">
            <div class="cc-bar__info">
                @php
                    $typedFee = is_numeric($data['shipping_fee'] ?? null) ? max(0, (int) $data['shipping_fee']) : 0;
                    // Chỉ cộng phụ phí khi phí hợp lệ (placeOrder chặn 1–999đ là gõ thiếu số 0).
                    $barFee = $typedFee >= 1000 ? $typedFee + $nightSurcharge : $typedFee;
                @endphp
                <span>Phí đơn{{ $isFreeship ? ' · Freeship' : '' }}{{ $typedFee >= 1000 && $nightSurcharge > 0 ? ' · gồm phụ phí đêm' : '' }}</span>
                <b>{{ number_format($barFee, 0, ',', '.') }}<small>đ</small></b>
            </div>
            {{-- wire:target bắt buộc: thiếu nó, mọi request (làm mới danh sách đơn 15s, đổi địa chỉ...) đều tắt nút → nút chớp. --}}
            <button type="submit" wire:loading.attr="disabled" wire:target="placeOrder" class="cc-submit-btn">
                <x-heroicon-o-paper-airplane wire:loading.remove wire:target="placeOrder" />
                <span wire:loading.remove wire:target="placeOrder">Tạo đơn</span>
                <span wire:loading wire:target="placeOrder">Đang xử lý...</span>
            </button>
        </div>
      </form>
    </div>

    {{-- ═══ BẢN ĐỒ + ĐƠN VỪA ĐẶT ═══ wire:ignore bọc map, script và danh sách đơn: Livewire re-render
    (đổi dịch vụ, nhập SĐT...) không được đụng vào, nếu không Google Maps load lại và chớp liên tục. --}}
    <div class="cc-map-wrap">
        <header class="cc-map-heading"><div><x-heroicon-o-map /><span>Bản đồ điều phối</span></div><span class="cc-map-availability"><i class="cc-live-dot"></i>{{ count($onlineDrivers) }} tài xế có thể gán</span></header>
        <div class="cc-map-area" wire:ignore>
            <div id="cc-main-map" class="cc-map"></div>
            <div class="cc-map-title"><x-heroicon-o-map-pin />Chọn biểu tượng ghim ở ô địa chỉ để đặt điểm</div>
            <div id="cc-map-info-drivers" class="cc-map-chip" style="display:none">
                <span id="cc-driver-label"></span>
                <span class="cc-legend"><i style="background:#22c55e"></i>Rảnh <i style="background:#3b82f6"></i>Đang giao <i style="background:#9ca3af"></i>Chưa nhận được</span>
            </div>
            <div id="cc-pin-hint" class="cc-pin-hint">
                <span id="cc-pin-hint-text"></span>
                <button type="button" onclick="_exitPinMode()">✕</button>
            </div>
        </div>
        <div class="cc-recent" wire:ignore>
            <div class="cc-recent__head">
                <span class="cc-recent__title"><x-heroicon-o-queue-list />Đơn tổng đài</span>
                <div class="cc-recent__tabs" role="tablist" aria-label="Lọc đơn tổng đài">
                    <button type="button" class="cc-recent__tab active" data-cc-board="attention" role="tab" aria-selected="true" onclick="_ccSelectBoard('attention')">Cần xử lý <b id="cc-attention-count">0</b></button>
                    <button type="button" class="cc-recent__tab" data-cc-board="recent" role="tab" aria-selected="false" onclick="_ccSelectBoard('recent')">Tất cả hôm nay <b id="cc-recent-count">0</b></button>
                </div>
                <em id="cc-recent-time" title="Tự cập nhật mỗi 15 giây"></em>
            </div>
            <div id="cc-recent-list" role="region" aria-label="Danh sách đơn tổng đài"><div class="cc-recent__empty">Đang tải…</div></div>
        </div>
    </div>

    <input type="hidden" wire:model="data.city_id" />
</div>

<x-filament-actions::modals />

<div wire:ignore>
<script>
// Cuộn chuột trên ô số (phí ship, số tiền nạp...) không được tự +/- giá trị
// — bỏ focus ngay khi lăn chuột qua, tránh đổi số ngoài ý muốn lúc tổng đài
// chỉ đang cuộn trang qua khu vực đó. Gắn trên document (event delegation)
// nên vẫn hoạt động đúng dù Livewire re-render lại các input bên trong.
document.addEventListener('wheel', (e) => {
    if (document.activeElement === e.target && e.target.matches('.cc-form-panel input[type="number"]')) {
        e.target.blur();
    }
}, { passive: true });

// Đơn tổng đài chỉ có điểm lấy (không có điểm giao): bản đồ chỉ ghim điểm lấy và tài xế quanh đó.
let _mainMap, _geocoder, _pickupMarker;
let _mapsReady = false;
let _pickupAc = null;
let _driverMarkers = [];
let _pinMode = false; // đang bật "chọn điểm lấy bằng click trên bản đồ"

const _cityCenter = { lat: {{ $defaultCenter['lat'] }}, lng: {{ $defaultCenter['lng'] }} };
// ── Init map ───────────────────────────────────────────────────────────────
function _initMainMap() {
    _mainMap = new google.maps.Map(document.getElementById('cc-main-map'), {
        center: _cityCenter, zoom: 14,
        mapTypeControl: false, fullscreenControl: false,
        streetViewControl: false,
        zoomControl: true,
        zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER },
        gestureHandling: 'greedy',
        scrollwheel: false,
        styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }],
    });
    // Lăn chuột trên bản đồ chỉ cuộn trang, không zoom (muốn zoom thì bấm nút + / −).
    // Chặn ở pha capture để Google Maps không nhận sự kiện; hành vi cuộn mặc định của trang vẫn giữ.
    const mapEl = document.getElementById('cc-main-map');
    if (mapEl && !mapEl._noWheelZoom) {
        mapEl._noWheelZoom = true;
        mapEl.addEventListener('wheel', (e) => e.stopPropagation(), { capture: true });
    }
    _geocoder = new google.maps.Geocoder();

    // Click bản đồ CHỈ có tác dụng khi đang bật "chế độ chọn trên bản đồ"
    // (bấm nút pin cạnh ô địa chỉ) — dự phòng cho lúc Autocomplete không ra
    // đúng địa chỉ cần tìm. Mặc định bản đồ chỉ để xem, click không làm gì.
    _mainMap.addListener('click', (e) => {
        if (!_pinMode) return;
        const lat = e.latLng.lat(), lng = e.latLng.lng();
        _exitPinMode();
        _geocoder.geocode({ location: { lat, lng } }, (res, status) => {
            const addr = (status === 'OK' && res[0]) ? res[0].formatted_address : `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            const pi = document.getElementById('cc-pickup-input');
            if (pi) { pi.value = addr; pi._picked = addr; }
            _setPickupPin(lat, lng);
            @this.call('setPickupLocation', addr, lat, lng).then(() => _refreshDriverMarkers(true));
        });
    });

    _initSearchAutocomplete();
}

// Bật/tắt "chế độ chọn điểm lấy trên bản đồ" — bấm lại nút đang bật thì tắt.
function _togglePinMode() {
    if (_pinMode) { _exitPinMode(); return; }
    _pinMode = true;
    const btn = document.getElementById('cc-pin-btn-pickup');
    if (btn) btn.classList.add('active');
    const hint = document.getElementById('cc-pin-hint');
    const hintText = document.getElementById('cc-pin-hint-text');
    if (hintText) hintText.textContent = '📍 Bấm vào bản đồ để chọn điểm lấy';
    if (hint) hint.style.display = 'flex';
    if (_mainMap) _mainMap.setOptions({ draggableCursor: 'crosshair' });
}

function _exitPinMode() {
    _pinMode = false;
    document.querySelectorAll('.cc-pin-btn').forEach((b) => b.classList.remove('active'));
    const hint = document.getElementById('cc-pin-hint');
    if (hint) hint.style.display = 'none';
    if (_mainMap) _mainMap.setOptions({ draggableCursor: null });
}

// ── Autocomplete — nguồn nhập toạ độ DUY NHẤT ─────────────────────────────────
function _makeBounds() {
    return new google.maps.LatLngBounds(
        new google.maps.LatLng(_cityCenter.lat - 0.15, _cityCenter.lng - 0.15),
        new google.maps.LatLng(_cityCenter.lat + 0.15, _cityCenter.lng + 0.15)
    );
}

function _initSearchAutocomplete() {
    const bounds = _makeBounds();
    const pickupInput = document.getElementById('cc-pickup-input');

    if (pickupInput && !pickupInput._acBound) {
        pickupInput._acBound = true;
        _pickupAc = new google.maps.places.Autocomplete(pickupInput, {
            componentRestrictions: { country: 'vn' }, bounds, strictBounds: true,
        });
        _pickupAc.addListener('place_changed', () => {
            const p = _pickupAc.getPlace();
            if (!p.geometry?.location) return;
            const lat = p.geometry.location.lat(), lng = p.geometry.location.lng();
            const addr = (p.formatted_address || pickupInput.value).trim();
            // Cửa hàng/địa điểm: ô hiện tên, địa chỉ nằm dòng dưới; địa chỉ thường: ô hiện địa chỉ như cũ
            const isPlace = (p.types || []).some((t) => t === 'establishment' || t === 'point_of_interest');
            const name = isPlace ? (p.name || '').trim() : '';
            if (name) pickupInput.value = name;
            pickupInput._picked = (name || addr).trim();
            @this.call('setPickupLocation', addr, lat, lng, name || null).then(() => _refreshDriverMarkers(true));
            _setPickupPin(lat, lng);
        });
    }

}

// ── Marker điểm lấy ──────────────────────────────────────────────────────
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

function _setPickupPin(lat, lng, move = true) {
    // move=false: chỉ đồng bộ ghim theo trạng thái server (hook commit) — không kéo/thu phóng bản đồ,
    // nếu không sẽ đè khung nhìn 4 km vừa căn hoặc vị trí người dùng đang xem.
    if (_pickupMarker) {
        const p = _pickupMarker.getPosition();
        if (p && Math.abs(p.lat() - lat) < 1e-9 && Math.abs(p.lng() - lng) < 1e-9) return;
        _pickupMarker.setMap(null);
    }
    _pickupMarker = new google.maps.Marker({
        position: { lat, lng }, map: _mainMap,
        icon: _pinIcon('#FF6B35', 'A'),
        title: 'Điểm lấy',
        zIndex: 20,
    });
    if (!move) return;
    _mainMap.panTo({ lat, lng });
    _mainMap.setZoom(15);
}

// Tài xế trong 4km quanh điểm lấy — đọc thẳng @this.nearbyDrivers (đã được
// backend tính xong ngay trong request setPickupLocation(), không cần gọi
// thêm request nào khác). CHỈ gọi hàm này SAU KHI @this.call('setPickupLocation'
// | reorder khôi phục) đã resolve — TUYỆT ĐỐI không gọi từ trong
// Livewire.hook('commit') (xem chú thích ở refreshNearbyDrivers() phía
// backend, đã từng gây bản đồ chớp vô hạn).
const _NEARBY_KM = 4;
const _DRV_COLORS = { free: '#22c55e', busy: '#3b82f6', blocked: '#9ca3af' };
let _driverCircle = null, _driverInfo = null;

// Tài xế đang online trong 4 km quanh điểm lấy: đọc thẳng @this.nearbyDrivers (server đã tính trong cùng request
// setPickupLocation, không gọi thêm). CHỈ gọi sau khi @this.call(...) đã resolve hoặc trong hook commit — TUYỆT ĐỐI
// không gọi lại request từ đây (xem chú thích ở refreshNearbyDrivers(): từng gây bản đồ chớp vô hạn).
function _refreshDriverMarkers(fit) {
    if (!_mainMap) return;
    const drivers = @this.nearbyDrivers || [];
    const pLat = @this.pickupLat, pLng = @this.pickupLng;
    const wrap = document.getElementById('cc-map-info-drivers');
    const label = document.getElementById('cc-driver-label');
    _driverMarkers.forEach((m) => m.setMap(null));
    _driverMarkers = [];
    if (_driverCircle) { _driverCircle.setMap(null); _driverCircle = null; }
    if (!(pLat && pLng)) { if (wrap) wrap.style.display = 'none'; return; }

    _driverCircle = new google.maps.Circle({
        map: _mainMap, center: { lat: pLat, lng: pLng }, radius: _NEARBY_KM * 1000,
        strokeColor: '#FF6B35', strokeOpacity: .7, strokeWeight: 1.5, fillColor: '#FF6B35', fillOpacity: .05, clickable: false,
    });
    if (!_driverInfo) _driverInfo = new google.maps.InfoWindow();
    _driverMarkers = drivers.map((d) => {
        const m = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng }, map: _mainMap, zIndex: d.assignable ? 6 : 4, title: d.name,
            icon: { path: google.maps.SymbolPath.CIRCLE, scale: 8, fillColor: _DRV_COLORS[d.state] || '#9ca3af', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
        });
        m.addListener('click', () => {
            const status = d.assignable ? (d.active > 0 ? 'Đang giao ' + d.active + ' đơn' : 'Đang rảnh') : (d.reason || 'Chưa nhận được đơn');
            const pick = d.assignable ? '<button type="button" onclick="_ccPickDriver(' + d.id + ')" style="margin-top:6px;padding:4px 12px;border-radius:8px;background:#0ea5e9;color:#fff;font-size:12px;border:0;cursor:pointer">Chọn gán tài xế này</button>' : '';
            _driverInfo.setContent('<div style="font-family:inherit;min-width:190px"><b>' + _ccEsc(d.name) + '</b><br>' +
                (d.phone ? '<a href="tel:' + _ccEsc(String(d.phone).replace(/\D+/g, '')) + '" style="color:#2563eb">' + _ccEsc(d.phone) + '</a><br>' : '') +
                '<span style="font-size:12px;color:#475569">' + String(d.km).replace('.', ',') + ' km · ' + _ccEsc(status) + '</span><br>' + pick + '</div>');
            _driverInfo.open(_mainMap, m);
        });
        return m;
    });

    const free = drivers.filter((d) => d.state === 'free').length;
    if (label) label.textContent = drivers.length ? `${drivers.length} tài xế trong ${_NEARBY_KM} km (${free} rảnh)` : `Chưa có tài xế nào trong ${_NEARBY_KM} km`;
    if (wrap) wrap.style.display = 'flex';
    // Thu phóng cho thấy trọn vòng 4 km quanh điểm lấy
    if (fit && _driverCircle.getBounds()) _mainMap.fitBounds(_driverCircle.getBounds(), 16);
}

function _ccPickDriver(id) {
    if (_driverInfo) _driverInfo.close();
    @this.set('assignedDriverId', id);
}

function _clearDriverMarkers() {
    _driverMarkers.forEach((m) => m.setMap(null));
    _driverMarkers = [];
    if (_driverCircle) { _driverCircle.setMap(null); _driverCircle = null; }
    const wrap = document.getElementById('cc-map-info-drivers');
    if (wrap) wrap.style.display = 'none';
}

// ── Livewire: đồng bộ pin khi state đổi ───────────────────────────────────────
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ commit, succeed }) => {
        // Lần làm mới danh sách "Đơn vừa đặt" (15s/lần) không được kéo lại bản đồ về điểm lấy
        const calls = (commit && commit.calls) || [];
        if (calls.length && calls.every((c) => ['recentOrders'].includes(c.method))) return;
        succeed(() => {
            setTimeout(_ccFit, 120); // banner kết quả hiện/ẩn làm đổi vị trí khung → tính lại chiều cao
            setTimeout(() => {
                const pLat = @this.pickupLat, pLng = @this.pickupLng;
                const pAddr = (@this.data && @this.data.pickup_address) || '';
                const pShow = (@this.data && @this.data.pickup_place_name) || pAddr;
                const pi = document.getElementById('cc-pickup-input');
                // Địa chỉ do server điền (đơn cũ, đặt lại, chọn bản đồ) phải hiện lên ô; đang gõ dở thì không đè
                if (pi && pAddr && document.activeElement !== pi && pi.value.trim() !== pShow.trim()) pi.value = pShow;
                if (pLat && pLng) {
                    _setPickupPin(pLat, pLng, false);
                    _refreshDriverMarkers(false);
                    if (pi) pi._picked = (pi.value || '').trim();
                } else {
                    _clearDriverMarkers();
                    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
                    // Chưa có toạ độ nhưng còn chữ đã gõ thì giữ lại (chưa chọn gợi ý); chỉ xoá khi form đã được làm sạch
                    // Ô đang gõ (chữ chưa đẩy lên server vì không gắn wire:model) thì không xoá, nếu không request nào vừa xong cũng làm mất chữ
                    if (pi && !pAddr && document.activeElement !== pi) pi.value = '';
                }
                if (_mapsReady) _initSearchAutocomplete();
            }, 150);
        });
    });
});

// ── Khung form + bản đồ cao đúng phần màn hình còn lại (màn tổng đài thường nhỏ) ─────────────
function _ccFit() {
    // Khung cao đúng phần màn hình còn lại dưới phần đầu trang, để thanh Tạo đơn ở đáy khung luôn thấy được
    // (min-height cố định trước đây đẩy nút xuống dưới mép màn hình khi cửa sổ thấp). Màn nhỏ: để trang tự cuộn.
    const w = document.querySelector('.cc-wrapper');
    if (!w) return;
    if (window.innerWidth <= 900) { w.style.removeProperty('height'); return; }
    const top = w.getBoundingClientRect().top + window.scrollY;
    w.style.setProperty('height', Math.max(420, window.innerHeight - top - 12) + 'px', 'important');
}
window.addEventListener('resize', _ccFit);
document.addEventListener('livewire:initialized', () => { _ccFit(); setTimeout(_ccFit, 300); });
setTimeout(_ccFit, 0);

// ── Địa chỉ gõ tay ───────────────────────────────────────────────────────────
// Ô địa chỉ không gắn wire:model (Google Autocomplete giữ giá trị). Khi rời ô mà chữ khác với địa chỉ đã chọn
// thì đẩy chữ lên server (không gọi request) và bỏ toạ độ cũ, để đơn không mang toạ độ của địa chỉ khác.
function _syncTyped() {
    // Chọn một gợi ý Google làm ô mất focus trước khi place_changed chạy: đợi một nhịp để lựa chọn thắng
    setTimeout(_syncTypedNow, 250);
}
function _syncTypedNow() {
    const el = document.getElementById('cc-pickup-input');
    if (!el) return;
    const v = el.value.trim();
    const cur = ((@this.data || {}).pickup_address || '').trim();
    if (v === cur || v === (el._picked || '')) return;
    @this.set('data.pickup_address', v, false);
    @this.set('data.pickup_place_name', '', false);
    @this.set('pickupLat', null, false);
    @this.set('pickupLng', null, false);
    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
    @this.call('refreshPreview'); // kèm các thay đổi vừa gom, để danh sách tài xế không còn theo điểm lấy cũ
}

// ── Đơn vừa đặt: tự làm mới 15s, không vẽ lại form ───────────────────────────
function _ccEsc(v) { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
// Thời gian chờ dễ đọc: "Cần xử lý" giữ cả đơn của hôm trước, không hiện kiểu "1500 phút".
function _ccAge(min) {
    min = Math.max(0, Math.floor(min || 0));
    if (min < 60) return min + ' phút';
    if (min < 24 * 60) { const h = Math.floor(min / 60), m = min % 60; return h + ' giờ' + (m ? ' ' + m + ' phút' : ''); }
    const d = Math.floor(min / (24 * 60));
    return d === 1 ? 'hôm qua' : d + ' ngày';
}
let _ccRecentOrders = [];
let _ccActiveBoard = 'attention';
function _ccSelectBoard(board) {
    _ccActiveBoard = board === 'recent' ? 'recent' : 'attention';
    document.querySelectorAll('[data-cc-board]').forEach((button) => {
        button.classList.toggle('active', button.dataset.ccBoard === _ccActiveBoard);
        button.setAttribute('aria-selected', String(button.dataset.ccBoard === _ccActiveBoard));
    });
    _ccRenderRecent(_ccRecentOrders);
}
function _ccRenderRecent(list) {
    const box = document.getElementById('cc-recent-list');
    if (!box) return;
    const t = document.getElementById('cc-recent-time');
    if (t) t.textContent = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    // Server trả mọi đơn còn chờ (kể cả hôm trước) cùng các đơn gần nhất hôm nay.
    const all = list || [];
    const attention = all.filter((o) => o.status === 'pending');
    const today = all.filter((o) => o.today);
    const attentionCount = document.getElementById('cc-attention-count');
    const recentCount = document.getElementById('cc-recent-count');
    if (attentionCount) attentionCount.textContent = attention.length;
    if (recentCount) recentCount.textContent = today.length;
    const visible = _ccActiveBoard === 'attention' ? attention : today;
    if (!visible.length) {
        box.innerHTML = '<div class="cc-recent__empty">' + (_ccActiveBoard === 'attention' ? 'Không có đơn nào đang chờ xử lý.' : 'Chưa có đơn nào hôm nay.') + '</div>';
        return;
    }
    box.innerHTML = visible.map((o) => {
        const canAssign = o.status === 'pending';
        const canCancel = ['pending', 'assigned', 'processing'].includes(o.status);
        const label = o.status_label;
        const color = o.status === 'pending' ? '#b45309' : (o.status === 'completed' ? '#15803d' : '#64748b');
        const when = (o.status === 'pending' ? _ccAge(o.minutes) : (o.driver ? _ccEsc(o.driver) : _ccAge(o.minutes) + ' trước'));
        const route = '↑ ' + _ccEsc(o.pickup) + (o.delivery ? ' → ' + _ccEsc(o.delivery) : '');
        return '<div class="cc-ro s-' + o.status + '' + '">' +
            '<a class="code" href="' + _ccEsc(o.url) + '">#' + _ccEsc(o.code) + '</a>' +
            '<span class="who" title="' + _ccEsc(o.service) + '">' + _ccEsc(o.name ? o.name + ' · ' : '') + _ccEsc(o.phone || 'chưa có SĐT') + '</span>' +
            '<span class="route" title="' + _ccEsc(o.pickup) + '">' + route + '</span>' +
            '<span class="st" style="color:' + color + '">' + _ccEsc(label) + ' <small>' + when + '</small></span>' +
            '<span class="acts">' +
                (canAssign ? '<button type="button" class="is-primary" onclick="_ccAssign(' + o.id + ')">Gán</button>' : '') +
                (canCancel ? '<button type="button" class="is-danger" onclick="_ccCancel(' + o.id + ')">Hủy</button>' : '') +
            '</span>' +
            '</div>';
    }).join('');
}
function _ccLoadRecent(force) {
    if (document.hidden && force !== true) return; // tab đang ẩn thì thôi làm mới định kỳ; lần tải đầu luôn chạy
    @this.call('recentOrders').then((recent) => {
        _ccRecentOrders = recent || [];
        _ccRenderRecent(_ccRecentOrders);
    }).catch(() => {});
}
function _ccAssign(id) { @this.call('mountAction', 'assign', { order: id }); }
function _ccCancel(id) { @this.call('mountAction', 'cancel', { order: id }); }
window.addEventListener('cc-recent-refresh', () => _ccLoadRecent(true));
if (window._ccRecentTimer) clearInterval(window._ccRecentTimer);
window._ccRecentTimer = setInterval(_ccLoadRecent, 15000);
document.addEventListener('livewire:initialized', () => _ccLoadRecent(true));

// Ctrl/Cmd + Enter: đặt đơn nhanh khi đang nghe máy
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const f = document.getElementById('cc-form');
        if (f) { e.preventDefault(); f.requestSubmit(); }
    }
});

// ── Google Maps callback ─────────────────────────────────────────────────────
window.ccInitGoogleMaps = function () {
    _mapsReady = true;
    _initMainMap();

    // Đặt lại đơn: khôi phục ghim điểm lấy
    const pLat = @this.pickupLat, pLng = @this.pickupLng;
    if (pLat && pLng) { _setPickupPin(pLat, pLng); _refreshDriverMarkers(); }
};
</script>

{{-- Google Maps API: AdminPanelProvider đã nạp sẵn toàn cục (kèm thư viện places) — chỉ nạp thêm khi chưa có,
     để không dính lỗi "đã nạp Google Maps nhiều lần". --}}
<script>
(function boot() {
    // Chờ Livewire sẵn sàng: ccInitGoogleMaps đọc trạng thái trang qua @this (khôi phục ghim khi "đặt lại")
    if (!window.Livewire || !window.Livewire.all || !window.Livewire.all().length) { setTimeout(boot, 100); return; }
    if (window.google && window.google.maps && window.google.maps.places) { window.ccInitGoogleMaps(); return; }
    if (window._ccMapsLoading) { setTimeout(boot, 150); return; }
    const hasGlobal = Array.from(document.scripts).some((x) => (x.src || '').includes('maps.googleapis.com/maps/api/js'));
    if (hasGlobal) { setTimeout(boot, 150); return; }
    window._ccMapsLoading = true;
    const el = document.createElement('script');
    el.src = 'https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&loading=async';
    el.onload = () => { window._ccMapsLoading = false; boot(); };
    document.head.appendChild(el);
})();
</script>
</div>

</x-filament-panels::page>
