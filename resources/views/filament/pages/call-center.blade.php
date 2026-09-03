<x-filament-panels::page>

@php
    $activeSvc = $this::services()[$serviceType];
    $cities    = \Illuminate\Support\Facades\DB::table('cities')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'lat', 'lng']);
    $currentCityName = $cities->firstWhere('id', $data['city_id'] ?? null)?->name ?? '';
    $defaultCenter = ['lat' => 10.0452, 'lng' => 105.7009];
    if (!empty($data['city_id'])) {
        $cityRow = $cities->firstWhere('id', $data['city_id']);
        if ($cityRow && $cityRow->lat) $defaultCenter = ['lat' => (float)$cityRow->lat, 'lng' => (float)$cityRow->lng];
    }
@endphp

<header class="fs-page-header cc-page-header">
    <div>
        <p class="fs-page-header__eyebrow">Trung tâm tiếp nhận</p>
        <h1 class="fs-page-header__title">Tổng đài đặt đơn</h1>
        <p class="fs-page-header__description">Tạo đơn, định vị hành trình và điều phối tài xế trên cùng một màn hình.</p>
    </div>
    <div class="cc-header-context">
        <span>{{ $currentCityName ?: 'Chưa chọn khu vực' }}</span>
        <strong>{{ $activeSvc['label'] }}</strong>
    </div>
</header>

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

