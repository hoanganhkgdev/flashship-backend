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
    $err = fn (string $k) => $fieldErrors[$k] ?? null;
    $isRide = in_array($serviceType, ['bike', 'motor', 'car']);
    $contactLabel = match ($serviceType) {
        'delivery' => 'SĐT shop / người gọi',
        'shopping' => 'SĐT khách nhận hàng',
        default => 'SĐT khách',
    };
    $deliveryOptional = ! in_array($serviceType, ['shopping', 'topup'], true);
    $noDeliveryYet = $deliveryOptional && trim((string) ($data['delivery_address'] ?? '')) === '';
    $nightFee = (int) $previewNightSurcharge;
    $shortLabels = ['delivery' => 'Lấy hộ', 'shopping' => 'Mua hộ', 'topup' => 'Nạp/Rút', 'bike' => 'Xe ôm', 'motor' => 'Lái xe máy', 'car' => 'Lái ô tô'];
@endphp

{{-- Màn hình tổng đài thường nhỏ: tiêu đề một dòng, banner gọn, form cuộn trong khung và nút Đặt đơn luôn dính đáy --}}
<div class="cc-top">
    <h1>Tổng đài đặt đơn</h1>
    <div class="cc-header-context">
        <span>{{ $currentCityName ?: 'Chưa chọn khu vực' }}</span>
        <strong>{{ $activeSvc['label'] }}</strong>
    </div>
    <p>Hỏi SĐT trước (Enter/Tab để tra khách) · Ctrl + Enter để đặt nhanh</p>
</div>

@if ($resultOrderCode && ! $resultWarning)
<div class="cc-banner ok">
    <x-heroicon-o-check-circle class="h-4 w-4" />
    <span>Đặt đơn thành công <b>#{{ $resultOrderCode }}</b>@if ($resultFee !== null) · {{ number_format($resultFee, 0, ',', '.') }}đ @endif</span>
    <button type="button" wire:click="clearResult" title="Đóng"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
</div>
@endif

@if ($resultWarning)
<div class="cc-banner warn">
    <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
    <span>{{ $resultWarning }}</span>
    <button type="button" wire:click="clearResult" title="Đóng"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
</div>
@endif

@if ($resultError)
<div class="cc-banner err">
    <x-heroicon-o-exclamation-circle class="h-4 w-4" />
    <span>{{ $resultError }}</span>
</div>
@endif