{{-- ══ LAYOUT 2 PANEL: FORM (trái) + BẢN ĐỒ QUAN SÁT (phải) ═══════════════ --}}
<style>
    {{-- Toàn bộ form và bản đồ cuộn cùng trang để không che mất nội dung. --}}
    body { overflow-y:auto !important; }

    .cc-page-header { margin-bottom:12px; }
    .cc-header-context { display:flex; align-items:center; gap:8px; }
    .cc-header-context span, .cc-header-context strong { padding:6px 10px; border:1px solid #e5e7eb; border-radius:9px; background:#fff; font-size:12px; }
    .cc-header-context span { color:#64748b; font-weight:500; }
    .cc-header-context strong { color:{{ $activeSvc['color'] }}; font-weight:650; }
    .cc-wrapper { position:relative; min-height:720px; border-radius:16px; overflow:hidden; border:1px solid #e5e7eb; display:flex; align-items:stretch; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.03),0 12px 30px rgba(15,23,42,.04); }

    .cc-form-panel { width:min(460px, 42%); flex-shrink:0; display:flex; flex-direction:column; gap:10px; padding:14px; overflow:visible; background:#f8fafc; border-right:1px solid #e5e7eb; }
    .cc-form-panel::-webkit-scrollbar { width:0; }

    .cc-card { background:#fff; border:1px solid #eef0f2; border-radius:14px; padding:10px; }
    .cc-step-title { display:flex; align-items:center; gap:7px; margin:0 2px 6px; color:#475569; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    .cc-step-title span { display:inline-grid; width:19px; height:19px; place-items:center; border-radius:50%; background:var(--cc-accent,#4F46E5); color:#fff; font-size:10px; }

    .cc-service-tabs { display:grid; grid-template-columns:repeat(3, 1fr); gap:5px; }
    .cc-service-tab { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; border-radius:10px; padding:6px 4px; text-align:center; color:#6b7280; background:#f8fafc; border:1px solid transparent; transition:all .15s ease; }
    .cc-service-tab:hover { background:#f1f5f9; }
    .cc-service-tab.active { color:#fff; }
    .cc-service-tab svg { width:16px; height:16px; }
    .cc-service-tab span { font-size:11px; font-weight:600; line-height:1.15; }

    .cc-address-row { display:flex; align-items:flex-start; gap:10px; padding:6px 2px; }
    .cc-address-row + .cc-address-row { border-top:1px dashed #eef0f2; }
    .cc-address-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; margin-top:8px; }
    .cc-address-col { flex:1; min-width:0; }
    .cc-address-col label { display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin-bottom:2px; }
    .cc-address-col input { width:100%; border:none; outline:none; font-size:14px; color:#111827; background:transparent; padding:0; box-shadow:none; }
    .cc-address-col input:focus { border:none; outline:none; box-shadow:none; }
    .cc-address-col input::placeholder { color:#c1c5cb; }
    .cc-pin-btn {
        flex-shrink:0; width:28px; height:28px; border-radius:8px; margin-top:2px;
        border:1px solid #e5e7eb; background:#f8fafc; color:#6b7280;
        display:flex; align-items:center; justify-content:center; cursor:pointer;
        transition:all .15s ease;
    }
    .cc-pin-btn svg { width:15px; height:15px; }
    .cc-pin-btn:hover { background:#f1f5f9; color:#111827; }
    .cc-pin-btn.active { background:var(--cc-accent, #4F46E5); border-color:var(--cc-accent, #4F46E5); color:#fff; }

    .cc-pin-hint {
        display:none; position:absolute; top:16px; left:50%; transform:translateX(-50%); z-index:11;
        align-items:center; gap:10px; background:#111827; color:#fff; font-size:13px; font-weight:600;
        padding:9px 10px 9px 16px; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.25); white-space:nowrap;
    }
    .cc-pin-hint button { flex-shrink:0; width:22px; height:22px; border-radius:50%; border:none; background:rgba(255,255,255,0.15); color:#fff; cursor:pointer; font-size:13px; line-height:1; }
    .cc-pin-hint button:hover { background:rgba(255,255,255,0.3); }

    .cc-field { margin-bottom:8px; }
    .cc-field:last-child { margin-bottom:0; }
    .cc-field label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin-bottom:3px; }
    .cc-field-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px; }
    .cc-field-row .cc-field { margin-bottom:0; }
    .cc-input, .cc-textarea {
        width:100%; border:1px solid #e5e7eb; border-radius:9px; padding:6px 10px;
        font-size:13.5px; color:#111827; background:#f8fafc; outline:none;
        transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }
    .cc-input::placeholder, .cc-textarea::placeholder { color:#c1c5cb; }
    .cc-input[type="number"] { -moz-appearance:textfield; }
    .cc-input[type="number"]::-webkit-outer-spin-button,
    .cc-input[type="number"]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .cc-input:focus, .cc-textarea:focus, .cc-form-panel select:focus {
        border-color:var(--cc-accent, #4F46E5); background:#fff;
        box-shadow:0 0 0 3px var(--cc-accent-20, rgba(79,70,229,.15));
    }
    .cc-textarea { resize:none; }
    .cc-fee-wrap { position:relative; }
    .cc-fee-wrap input { padding-right:30px; }
    .cc-fee-suffix { position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:13px; font-weight:600; color:#9ca3af; pointer-events:none; }

    .cc-select-wrap { position:relative; }
    .cc-select {
        appearance:none; -webkit-appearance:none; -moz-appearance:none;
        background-image:none !important; background-repeat:no-repeat;
        padding-right:34px; cursor:pointer;
    }
    .cc-select::-ms-expand { display:none; }
    .cc-select-chevron { position:absolute; right:12px; top:50%; width:16px; height:16px; transform:translateY(-50%); pointer-events:none; }

    .cc-checkbox-row { display:flex; align-items:center; gap:8px; padding-top:8px; margin-top:8px; border-top:1px dashed #eef0f2; cursor:pointer; }
    .cc-checkbox-row input[type="checkbox"] { width:16px; height:16px; accent-color:var(--cc-accent, #4F46E5); cursor:pointer; flex-shrink:0; }
    .cc-checkbox-row span { font-size:12.5px; color:#111827; line-height:1.3; }
    .cc-checkbox-row strong { font-weight:600; }
    .cc-checkbox-row small { font-size:11px; color:#9ca3af; }

    .cc-submit-btn { width:100%; border-radius:12px; padding:11px; font-size:14px; font-weight:700; color:#fff; transition:all .15s ease; border:none; cursor:pointer; }
    .cc-submit-btn:active { transform:scale(.98); }
    .cc-submit-btn:disabled { opacity:.7; cursor:default; }

    .cc-map-wrap { position:relative; flex:1; min-width:0; min-height:720px; }
    .cc-map { position:absolute; inset:0; width:100%; height:100%; }
    .cc-map-distance { position:absolute; top:16px; left:16px; z-index:10; color:#4338ca; font-size:14px; font-weight:600; text-shadow:0 1px 2px #fff, 0 0 8px #fff; pointer-events:none; }
    .dark .cc-header-context span, .dark .cc-header-context strong, .dark .cc-card, .dark .cc-wrapper { border-color:#293142; background:#171b25; }
    .dark .cc-form-panel { border-color:#293142; background:#121620; }
    .dark .cc-address-col input, .dark .cc-checkbox-row span { color:#f8fafc; }
    .dark .cc-input, .dark .cc-textarea { border-color:#334155; background:#0f1420; color:#f8fafc; }
    .dark .cc-step-title { color:#94a3b8; }

    @media (max-width: 900px) {
        .cc-wrapper { height:auto; min-height:auto; flex-direction:column; border-radius:12px; }
        .cc-form-panel { width:100%; max-height:none; overflow-y:visible; border-right:none; border-bottom:1px solid #e5e7eb; }
        .cc-map-wrap { height:40vh; min-height:260px; }
        .cc-form-panel input, .cc-form-panel textarea, .cc-form-panel select { font-size:16px !important; }
    }
</style>
<div class="cc-wrapper">

    {{-- ═══ PANEL FORM ═══ --}}
    <div class="cc-form-panel" style="--cc-accent: {{ $activeSvc['color'] }}; --cc-accent-20: {{ $activeSvc['color'] }}26;">

        <p class="cc-step-title"><span>1</span>Dịch vụ</p>
        <div class="cc-card">
            <div class="cc-service-tabs">
                @foreach ($this::services() as $key => $svc)
                @php $active = $serviceType === $key; @endphp
                <button type="button" wire:click="selectService('{{ $key }}')" onclick="_onServiceChange()" wire:key="svc-{{ $key }}"
                    class="cc-service-tab {{ $active ? 'active' : '' }}"
                    style="{{ $active ? 'background:'.$svc['color'].'; box-shadow:0 3px 10px '.$svc['color'].'4d;' : '' }}">
                    <x-dynamic-component :component="$svc['icon']" />
                    <span>{{ $svc['label'] }}</span>
                </button>
                @endforeach
            </div>
        </div>

        {{-- Địa chỉ (chỉ nhập qua Autocomplete — bản đồ bên phải không nhận click gim nữa) --}}
        @php
            $pickupLabel = match($serviceType) {
                'shopping' => 'Điểm mua hàng',
                'topup'    => 'Địa chỉ nạp',
                'bike', 'motor', 'car' => 'Điểm đón',
                default    => 'Điểm lấy hàng',
            };
            $deliveryLabel = match($serviceType) {
                'bike', 'motor', 'car' => 'Điểm đến',
                default => 'Điểm giao hàng',
            };
            $needDelivery = $serviceType !== 'topup';
        @endphp
        <p class="cc-step-title"><span>2</span>Hành trình</p>
        <div class="cc-card" style="padding:4px 14px;">
            <div class="cc-address-row">
                <div class="cc-address-dot" style="background:#FF6B35; box-shadow:0 0 0 3px #FF6B3520;"></div>
                <div class="cc-address-col">
                    <label>{{ $pickupLabel }}</label>
                    <input id="cc-pickup-input" type="text" placeholder="Nhập địa chỉ..."
                        autocomplete="off"
                        value="{{ $data['pickup_address'] ?? '' }}" />
                </div>
                <button type="button" id="cc-pin-btn-pickup" class="cc-pin-btn" onclick="_togglePinMode('pickup')" title="Chọn điểm lấy hàng trên bản đồ">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18s6-5.686 6-10A6 6 0 1 0 4 8c0 4.314 6 10 6 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
                </button>
            </div>
            @if ($needDelivery)
            <div class="cc-address-row">
                <div class="cc-address-dot" style="background:#22c55e; box-shadow:0 0 0 3px #22c55e20;"></div>
                <div class="cc-address-col">
                    <label>{{ $deliveryLabel }}</label>
                    <input id="cc-delivery-input" type="text" placeholder="Nhập địa chỉ..."
                        autocomplete="off"
                        value="{{ $data['delivery_address'] ?? '' }}" />
                </div>
                <button type="button" id="cc-pin-btn-delivery" class="cc-pin-btn" onclick="_togglePinMode('delivery')" title="Chọn điểm giao hàng trên bản đồ">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18s6-5.686 6-10A6 6 0 1 0 4 8c0 4.314 6 10 6 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
                </button>
            </div>
            @endif
        </div>

        {{-- Form --}}
        <form wire:submit="placeOrder">
        <p class="cc-step-title"><span>3</span>Chi tiết đơn hàng</p>
        <div class="cc-card">

            @if ($serviceType === 'delivery')
            <div class="cc-field-row">
                <div class="cc-field"><label>SĐT điểm lấy</label><input type="text" class="cc-input" wire:model="data.pickup_phone" placeholder="09xx xxx xxx" /></div>
                <div class="cc-field"><label>SĐT điểm giao</label><input type="text" class="cc-input" wire:model="data.delivery_phone" placeholder="09xx xxx xxx" /></div>
            </div>
            <div class="cc-field">
                <label>Phí ship</label>
                <div class="cc-fee-wrap"><input type="number" class="cc-input" wire:model="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
            </div>
            <div class="cc-field"><label>Ghi chú</label><textarea class="cc-textarea" wire:model="data.order_note" rows="2" placeholder="Ghi chú cho tài xế..."></textarea></div>
            @endif

            @if ($serviceType === 'shopping')
            <div class="cc-field"><label>Mô tả hàng cần mua</label><textarea class="cc-textarea" wire:model="data.shopping_note" rows="2" placeholder="Ví dụ: 1 ly trà sữa size L, ít đá..."></textarea></div>
            <div class="cc-field"><label>SĐT khách</label><input type="text" class="cc-input" wire:model="data.delivery_phone" placeholder="09xx xxx xxx" /></div>
            <div class="cc-field">
                <label>Phí ship</label>
                <div class="cc-fee-wrap"><input type="number" class="cc-input" wire:model="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
            </div>
            @endif

            @if ($serviceType === 'topup')
            <div class="cc-field">
                <label>Số tiền nạp</label>
                <div class="cc-fee-wrap"><input type="number" class="cc-input" wire:model="data.cod_amount" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
            </div>
            <div class="cc-field"><label>SĐT khách</label><input type="text" class="cc-input" wire:model="data.pickup_phone" placeholder="09xx xxx xxx" /></div>
            <div class="cc-field">
                <label>Phí ship</label>
                <div class="cc-fee-wrap"><input type="number" class="cc-input" wire:model="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
            </div>
            <div class="cc-field"><label>Ghi chú <span style="text-transform:none; font-weight:400;">(tuỳ chọn)</span></label><textarea class="cc-textarea" wire:model="data.order_note" rows="2" placeholder="Ghi chú..."></textarea></div>
            @endif

            @if (in_array($serviceType, ['bike', 'motor', 'car']))
            <div class="cc-field"><label>SĐT khách</label><input type="text" class="cc-input" wire:model="data.pickup_phone" placeholder="09xx xxx xxx" /></div>
            <div class="cc-field">
                <label>Phí ship</label>
                <div class="cc-fee-wrap"><input type="number" class="cc-input" wire:model="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
            </div>
            <div class="cc-field"><label>Ghi chú cho tài xế</label><textarea class="cc-textarea" wire:model="data.order_note" rows="2" placeholder="Ghi chú..."></textarea></div>
            @endif

            <label class="cc-checkbox-row">
                <input type="checkbox" wire:model="isFreeship" class="cc-checkbox" />
                <span><strong>Freeship</strong> — <small>Khách không trả phí ship - admin trả.</small></span>
            </label>

        </div>

        <p class="cc-step-title" style="margin-top:10px;"><span>4</span>Điều phối tài xế</p>
        <div class="cc-card">
            <label style="display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin-bottom:4px;">Gán tài xế (tuỳ chọn)</label>
            <div class="cc-select-wrap">
                <select wire:model="assignedDriverId" class="cc-input cc-select">
                    <option value="">Để hệ thống tự chọn</option>
                    @foreach ($onlineDrivers as $d)
                    <option value="{{ $d['id'] }}">{{ $d['name'] }} — {{ $d['phone'] }}</option>
                    @endforeach
                </select>
                <svg class="cc-select-chevron" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 7.5L10 12.5L15 7.5" stroke="#9ca3af" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            @if (empty($onlineDrivers))
            <p style="font-size:12px; color:#9ca3af; margin-top:6px;">Chưa có tài xế online trong thành phố này.</p>
            @endif
        </div>

        <button type="submit" wire:loading.attr="disabled" class="cc-submit-btn" style="margin-top:8px; background:{{ $activeSvc['color'] }}; box-shadow:0 4px 16px {{ $activeSvc['color'] }}4d;">
            <span wire:loading.remove wire:target="placeOrder">Đặt đơn ngay</span>
            <span wire:loading wire:target="placeOrder">Đang xử lý...</span>
        </button>
        </form>
    </div>

    {{-- ═══ PANEL BẢN ĐỒ — mặc định chỉ quan sát (khoảng cách + tài xế online),
    chỉ nhận click gim địa chỉ khi bấm nút pin cạnh ô địa chỉ để bật "chế độ
    chọn trên bản đồ" (dự phòng khi Autocomplete không ra đúng địa chỉ) ═══ --}}
    {{-- wire:ignore bọc CẢ map lẫn 2 script bên dưới — Livewire re-render (đổi
    thành phố, chọn dịch vụ...) không được đụng vào, nếu không trình duyệt sẽ
    load lại toàn bộ Google Maps API mỗi lần commit → bản đồ chớp liên tục,
    Autocomplete bị vỡ giữa chừng, xoá mất địa chỉ vừa chọn. --}}
    <div class="cc-map-wrap">
        <div wire:ignore style="display:contents;">
            <div id="cc-main-map" class="cc-map"></div>
            <div id="cc-map-info-route" class="cc-map-distance"></div>

            <div id="cc-pin-hint" class="cc-pin-hint">
                <span id="cc-pin-hint-text"></span>
                <button type="button" onclick="_exitPinMode()">✕</button>
            </div>
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

let _mainMap, _geocoder, _pickupMarker, _deliveryMarker;
let _mapsReady = false;
let _pickupAc = null, _deliveryAc = null;
let _directionsRenderer = null;
let _driverMarkers = [];
let _pinModeTarget = null; // 'pickup' | 'delivery' | null — chế độ chọn địa chỉ bằng click trên bản đồ

const _cityCenter = { lat: {{ $defaultCenter['lat'] }}, lng: {{ $defaultCenter['lng'] }} };
const _cityCoords = {
    @foreach ($cities as $city)
    {{ $city->id }}: { lat: {{ (float)$city->lat }}, lng: {{ (float)$city->lng }}, name: @js($city->name) },
    @endforeach
};

// wire:ignore bọc cả card thông tin nên tên thành phố không còn tự cập nhật
// qua Blade re-render nữa — cập nhật thẳng DOM ở đây mỗi khi đổi thành phố.
function _onCityChange(cityId) {
    const c = _cityCoords[cityId];
    if (!_mainMap || !c) return;
    _cityCenter.lat = c.lat;
    _cityCenter.lng = c.lng;
    _mainMap.panTo(c);
    _mainMap.setZoom(14);
    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
    if (_deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; }
    _clearRoute();
    _clearDriverMarkers();
    _exitPinMode();
    const pi = document.getElementById('cc-pickup-input');
    const di = document.getElementById('cc-delivery-input');
    if (pi) pi.value = '';
    if (di) di.value = '';
    _updateAutocompleteBounds();
}

// Đổi loại dịch vụ → xoá sạch pin/route/ô địa chỉ ngay lập tức (không đợi
// Livewire round-trip xong mới dọn — tránh cảm giác dữ liệu cũ còn sót lại).
function _onServiceChange() {
    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
    if (_deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; }
    _clearRoute();
    _clearDriverMarkers();
    _exitPinMode();
    const pi = document.getElementById('cc-pickup-input');
    const di = document.getElementById('cc-delivery-input');
    if (pi) pi.value = '';
    if (di) di.value = '';
}

// ── Init map ───────────────────────────────────────────────────────────────
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

    // Click bản đồ CHỈ có tác dụng khi đang bật "chế độ chọn trên bản đồ"
    // (bấm nút pin cạnh ô địa chỉ) — dự phòng cho lúc Autocomplete không ra
    // đúng địa chỉ cần tìm. Mặc định bản đồ chỉ để xem, click không làm gì.
    _mainMap.addListener('click', (e) => {
        if (!_pinModeTarget) return;
        const target = _pinModeTarget;
        const lat = e.latLng.lat(), lng = e.latLng.lng();
        _exitPinMode();
        _geocoder.geocode({ location: { lat, lng } }, (res, status) => {
            const addr = (status === 'OK' && res[0]) ? res[0].formatted_address : `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            if (target === 'pickup') {
                const pi = document.getElementById('cc-pickup-input');
                if (pi) pi.value = addr;
                _setPickupPin(lat, lng);
                @this.call('setPickupLocation', addr, lat, lng).then(() => {
                    _updateFeeInfo();
                    _refreshDriverMarkers();
                });
            } else {
                const di = document.getElementById('cc-delivery-input');
                if (di) di.value = addr;
                _setDeliveryPin(lat, lng);
                @this.call('setDeliveryLocation', addr, lat, lng).then(_updateFeeInfo);
            }
        });
    });

    _initSearchAutocomplete();
}

// Bật/tắt "chế độ chọn trên bản đồ" cho 1 ô địa chỉ cụ thể — bấm lại nút
// đang bật thì tắt, bấm nút còn lại thì chuyển sang ô đó.
function _togglePinMode(target) {
    if (_pinModeTarget === target) { _exitPinMode(); return; }
    _pinModeTarget = target;
    document.querySelectorAll('.cc-pin-btn').forEach((b) => b.classList.remove('active'));
    const btn = document.getElementById(`cc-pin-btn-${target}`);
    if (btn) btn.classList.add('active');
    const hint = document.getElementById('cc-pin-hint');
    const hintText = document.getElementById('cc-pin-hint-text');
    if (hintText) hintText.textContent = `📍 Bấm vào bản đồ để chọn ${target === 'pickup' ? 'điểm lấy hàng' : 'điểm giao hàng'}`;
    if (hint) hint.style.display = 'flex';
    if (_mainMap) _mainMap.setOptions({ draggableCursor: 'crosshair' });
}

function _exitPinMode() {
    _pinModeTarget = null;
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
            @this.call('setPickupLocation', p.formatted_address || pickupInput.value, lat, lng).then(() => {
                _updateFeeInfo();
                _refreshDriverMarkers();
            });
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
            @this.call('setDeliveryLocation', p.formatted_address || deliveryInput.value, lat, lng).then(_updateFeeInfo);
            _setDeliveryPin(lat, lng);
        });
    }
}

// ── Markers điểm lấy/giao ──────────────────────────────────────────────────────
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
        zIndex: 20,
    });
    _mainMap.panTo({ lat, lng });
    _mainMap.setZoom(15);
    _fitBounds();
}

function _setDeliveryPin(lat, lng) {
    if (_deliveryMarker) _deliveryMarker.setMap(null);
    _deliveryMarker = new google.maps.Marker({
        position: { lat, lng }, map: _mainMap,
        icon: _pinIcon('#22c55e', 'B'),
        title: 'Điểm giao',
        zIndex: 20,
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

// ── Route + khoảng cách hiển thị cho tổng đài ─────────────────────────────────
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
            const leg = result.routes[0]?.legs?.[0];
            _updateRouteInfo(leg ? `≈ ${leg.distance.text} · ~${leg.duration.text}` : '');
        }
    });
}

function _clearRoute() {
    if (_directionsRenderer) {
        _directionsRenderer.setDirections({ routes: [] });
    }
    _updateRouteInfo('');
    const feeEl = document.getElementById('cc-map-info-fee');
    if (feeEl) feeEl.textContent = '';
}

function _updateRouteInfo(text) {
    const el = document.getElementById('cc-map-info-route');
    if (el) el.textContent = text;
}

// Đọc phí ship vừa được server tự tính (suggestShippingFee()) sau khi đủ 2
// điểm, hiện ngay dưới dòng khoảng cách để tổng đài thấy luôn không cần nhìn
// xuống form.
function _updateFeeInfo() {
    const el = document.getElementById('cc-map-info-fee');
    if (!el) return;
    const fee = @this.previewFee;
    el.textContent = fee ? `Phí gợi ý: ${Number(fee).toLocaleString('vi-VN')}đ` : '';
}

// Tài xế trong 4km quanh điểm lấy — đọc thẳng @this.nearbyDrivers (đã được
// backend tính xong ngay trong request setPickupLocation(), không cần gọi
// thêm request nào khác). CHỈ gọi hàm này SAU KHI @this.call('setPickupLocation'
// | reorder khôi phục) đã resolve — TUYỆT ĐỐI không gọi từ trong
// Livewire.hook('commit') (xem chú thích ở refreshNearbyDrivers() phía
// backend, đã từng gây bản đồ chớp vô hạn).
function _refreshDriverMarkers() {
    if (!_mainMap) return;
    const drivers = @this.nearbyDrivers || [];
    _driverMarkers.forEach((m) => m.setMap(null));
    _driverMarkers = drivers.map((d) => new google.maps.Marker({
        position: { lat: d.lat, lng: d.lng }, map: _mainMap,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 6,
            fillColor: '#22c55e', fillOpacity: 1,
            strokeColor: '#fff', strokeWeight: 2,
        },
        zIndex: 5,
    }));
    const wrap = document.getElementById('cc-map-info-drivers');
    const label = document.getElementById('cc-driver-label');
    if (label) label.textContent = `${drivers.length} tài xế trong 4km`;
    if (wrap) wrap.style.display = 'flex';
}

function _clearDriverMarkers() {
    _driverMarkers.forEach((m) => m.setMap(null));
    _driverMarkers = [];
    const wrap = document.getElementById('cc-map-info-drivers');
    if (wrap) wrap.style.display = 'none';
}

// ── Livewire: đồng bộ pin khi state đổi ───────────────────────────────────────
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
                    const pi = document.getElementById('cc-pickup-input');
                    if (pi) pi.value = '';
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
    if (pLat && pLng) { _setPickupPin(pLat, pLng); _refreshDriverMarkers(); }
    if (dLat && dLng) _setDeliveryPin(dLat, dLng);
};
</script>

{{-- Google Maps API --}}
<script
    src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&callback=ccInitGoogleMaps&loading=async"
    async defer
></script>
</div>

</x-filament-panels::page>