<style>
    body { overflow-y:auto !important; }
    /* Dẹp khoảng trống mặc định của trang để nhường chỗ cho form */
    .fi-page:has(.cc-wrapper) > section { row-gap:.35rem !important; padding-block:.35rem !important; }
    .fi-page:has(.cc-wrapper) > section > div > .grid { row-gap:0 !important; }
    .fi-main:has(.cc-wrapper) { padding-block:.5rem !important; }

    .cc-top { display:flex; align-items:center; flex-wrap:wrap; gap:6px 12px; margin:-6px 0 8px; }
    .cc-top h1 { font-size:1.15rem; font-weight:750; line-height:1.2; color:var(--flashship-ink, #0f172a); }
    .dark .cc-top h1 { color:#f8fafc; }
    .cc-top p { margin-left:auto; color:#94a3b8; font-size:var(--fs-xs); }
    .cc-header-context { display:flex; align-items:center; gap:6px; }
    .cc-header-context span, .cc-header-context strong { padding:3px 8px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; font-size:var(--fs-xs); }
    .cc-header-context span { color:#64748b; font-weight:500; }
    .cc-header-context strong { color:{{ $activeSvc['color'] }}; font-weight:650; }

    .cc-banner { display:flex; align-items:center; gap:8px; margin-bottom:6px; padding:6px 10px; border:1px solid; border-radius:10px; font-size:var(--fs-sm); font-weight:500; line-height:1.3; }
    .cc-banner span { flex:1; min-width:0; }
    .cc-banner > button { flex:none; opacity:.7; cursor:pointer; }
    .cc-banner.ok { border-color:#86efac; background:#f0fdf4; color:#166534; }
    .cc-banner.warn { border-color:#fcd34d; background:#fffbeb; color:#92400e; }
    .cc-banner.err { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
    .cc-banner .cc-banner__btn { opacity:1; padding:2px 10px; border-radius:7px; background:#dc2626; color:#fff; font-size:var(--fs-xs); font-weight:700; }
    .dark .cc-banner.ok { background:#0f2a1a; color:#86efac; border-color:#166534; }
    .dark .cc-banner.warn { background:#2a2110; color:#fcd34d; border-color:#92400e; }
    .dark .cc-banner.err { background:#2a1214; color:#fca5a5; border-color:#7f1d1d; }

    /* Khung chính cao đúng phần màn hình còn lại: form cuộn bên trong, bản đồ + đơn vừa đặt chiếm cột phải */
    .cc-wrapper { position:relative; height:calc(100vh - 130px); min-height:420px; border-radius:14px; overflow:hidden; border:1px solid #e5e7eb; display:flex; align-items:stretch; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.03),0 10px 24px rgba(15,23,42,.04); }

    .cc-form-panel { width:min(500px, 46%); flex-shrink:0; display:flex; flex-direction:column; min-height:0; background:#f8fafc; border-right:1px solid #e5e7eb; }
    .cc-form { display:flex; flex:1; flex-direction:column; min-height:0; }
    .cc-scroll { flex:1; min-height:0; display:flex; flex-direction:column; gap:5px; padding:6px 10px; overflow-y:auto; scrollbar-width:thin; }

    .cc-card { --cc-card-bg:#fff; background:var(--cc-card-bg); border:1px solid #eef0f2; border-radius:11px; padding:10px 9px 7px; }
    .dark .cc-card { --cc-card-bg:#171b25; }
    /* Nhãn nằm trên đường viền ô (kiểu fieldset): tiết kiệm một dòng chiều cao cho mỗi hàng ô nhập */
    .cc-row2 > div, .cc-fld { position:relative; }
    .cc-row2 > div > .cc-lbl, .cc-fld > .cc-lbl { position:absolute; top:-7px; left:8px; z-index:2; margin:0; padding:0 4px; background:var(--cc-card-bg, #fff); line-height:1.2; pointer-events:none; }
    .cc-lbl { display:block; margin-bottom:2px; color:#94a3b8; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    .cc-lbl small { text-transform:none; font-weight:400; letter-spacing:0; }

    .cc-service-tabs { display:grid; grid-template-columns:repeat(3, 1fr); gap:4px; }
    .cc-service-tab { display:flex; align-items:center; justify-content:center; gap:5px; border-radius:8px; padding:5px 4px; color:#6b7280; background:#f1f5f9; border:1px solid transparent; transition:all .15s ease; }
    .cc-service-tab:hover { background:#e2e8f0; }
    .cc-service-tab.active { color:#fff; }
    .cc-service-tab svg { width:14px; height:14px; flex:none; }
    .cc-service-tab span { font-size:var(--fs-xs); font-weight:650; line-height:1.1; white-space:nowrap; }

    .cc-address-row { display:flex; align-items:flex-start; gap:7px; padding:3px 0; }
    .cc-addr-tag { flex:none; width:48px; margin-top:6px; color:#94a3b8; font-size:10px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
    .cc-address-col { padding-top:4px; }
    .cc-address-row + .cc-address-row { border-top:1px dashed #eef0f2; }
    .cc-address-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:10px; }
    .cc-address-col { flex:1; min-width:0; }
    .cc-address-col input { width:100%; height:22px; border:none; outline:none; font-size:var(--fs-sm); color:#111827; background:transparent; padding:0; box-shadow:none; }
    .cc-address-col input:focus { border:none; outline:none; box-shadow:none; }
    .cc-address-col input::placeholder { color:#c1c5cb; }
    .cc-pin-btn { flex-shrink:0; width:26px; height:26px; border-radius:7px; margin-top:1px; border:1px solid #e5e7eb; background:#f8fafc; color:#6b7280; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .15s ease; }
    .cc-pin-btn svg { width:14px; height:14px; }
    .cc-pin-btn:hover { background:#f1f5f9; color:#111827; }
    .cc-pin-btn.active { background:var(--cc-accent, #4F46E5); border-color:var(--cc-accent, #4F46E5); color:#fff; }

    .cc-pin-hint { display:none; position:absolute; top:12px; left:50%; transform:translateX(-50%); z-index:11; align-items:center; gap:10px; background:#111827; color:#fff; font-size:var(--fs-sm); font-weight:600; padding:7px 8px 7px 14px; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.25); white-space:nowrap; }
    .cc-pin-hint button { flex-shrink:0; width:20px; height:20px; border-radius:50%; border:none; background:rgba(255,255,255,0.15); color:#fff; cursor:pointer; font-size:var(--fs-sm); line-height:1; }

    .cc-row2 { display:grid; grid-template-columns:1fr 1fr; gap:7px; }
    .cc-input, .cc-textarea { width:100%; border:1px solid #e5e7eb; border-radius:8px; padding:4px 9px; font-size:13px; color:#111827; background:#f8fafc; outline:none; transition:border-color .15s ease, background .15s ease, box-shadow .15s ease; }
    .cc-input { height:30px; }
    .cc-input::placeholder, .cc-textarea::placeholder { color:#c1c5cb; }
    .cc-input[type="number"] { -moz-appearance:textfield; }
    .cc-input[type="number"]::-webkit-outer-spin-button, .cc-input[type="number"]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .cc-input:focus, .cc-textarea:focus, .cc-form-panel select:focus { border-color:var(--cc-accent, #4F46E5); background:#fff; box-shadow:0 0 0 3px var(--cc-accent-20, rgba(79,70,229,.15)); }
    .cc-input.has-err, .cc-textarea.has-err, .cc-card.has-err { border-color:#ef4444 !important; background:#fef2f2; }
    .cc-err { margin-top:2px; color:#dc2626; font-size:11px; line-height:1.3; }
    .cc-hint { margin-top:3px; color:#94a3b8; font-size:11px; line-height:1.3; }
    .cc-hint.warn { color:#b45309; }
    .cc-textarea { resize:none; line-height:1.35; }
    .cc-fee-wrap { position:relative; }
    .cc-fee-wrap input { padding-right:24px; }
    .cc-fee-suffix { position:absolute; right:9px; top:50%; transform:translateY(-50%); font-size:var(--fs-xs); font-weight:600; color:#9ca3af; pointer-events:none; }

    .cc-select-wrap { position:relative; }
    .cc-select { appearance:none; -webkit-appearance:none; -moz-appearance:none; background-image:none !important; padding-right:26px; cursor:pointer; text-overflow:ellipsis; }
    .cc-select::-ms-expand { display:none; }
    .cc-select-chevron { position:absolute; right:8px; top:50%; width:14px; height:14px; transform:translateY(-50%); pointer-events:none; }

    .cc-check { display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-size:var(--fs-xs); color:#111827; }
    .cc-check input { width:14px; height:14px; accent-color:var(--cc-accent, #4F46E5); cursor:pointer; }
    .cc-feeline { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:3px 10px; margin-top:5px; font-size:11px; color:#94a3b8; }
    .cc-feeline button { color:var(--cc-accent); font-weight:700; cursor:pointer; }

    .cc-customer { display:flex; align-items:center; gap:4px 8px; margin-top:6px; padding:4px 8px; border-radius:8px; background:#f0fdf4; color:#166534; font-size:var(--fs-xs); }
    .cc-customer.risky { background:#fef2f2; color:#b91c1c; }
    .cc-customer b { font-size:var(--fs-sm); }
    .cc-customer > span { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cc-customer > button { flex:none; padding:1px 8px; border:1px solid currentColor; border-radius:999px; font-size:11px; font-weight:700; cursor:pointer; }
    .cc-history { margin-top:4px; max-height:132px; overflow-y:auto; }
    .cc-history button { display:block; width:100%; padding:4px 2px; border-top:1px solid rgba(148,163,184,.25); text-align:left; font-size:11px; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cc-history button:hover { background:rgba(148,163,184,.1); }

    /* Thanh đặt đơn dính đáy khung form: phí ở trái, nút đặt ở phải */
    .cc-bar { flex:none; display:flex; align-items:center; gap:10px; padding:8px 10px; border-top:1px solid #e5e7eb; background:#f8fafc; }
    .cc-bar__info { display:grid; flex:1; min-width:0; gap:1px; color:#64748b; font-size:11px; line-height:1.25; }
    .cc-bar__info b { color:#0f172a; font-size:var(--fs-sm); }
    .cc-bar__info button { margin-left:5px; padding:0 7px; border:1px solid var(--cc-accent, #4F46E5); border-radius:999px; color:var(--cc-accent, #4F46E5); font-size:11px; font-weight:700; cursor:pointer; }
    .cc-submit-btn { flex:none; min-width:170px; border-radius:10px; padding:9px 14px; font-size:var(--fs-sm); font-weight:700; color:#fff; transition:all .15s ease; border:none; cursor:pointer; }
    .cc-submit-btn:active { transform:scale(.98); }
    .cc-submit-btn:disabled { opacity:.7; cursor:default; }

    .cc-map-wrap { position:relative; flex:1; min-width:0; display:flex; flex-direction:column; }
    .cc-map-area { position:relative; flex:1; min-height:160px; }
    .cc-map { position:absolute; inset:0; width:100%; height:100%; }
    .cc-map-distance { position:absolute; top:10px; left:10px; z-index:10; color:#4338ca; font-size:var(--fs-sm); font-weight:600; text-shadow:0 1px 2px #fff, 0 0 8px #fff; pointer-events:none; }

    .cc-recent { flex:none; height:clamp(120px, 32%, 210px); display:flex; flex-direction:column; border-top:1px solid #e5e7eb; background:#fff; }
    .cc-recent__head { flex:none; display:flex; align-items:center; justify-content:space-between; padding:5px 10px; border-bottom:1px solid #eef0f2; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#64748b; }
    .cc-recent__head em { font-style:normal; font-weight:500; text-transform:none; letter-spacing:0; color:#94a3b8; }
    #cc-recent-list { flex:1; min-height:0; overflow-y:auto; }
    .cc-ro { display:grid; grid-template-columns:62px minmax(96px, 150px) minmax(0, 1fr) auto auto; align-items:center; gap:8px; padding:4px 10px; border-bottom:1px solid #f1f5f9; border-left:3px solid #cbd5e1; font-size:12px; }
    .cc-ro.s-pending { border-left-color:#f59e0b; } .cc-ro.s-assigned, .cc-ro.s-processing { border-left-color:#3b82f6; } .cc-ro.s-completed { border-left-color:#22c55e; } .cc-ro.s-cancelled { border-left-color:#9ca3af; opacity:.65; } .cc-ro.stopped { border-left-color:#ef4444; background:rgba(254,226,226,.45); }
    .cc-ro a.code { color:#ea580c; font-weight:750; }
    .cc-ro .who, .cc-ro .route { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cc-ro .route { color:#64748b; }
    .cc-ro .route i { color:#b45309; }
    .cc-ro .st { font-weight:700; white-space:nowrap; text-align:right; }
    .cc-ro .st small { font-weight:500; color:#94a3b8; }
    .cc-ro .acts { display:flex; gap:4px; min-width:0; }
    .cc-ro .acts button { padding:0 8px; border:1px solid #e2e8f0; border-radius:999px; font-size:11px; font-weight:600; color:#334155; cursor:pointer; }
    .cc-recent__empty { padding:14px 12px; color:#94a3b8; font-size:var(--fs-xs); text-align:center; }

    .dark .cc-header-context span, .dark .cc-header-context strong, .dark .cc-card, .dark .cc-wrapper { border-color:#293142; background:#171b25; }
    .dark .cc-form-panel { border-color:#293142; background:#121620; }
    .dark .cc-address-col input, .dark .cc-check { color:#f8fafc; }
    .dark .cc-input, .dark .cc-textarea { border-color:#334155; background:#0f1420; color:#f8fafc; }
    .dark .cc-input.has-err, .dark .cc-textarea.has-err, .dark .cc-card.has-err { background:#2a1214; }
    .dark .cc-service-tab { background:#1c2331; color:#cbd5e1; } .dark .cc-service-tab.active { color:#fff; }
    .dark .cc-bar { border-color:#293142; background:#121620; }
    .dark .cc-bar__info b { color:#f8fafc; }
    .dark .cc-customer { background:#0f2a1a; color:#86efac; } .dark .cc-customer.risky { background:#3a1414; color:#fca5a5; }
    .dark .cc-recent { border-color:#293142; background:#171b25; }
    .dark .cc-recent__head { border-color:#293142; color:#94a3b8; }
    .dark .cc-ro { border-bottom-color:#232b3b; } .dark .cc-ro.stopped { background:rgba(127,29,29,.3); }
    .dark .cc-ro .route { color:#94a3b8; } .dark .cc-ro .acts button { border-color:#334155; color:#e2e8f0; }

    @media (max-width: 1100px) { .cc-ro { grid-template-columns:58px minmax(0, 1fr) auto auto; } .cc-ro .who { display:none; } }
    @media (max-width: 900px) {
        .cc-wrapper { height:auto; flex-direction:column; border-radius:12px; }
        .cc-form-panel { width:100%; border-right:none; border-bottom:1px solid #e5e7eb; }
        .cc-scroll { overflow:visible; }
        .cc-map-area { height:40vh; min-height:240px; flex:none; }
        .cc-recent { height:auto; max-height:280px; }
        .cc-form-panel input, .cc-form-panel textarea, .cc-form-panel select { font-size:var(--fs-base) !important; }
    }

    /* ── Cỡ lớn, thoáng: nhập liệu to và dễ bấm khi đang nghe máy ───────────────────────────── */
    .cc-top { margin:-4px 0 12px; }
    .cc-top h1 { font-size:1.45rem; }
    .cc-top p { font-size:var(--fs-sm); }
    .cc-header-context span, .cc-header-context strong { padding:5px 12px; font-size:var(--fs-sm); border-radius:9px; }
    .cc-banner { padding:10px 14px; margin-bottom:10px; font-size:var(--fs-base, 14px); border-radius:12px; }
    .cc-wrapper { border-radius:18px; box-shadow:0 1px 2px rgba(15,23,42,.04), 0 14px 34px rgba(15,23,42,.07); }
    @media (min-width: 901px) { .cc-form-panel { width:clamp(470px, 50%, 600px); } }
    .cc-form-panel { background:#f4f6fa; }
    .dark .cc-form-panel { background:#10141d; }
    .cc-scroll { gap:12px; padding:14px 16px; }
    .cc-card { padding:16px 14px 12px; border-radius:14px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
    .cc-lbl { top:-8px !important; font-size:11px; }
    .cc-row2 { gap:14px; }
    .cc-input { height:42px; padding:6px 13px; border-radius:10px; font-size:15px; }
    .cc-textarea { padding:9px 13px; border-radius:10px; font-size:15px; }
    .cc-fee-wrap input { padding-right:30px; }
    .cc-fee-suffix { right:12px; font-size:var(--fs-sm); }
    .cc-select { padding-right:34px; }
    .cc-select-chevron { right:11px; width:16px; height:16px; }

    .cc-service-tabs { gap:8px; }
    .cc-service-tab { padding:11px 6px; gap:8px; border-radius:11px; }
    .cc-service-tab svg { width:18px; height:18px; }
    .cc-service-tab span { font-size:14px; }

    .cc-address-row { gap:11px; padding:6px 0; align-items:center; }
    .cc-address-row + .cc-address-row { border-top:1px solid #eef0f4; }
    .dark .cc-address-row + .cc-address-row { border-top-color:#232b3b; }
    .cc-address-dot { width:11px; height:11px; margin-top:0; }
    .cc-addr-tag { width:58px; margin-top:0; font-size:11.5px; }
    .cc-address-col { padding-top:0; }
    .cc-address-col input { height:34px; font-size:15.5px; }
    .cc-pin-btn { width:34px; height:34px; margin-top:0; border-radius:9px; }
    .cc-pin-btn svg { width:17px; height:17px; }
    .cc-err, .cc-hint { font-size:12.5px; margin-top:4px; }

    .cc-feeline { margin-top:10px; font-size:13px; }
    .cc-check { font-size:14px; }
    .cc-check input { width:17px; height:17px; }
    .cc-customer { margin-top:10px; padding:8px 12px; font-size:13.5px; border-radius:10px; }
    .cc-customer b { font-size:var(--fs-base, 14px); }
    .cc-customer > button { padding:3px 11px; font-size:12px; }
    .cc-history button { padding:7px 4px; font-size:12.5px; }

    .cc-bar { padding:14px 16px; gap:16px; }
    .cc-bar__info { font-size:13px; gap:3px; }
    .cc-bar__info b { font-size:17px; }
    .cc-bar__info button { padding:1px 10px; font-size:12px; }
    .cc-submit-btn { min-width:210px; padding:14px 20px; border-radius:13px; font-size:16px; }

    .cc-recent { height:clamp(160px, 36%, 270px); }
    .cc-recent__head { padding:10px 16px; font-size:12px; }
    .cc-ro { grid-template-columns:76px minmax(110px, 180px) minmax(0, 1fr) auto auto; gap:14px; padding:10px 16px; font-size:14px; }
    .cc-ro .acts button { padding:3px 14px; font-size:13px; }
    .cc-ro .st small { font-size:12px; }
    .cc-recent__empty { padding:20px 14px; font-size:var(--fs-sm); }
    .cc-map-distance { font-size:var(--fs-base, 14px); top:14px; left:14px; }

    /* Cỡ lớn thì để trang cuộn tự nhiên: thanh Đặt đơn dính đáy màn hình, bản đồ dính đầu màn hình */
    @media (min-width: 901px) {
        .cc-wrapper { height:auto !important; min-height:calc(100vh - 140px); overflow:clip; }
        .cc-scroll { overflow:visible; }
        .cc-bar { position:sticky; bottom:0; z-index:20; box-shadow:0 -10px 24px rgba(15,23,42,.07); }
        .dark .cc-bar { box-shadow:0 -10px 24px rgba(0,0,0,.35); }
        .cc-map-wrap { position:sticky; top:12px; align-self:flex-start; height:calc(100vh - 110px); min-height:420px; }
    }

    /* Dòng "Đơn vừa đặt" hai tầng: tầng 1 = khách · trạng thái · nút; tầng 2 = hành trình. Không còn chèn nhau ở mọi độ rộng. */
    .cc-ro { grid-template-columns:70px minmax(0, 1fr) auto auto !important; column-gap:12px; row-gap:2px; align-items:center; }
    .cc-ro a.code { grid-column:1; grid-row:1 / span 2; align-self:center; }
    .cc-ro .who { display:block !important; grid-column:2; grid-row:1; min-width:0; font-weight:600; }
    .cc-ro .st { grid-column:3; grid-row:1; }
    .cc-ro .acts { grid-column:4; grid-row:1; justify-content:flex-end; }
    .cc-ro .route { grid-column:2 / -1; grid-row:2; min-width:0; font-size:12.5px; }

    .cc-map-chip { position:absolute; left:12px; bottom:30px; z-index:10; display:flex; flex-direction:column; gap:3px; padding:7px 12px; border-radius:11px; background:rgba(255,255,255,.95); box-shadow:0 2px 10px rgba(15,23,42,.18); color:#0f172a; font-size:13px; font-weight:650; pointer-events:none; }
    .cc-map-chip .cc-legend { display:flex; align-items:center; gap:5px; color:#64748b; font-size:11.5px; font-weight:500; }
    .cc-map-chip .cc-legend i { display:inline-block; width:9px; height:9px; border-radius:50%; margin-left:6px; }
    .cc-map-chip .cc-legend i:first-child { margin-left:0; }
    .dark .cc-map-chip { background:rgba(23,27,37,.95); color:#f8fafc; }
</style>

<div class="cc-wrapper">

    {{-- ═══ PANEL FORM ═══ --}}
    <div class="cc-form-panel" style="--cc-accent: {{ $activeSvc['color'] }}; --cc-accent-20: {{ $activeSvc['color'] }}26;">
      <form id="cc-form" class="cc-form" wire:submit="placeOrder"
            onkeydown="if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') { event.preventDefault(); if (event.target.type === 'tel') event.target.blur(); }">
        <div class="cc-scroll">

            {{-- Khách: hỏi SĐT trước --}}
            <div class="cc-card {{ $err('contact_phone') ? 'has-err' : '' }}" x-data="{ hist: false }">
                <div class="cc-row2">
                    <div>
                        <label class="cc-lbl">{{ $contactLabel }}@if ($serviceType === 'delivery') <small>(tuỳ chọn)</small>@endif</label>
                        <input type="tel" inputmode="tel" class="cc-input {{ $err('contact_phone') ? 'has-err' : '' }}" wire:model.blur="data.contact_phone" placeholder="09xx xxx xxx" autocomplete="off" />
                    </div>
                    <div>
                        <label class="cc-lbl">Tên <small>(tuỳ chọn)</small></label>
                        <input type="text" class="cc-input" wire:model.blur="data.contact_name" placeholder="Tên shop / khách" autocomplete="off" />
                    </div>
                </div>
                @if ($err('contact_phone'))<p class="cc-err">{{ $err('contact_phone') }}</p>@endif

                @if (! empty($customer))
                <div class="cc-customer {{ $customer['risky'] ? 'risky' : '' }}">
                    <b>{{ $customer['name'] ?: 'Khách quen' }}</b>
                    <span>{{ $customer['total'] }} đơn · {{ $customer['cancelled'] }} hủy @if ($customer['risky']) · HAY HỦY — xác nhận kỹ @endif</span>
                    @if (! empty($history))<button type="button" x-on:click="hist = ! hist" x-text="hist ? 'Ẩn đơn cũ' : 'Đơn cũ ({{ count($history) }})'"></button>@endif
                </div>
                @endif
                @if (! empty($history))
                <div class="cc-history" x-show="hist" x-cloak>
                    @foreach ($history as $h)
                    <button type="button" wire:click="useHistory({{ $h['id'] }})" x-on:click="hist = false" wire:key="hist-{{ $h['id'] }}" title="Bấm để điền lại hành trình">
                        <b>#{{ $h['code'] }}</b> {{ $h['when'] }}{{ $h['status'] === 'cancelled' ? ' · hủy' : '' }} · {{ \Illuminate\Support\Str::limit($h['pickup'], 30) }} → {{ $h['delivery'] ? \Illuminate\Support\Str::limit($h['delivery'], 30) : 'chưa có điểm giao' }}
                    </button>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Dịch vụ --}}
            <div class="cc-service-tabs">
                @foreach ($this::services() as $key => $svc)
                @php $active = $serviceType === $key; @endphp
                <button type="button" wire:click="selectService('{{ $key }}')" wire:key="svc-{{ $key }}" title="{{ $svc['label'] }}"
                    class="cc-service-tab {{ $active ? 'active' : '' }}"
                    style="{{ $active ? 'background:'.$svc['color'].'; box-shadow:0 2px 8px '.$svc['color'].'4d;' : '' }}">
                    <x-dynamic-component :component="$svc['icon']" />
                    <span>{{ $shortLabels[$key] ?? $svc['label'] }}</span>
                </button>
                @endforeach
            </div>

            {{-- Hành trình --}}
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
                $pickupShort = match($serviceType) { 'shopping' => 'Mua tại', 'topup' => 'Nạp tại', 'bike', 'motor', 'car' => 'Đón', default => 'Lấy' };
                $deliveryShort = in_array($serviceType, ['bike', 'motor', 'car'], true) ? 'Đến' : 'Giao';
            @endphp
            <div class="cc-card {{ $err('pickup_address') || $err('delivery_address') ? 'has-err' : '' }}" style="padding:2px 10px;">
                <div class="cc-address-row">
                    <div class="cc-address-dot" style="background:#FF6B35; box-shadow:0 0 0 3px #FF6B3520;"></div>
                    <span class="cc-addr-tag" title="{{ $pickupLabel }}">{{ $pickupShort }}</span>
                    <div class="cc-address-col">
                        <input id="cc-pickup-input" type="text" placeholder="{{ $pickupLabel }}: nhập địa chỉ..." autocomplete="off"
                            value="{{ $data['pickup_address'] ?? '' }}" onblur="_syncTyped('pickup')" />
                        @if ($err('pickup_address'))<p class="cc-err">{{ $err('pickup_address') }}</p>@endif
                    </div>
                    <button type="button" id="cc-pin-btn-pickup" class="cc-pin-btn" onclick="_togglePinMode('pickup')" title="Chọn điểm lấy hàng trên bản đồ">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18s6-5.686 6-10A6 6 0 1 0 4 8c0 4.314 6 10 6 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
                    </button>
                </div>
                @if ($needDelivery)
                <div class="cc-address-row">
                    <div class="cc-address-dot" style="background:#22c55e; box-shadow:0 0 0 3px #22c55e20;"></div>
                    <span class="cc-addr-tag" title="{{ $deliveryLabel }}">{{ $deliveryShort }}</span>
                    <div class="cc-address-col">
                        <input id="cc-delivery-input" type="text" placeholder="{{ $deliveryOptional ? 'Tuỳ chọn — để trống nếu khách chưa cho' : $deliveryLabel.': nhập địa chỉ...' }}" autocomplete="off"
                            value="{{ $data['delivery_address'] ?? '' }}" onblur="_syncTyped('delivery')" />
                        @if ($err('delivery_address'))<p class="cc-err">{{ $err('delivery_address') }}</p>@endif
                    </div>
                    <button type="button" id="cc-pin-btn-delivery" class="cc-pin-btn" onclick="_togglePinMode('delivery')" title="Chọn điểm giao hàng trên bản đồ">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18s6-5.686 6-10A6 6 0 1 0 4 8c0 4.314 6 10 6 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
                    </button>
                </div>
                @endif
            </div>

            {{-- Chi tiết theo dịch vụ --}}
            <div class="cc-card">
                @if ($serviceType === 'delivery')
                <div class="cc-row2">
                    <div>
                        <label class="cc-lbl">SĐT người nhận <small>(tuỳ chọn)</small></label>
                        <input type="tel" inputmode="tel" class="cc-input" wire:model.blur="data.delivery_phone" placeholder="Shop chưa cho thì để trống" autocomplete="off" />
                    </div>
                    <div>
                        <label class="cc-lbl">Ghi chú cho tài xế</label>
                        <input type="text" class="cc-input" wire:model="data.order_note" placeholder="Ghi chú..." />
                    </div>
                </div>
                @endif

                @if ($serviceType === 'shopping')
                <div class="cc-fld"><label class="cc-lbl">Hàng cần mua</label>
                <textarea class="cc-textarea {{ $err('shopping_note') ? 'has-err' : '' }}" wire:model="data.shopping_note" rows="2" placeholder="Ví dụ: 1 ly trà sữa size L, ít đá..."></textarea></div>
                @if ($err('shopping_note'))<p class="cc-err">{{ $err('shopping_note') }}</p>@endif
                @endif

                @if ($serviceType === 'topup')
                <div class="cc-row2">
                    <div>
                        <label class="cc-lbl">Số tiền nạp</label>
                        <div class="cc-fee-wrap"><input type="number" class="cc-input {{ $err('cod_amount') ? 'has-err' : '' }}" wire:model="data.cod_amount" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
                        @if ($err('cod_amount'))<p class="cc-err">{{ $err('cod_amount') }}</p>@endif
                    </div>
                    <div>
                        <label class="cc-lbl">Ghi chú <small>(tuỳ chọn)</small></label>
                        <input type="text" class="cc-input" wire:model="data.order_note" placeholder="Ghi chú..." />
                    </div>
                </div>
                @endif

                @if ($isRide)
                <div class="cc-fld"><label class="cc-lbl">Ghi chú cho tài xế</label>
                <input type="text" class="cc-input" wire:model="data.order_note" placeholder="Ghi chú..." /></div>
                @endif
            </div>

            {{-- Phí + tài xế --}}
            <div class="cc-card {{ $err('assignedDriverId') ? 'has-err' : '' }}">
                <div class="cc-row2">
                    <div>
                        <label class="cc-lbl">Phí thu khách</label>
                        <div class="cc-fee-wrap"><input type="number" class="cc-input {{ $err('shipping_fee') ? 'has-err' : '' }}" wire:model.blur="data.shipping_fee" placeholder="0" /><span class="cc-fee-suffix">đ</span></div>
                    </div>
                    <div>
                        <label class="cc-lbl">Gán tài xế <small>(tuỳ chọn)</small></label>
                        <div class="cc-select-wrap">
                            <select wire:model="assignedDriverId" class="cc-input cc-select">
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
                            <svg class="cc-select-chevron" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 7.5L10 12.5L15 7.5" stroke="#9ca3af" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                    </div>
                </div>
                @if ($err('assignedDriverId'))<p class="cc-err">{{ $err('assignedDriverId') }}</p>@endif
                <div class="cc-feeline">
                    <span>
                        @if ($previewFee !== null)Hệ thống tính {{ number_format($previewFee, 0, ',', '.') }}đ @if ($nightFee > 0)(đêm +{{ number_format($nightFee, 0, ',', '.') }}) @endif<button type="button" wire:click="useSystemFee">Dùng giá này</button>
                        @elseif ($noDeliveryYet && $serviceType !== 'topup')Chưa có điểm giao nên chưa tính được phí — nhập phí đã thỏa thuận.
                        @else Chọn đủ điểm để hệ thống tính phí. @endif
                    </span>
                    <label class="cc-check"><input type="checkbox" wire:model.live="isFreeship" /> <strong>Freeship</strong></label>
                </div>
                @if (empty($onlineDrivers))
                <p class="cc-hint">
                    @if (! empty($unavailableDrivers))Chưa có tài xế nào gán được ngay: {{ count($unavailableDrivers) }} tài xế của khu vực chưa nhận được đơn ({{ collect($unavailableDrivers)->pluck('reason')->unique()->take(3)->implode(', ') }}) — xem danh sách bên trên. Tài xế phải online mới nhận được đơn.
                    @else Khu vực này chưa có tài xế nào đang hoạt động.@endif
                </p>
                @endif
            </div>
        </div>

        {{-- Thanh đặt đơn dính đáy --}}
        <div class="cc-bar">
            <div class="cc-bar__info">
                <span>Phí hệ thống <b>{{ $previewFee !== null ? number_format($previewFee, 0, ',', '.').'đ' : '—' }}</b>@if ($previewFee !== null && (int) ($data['shipping_fee'] ?? 0) !== (int) $previewFee)<button type="button" wire:click="useSystemFee">Dùng giá này</button>@endif</span>
                <span id="cc-bar-route" wire:ignore></span>
            </div>
            <button type="submit" wire:loading.attr="disabled" class="cc-submit-btn" style="background:{{ $activeSvc['color'] }}; box-shadow:0 3px 12px {{ $activeSvc['color'] }}4d;">
                <span wire:loading.remove wire:target="placeOrder">Đặt đơn <small style="opacity:.75; font-weight:500;">(Ctrl+Enter)</small></span>
                <span wire:loading wire:target="placeOrder">Đang xử lý...</span>
            </button>
        </div>
      </form>
    </div>

    {{-- ═══ BẢN ĐỒ + ĐƠN VỪA ĐẶT ═══ wire:ignore bọc map, script và danh sách đơn: Livewire re-render
    (đổi dịch vụ, nhập SĐT...) không được đụng vào, nếu không Google Maps load lại và chớp liên tục. --}}
    <div class="cc-map-wrap">
        <div class="cc-map-area" wire:ignore>
            <div id="cc-main-map" class="cc-map"></div>
            <div id="cc-map-info-route" class="cc-map-distance"></div>
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
            <div class="cc-recent__head"><span>Đơn vừa đặt hôm nay</span><em id="cc-recent-time"></em></div>
            <div id="cc-recent-list"><div class="cc-recent__empty">Đang tải…</div></div>
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
        if (!_pinModeTarget) return;
        const target = _pinModeTarget;
        const lat = e.latLng.lat(), lng = e.latLng.lng();
        _exitPinMode();
        _geocoder.geocode({ location: { lat, lng } }, (res, status) => {
            const addr = (status === 'OK' && res[0]) ? res[0].formatted_address : `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            if (target === 'pickup') {
                const pi = document.getElementById('cc-pickup-input');
                if (pi) { pi.value = addr; pi._picked = addr; }
                _setPickupPin(lat, lng);
                @this.call('setPickupLocation', addr, lat, lng).then(() => {
                    _updateFeeInfo();
                    _refreshDriverMarkers(true);
                });
            } else {
                const di = document.getElementById('cc-delivery-input');
                if (di) { di.value = addr; di._picked = addr; }
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
            pickupInput._picked = (p.formatted_address || pickupInput.value).trim();
            @this.call('setPickupLocation', p.formatted_address || pickupInput.value, lat, lng).then(() => {
                _updateFeeInfo();
                _refreshDriverMarkers(true);
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
            deliveryInput._picked = (p.formatted_address || deliveryInput.value).trim();
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
    const bar = document.getElementById('cc-bar-route');
    if (bar) bar.textContent = text;
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
    // Chưa có điểm giao thì thu phóng cho thấy trọn vòng 4 km; đã có hành trình thì _fitBounds lo
    if (fit && !_deliveryMarker && _driverCircle.getBounds()) _mainMap.fitBounds(_driverCircle.getBounds(), 16);
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
        if (calls.length && calls.every((c) => c.method === 'recentOrders')) return;
        succeed(() => {
            setTimeout(_ccFit, 120); // banner kết quả hiện/ẩn làm đổi vị trí khung → tính lại chiều cao
            setTimeout(() => {
                const pLat = @this.pickupLat, pLng = @this.pickupLng;
                const dLat = @this.deliveryLat, dLng = @this.deliveryLng;
                const pAddr = (@this.data && @this.data.pickup_address) || '';
                const dAddr = (@this.data && @this.data.delivery_address) || '';
                const pi = document.getElementById('cc-pickup-input');
                const di = document.getElementById('cc-delivery-input');
                // Địa chỉ do server điền (đơn cũ, đặt lại, chọn bản đồ) phải hiện lên ô; đang gõ dở ở ô nào thì không đè
                if (pi && pAddr && document.activeElement !== pi && pi.value.trim() !== pAddr.trim()) pi.value = pAddr;
                if (di && dAddr && document.activeElement !== di && di.value.trim() !== dAddr.trim()) di.value = dAddr;
                if (pLat && pLng) {
                    _setPickupPin(pLat, pLng, false);
                    _refreshDriverMarkers(false);
                    if (pi) pi._picked = (pi.value || '').trim();
                } else {
                    _clearDriverMarkers();
                    if (_pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
                    // Chưa có toạ độ nhưng còn chữ đã gõ thì giữ lại (chưa chọn gợi ý); chỉ xoá khi form đã được làm sạch
                    if (pi && !pAddr) pi.value = '';
                }
                if (dLat && dLng) {
                    _setDeliveryPin(dLat, dLng);
                    if (di) di._picked = (di.value || '').trim();
                } else {
                    if (_deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; }
                    _clearRoute();
                    if (di && !dAddr) di.value = '';
                }
                if (_mapsReady) _initSearchAutocomplete();
            }, 150);
        });
    });
});

// ── Khung form + bản đồ cao đúng phần màn hình còn lại (màn tổng đài thường nhỏ) ─────────────
function _ccFit() {
    // Trang cuộn tự nhiên (thanh Đặt đơn và bản đồ dính màn hình): không ép chiều cao khung nữa
    const w = document.querySelector('.cc-wrapper');
    if (w) w.style.height = '';
}
window.addEventListener('resize', _ccFit);
document.addEventListener('livewire:initialized', () => { _ccFit(); setTimeout(_ccFit, 300); });
setTimeout(_ccFit, 0);

// ── Địa chỉ gõ tay ───────────────────────────────────────────────────────────
// Ô địa chỉ không gắn wire:model (Google Autocomplete giữ giá trị). Khi rời ô mà chữ khác với địa chỉ đã chọn
// thì đẩy chữ lên server (không gọi request) và bỏ toạ độ cũ, để đơn không mang toạ độ của địa chỉ khác.
function _syncTyped(kind) {
    // Chọn một gợi ý Google làm ô mất focus trước khi place_changed chạy: đợi một nhịp để lựa chọn thắng
    setTimeout(() => _syncTypedNow(kind), 250);
}
function _syncTypedNow(kind) {
    const el = document.getElementById(`cc-${kind}-input`);
    if (!el) return;
    const v = el.value.trim();
    const cur = ((@this.data || {})[`${kind}_address`] || '').trim();
    if (v === cur || v === (el._picked || '')) return;
    @this.set(`data.${kind}_address`, v, false);
    @this.set(`${kind}Lat`, null, false);
    @this.set(`${kind}Lng`, null, false);
    if (kind === 'pickup' && _pickupMarker) { _pickupMarker.setMap(null); _pickupMarker = null; }
    if (kind === 'delivery' && _deliveryMarker) { _deliveryMarker.setMap(null); _deliveryMarker = null; _clearRoute(); }
    @this.call('refreshPreview'); // kèm các thay đổi vừa gom, để phí hệ thống không còn là số của hành trình cũ
}

// ── Đơn vừa đặt: tự làm mới 15s, không vẽ lại form ───────────────────────────
function _ccEsc(v) { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
function _ccRenderRecent(list) {
    const box = document.getElementById('cc-recent-list');
    if (!box) return;
    const t = document.getElementById('cc-recent-time');
    if (t) t.textContent = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    if (!list || !list.length) { box.innerHTML = '<div class="cc-recent__empty">Chưa có đơn nào hôm nay.</div>'; return; }
    box.innerHTML = list.map((o) => {
        const open = o.status === 'pending';
        const label = o.stopped ? 'Đã dừng tìm' : o.status_label;
        const color = o.stopped ? '#dc2626' : (o.status === 'pending' ? '#b45309' : (o.status === 'completed' ? '#15803d' : '#64748b'));
        const when = o.status === 'pending' ? o.minutes + ' phút' : (o.driver ? _ccEsc(o.driver) : o.minutes + 'p trước');
        const route = '↑ ' + _ccEsc(o.pickup) + ' → ' + (o.no_delivery ? '<i>chưa có điểm giao</i>' : _ccEsc(o.delivery));
        return '<div class="cc-ro s-' + o.status + (o.stopped ? ' stopped' : '') + '">' +
            '<a class="code" href="' + _ccEsc(o.url) + '">#' + _ccEsc(o.code) + '</a>' +
            '<span class="who" title="' + _ccEsc(o.service) + '">' + _ccEsc(o.name ? o.name + ' · ' : '') + _ccEsc(o.phone || 'chưa có SĐT') + '</span>' +
            '<span class="route" title="' + _ccEsc(o.pickup) + '">' + route + '</span>' +
            '<span class="st" style="color:' + color + '">' + _ccEsc(label) + ' <small>' + when + '</small></span>' +
            '<span class="acts">' + (open ? '<button type="button" onclick="_ccAssign(' + o.id + ')">Gán</button><button type="button" onclick="_ccCancel(' + o.id + ')">Hủy</button>' : '') + '</span>' +
            '</div>';
    }).join('');
}
function _ccLoadRecent(force) {
    if (document.hidden && force !== true) return; // tab đang ẩn thì thôi làm mới định kỳ; lần tải đầu luôn chạy
    Promise.resolve(@this.call('recentOrders')).then(_ccRenderRecent).catch(() => {});
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

    // Restore pins if reorder
    const pLat = @this.pickupLat, pLng = @this.pickupLng;
    const dLat = @this.deliveryLat, dLng = @this.deliveryLng;
    if (pLat && pLng) { _setPickupPin(pLat, pLng); _refreshDriverMarkers(); }
    if (dLat && dLng) _setDeliveryPin(dLat, dLng);
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
