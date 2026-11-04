<x-filament-panels::page class="fs-driver-map">

<style>

    /* ── Bản đồ tài xế: một thanh trên cùng, bản đồ chiếm phần màn hình còn lại ── */
    .fs-driver-map { --dm-bg:#fff; --dm-soft:#f7f8fa; --dm-line:#e5e7eb; --dm-ink:#0f172a; --dm-muted:#64748b; --dm-faint:#94a3b8; }
    .fi-main:has(.fs-driver-map) { padding-block:.5rem; }
    .fs-driver-map > section { padding-block:.35rem; row-gap:0; }
    .fs-driver-map > section > div > .grid { row-gap:0; }

    .dm-bar { display:flex; align-items:center; flex-wrap:wrap; gap:10px 16px; margin-bottom:10px; }
    .dm-bar__title { display:flex; align-items:center; gap:10px; margin-right:auto; min-width:0; }
    .dm-bar__title h1 { color:var(--dm-ink); font-size:var(--fs-lg); font-weight:750; letter-spacing:-.02em; line-height:1.2; white-space:nowrap; }
    .dm-live { display:inline-flex; align-items:center; gap:6px; padding:3px 9px; border:1px solid #d1fae5; border-radius:999px; background:#f0fdf4; color:#15803d; font-size:var(--fs-xs); font-weight:600; white-space:nowrap; }
    .dm-live i { width:7px; height:7px; border-radius:50%; background:#22c55e; animation:dm-pulse 1.8s ease-in-out infinite; }
    @keyframes dm-pulse { 50% { opacity:.35; } }

    /* 6 số liệu = nút lọc (bấm "Online" để xem tất cả) */
    .dm-stats { display:flex; flex-wrap:wrap; gap:4px; padding:3px; border:1px solid var(--dm-line); border-radius:12px; background:var(--dm-bg); }
    .dm-stat { position:relative; display:inline-flex; align-items:center; gap:7px; padding:5px 11px; border:0; border-radius:9px; background:transparent; color:var(--dm-muted); font-size:var(--fs-xs); cursor:pointer; transition:background .15s,color .15s; }
    .dm-stat:hover { background:var(--dm-soft); color:var(--dm-ink); }
    .dm-stat i { width:8px; height:8px; flex:none; border-radius:50%; }
    .dm-stat div { display:flex; align-items:baseline; gap:6px; }
    .dm-stat strong { color:var(--dm-ink); font-size:var(--fs-base); font-weight:750; font-variant-numeric:tabular-nums; line-height:1.2; }
    .dm-stat span { white-space:nowrap; }
    .dm-stat.active { background:#fff7ed; color:#c2410c; box-shadow:inset 0 0 0 1px #fed7aa; }
    .dm-stat[data-stat="orders"] { margin-left:6px; }
    .dm-stat[data-stat="orders"]::before { content:''; position:absolute; left:-5px; top:20%; bottom:20%; width:1px; background:var(--dm-line); }

    /* Khung: cột trái + bản đồ. Chiều cao do fitMapHeight() (JS) đặt cho #driver-map-wrapper. */
    .dm-layout { display:grid; grid-template-columns:clamp(300px,26%,370px) minmax(0,1fr); grid-template-rows:minmax(0,1fr); height:calc(100dvh - 140px); min-height:420px; /* fitMapHeight() đo lại chính xác */ overflow:hidden; border:1px solid var(--dm-line); border-radius:14px; background:var(--dm-bg); box-shadow:0 4px 18px rgba(15,23,42,.05); }
    .dm-sidebar { display:flex; min-width:0; min-height:0; flex-direction:column; border-right:1px solid var(--dm-line); background:var(--dm-soft); }
    .dm-tabs { display:grid; grid-template-columns:1fr 1fr; gap:4px; padding:6px; border-bottom:1px solid var(--dm-line); background:var(--dm-bg); }
    .dm-tab { padding:7px 8px; border:0; border-radius:8px; color:var(--dm-muted); font-size:var(--fs-sm); font-weight:650; cursor:pointer; }
    .dm-tab.active { background:#fff7ed; color:#c2410c; box-shadow:inset 0 0 0 1px #fed7aa; }
    .dm-tab b { margin-left:4px; padding:1px 7px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:var(--fs-xs); }
    .dm-tools { padding:8px; border-bottom:1px solid var(--dm-line); }
    .dm-search { width:100%; height:36px; padding:7px 11px; border:1px solid var(--dm-line); border-radius:9px; background:var(--dm-bg); color:var(--dm-ink); font-size:var(--fs-sm); outline:none; }
    .dm-search:focus { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
    .dm-list { flex:1; min-height:0; overflow-y:auto; padding:6px; scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent; }
    .dm-empty { padding:30px 12px; color:var(--dm-faint); font-size:var(--fs-sm); text-align:center; }

    .dm-driver { display:grid; grid-template-columns:36px minmax(0,1fr); gap:9px; width:100%; margin-bottom:2px; padding:7px 8px; border:1px solid transparent; border-radius:10px; text-align:left; transition:background .15s,border-color .15s; }
    .dm-driver:hover,.dm-driver.active { border-color:#fed7aa; background:var(--dm-bg); box-shadow:0 2px 8px rgba(15,23,42,.05); }
    .dm-avatar { position:relative; width:36px; height:36px; overflow:hidden; border:2px solid var(--dm-color); border-radius:50%; background:#e2e8f0; }
    .dm-avatar img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
    .dm-avatar > span { display:grid; width:100%; height:100%; place-items:center; color:var(--dm-muted); font-size:var(--fs-xs); font-weight:700; }
    .dm-driver__top { display:flex; min-width:0; align-items:center; justify-content:space-between; gap:6px; }
    .dm-driver__name { overflow:hidden; color:var(--dm-ink); font-size:var(--fs-sm); font-weight:650; text-overflow:ellipsis; white-space:nowrap; }
    .dm-driver__ago { flex:none; color:var(--dm-faint); font-size:var(--fs-xs); }
    .dm-driver__meta { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-top:2px; color:var(--dm-muted); font-size:var(--fs-xs); }
    .dm-status { color:var(--dm-color); font-weight:600; }

    .dm-order { display:grid; gap:6px; width:100%; margin-bottom:6px; padding:9px 10px; border:1px solid var(--dm-line); border-left:3px solid #ef4444; border-radius:10px; background:var(--dm-bg); text-align:left; cursor:pointer; transition:border-color .15s,box-shadow .15s; }
    .dm-order:hover,.dm-order.active { border-color:#fed7aa; border-left-color:#ef4444; }
    .dm-order.active { box-shadow:0 0 0 2px rgba(249,115,22,.15); }
    .dm-order__top { display:flex; align-items:center; gap:6px; }
    .dm-order__top b { color:#ea580c; font-size:var(--fs-sm); }
    .dm-order__top em { margin-left:auto; color:#dc2626; font-size:var(--fs-xs); font-style:normal; font-weight:700; }
    .dm-tag { padding:1px 7px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:var(--fs-xs); font-weight:700; }
    .dm-tag.soft { background:#fef3c7; color:#b45309; }
    .dm-order__addr { padding:5px 8px; border-radius:7px; background:var(--dm-soft); color:#475569; font-size:var(--fs-xs); line-height:1.5; }
    .dm-order__note { overflow:hidden; padding:2px 8px; border-left:2px solid #14b8a6; border-radius:4px; background:rgba(20,184,166,.1); color:#0f766e; font-size:var(--fs-xs); text-overflow:ellipsis; white-space:nowrap; }
    .dm-order__acts { display:flex; flex-wrap:wrap; gap:6px; }
    .dm-btn { padding:4px 10px; border:1px solid var(--dm-line); border-radius:8px; background:var(--dm-bg); color:#334155; font-size:var(--fs-xs); font-weight:600; cursor:pointer; text-decoration:none; }
    .dm-btn.primary { border-color:#f97316; background:#f97316; color:#fff; }
    .dm-near { display:grid; gap:4px; margin-top:2px; padding-top:8px; border-top:1px dashed #cbd5e1; }
    .dm-near__title { color:var(--dm-muted); font-size:var(--fs-xs); font-weight:700; }
    .dm-near__row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; align-items:center; gap:8px; padding:4px 0; font-size:var(--fs-xs); }
    .dm-near__row strong { overflow:hidden; color:var(--dm-ink); text-overflow:ellipsis; white-space:nowrap; }
    .dm-near__row span { color:var(--dm-muted); white-space:nowrap; }
    .dm-near__note { color:var(--dm-faint); font-size:var(--fs-xs); }

    /* Bản đồ và các lớp nổi */
    #driver-map-wrapper { position:relative; width:100%; min-height:0; }
    #driver-map { position:absolute; inset:0; width:100%; height:100%; }
    #driver-map-counter { position:absolute; top:10px; left:10px; z-index:5; padding:6px 12px; border:1px solid rgba(255,255,255,.8); border-radius:999px; background:#fff; color:#374151; font-size:var(--fs-xs); font-weight:650; box-shadow:0 4px 14px rgba(15,23,42,.15); }
    .dm-map-actions { position:absolute; top:10px; right:52px; z-index:5; display:flex; gap:6px; }
    .dm-map-action { padding:6px 10px; border:1px solid rgba(255,255,255,.8); border-radius:8px; background:#fff; color:#475569; font-size:var(--fs-xs); font-weight:600; cursor:pointer; box-shadow:0 4px 14px rgba(15,23,42,.15); }
    .dm-map-legend { position:absolute; z-index:5; bottom:24px; left:10px; display:flex; align-items:center; gap:10px; padding:6px 10px; border:1px solid rgba(255,255,255,.85); border-radius:9px; background:rgba(255,255,255,.94); box-shadow:0 4px 14px rgba(15,23,42,.14); color:var(--dm-muted); font-size:var(--fs-xs); pointer-events:none; }
    .dm-map-legend span { display:inline-flex; align-items:center; gap:4px; }
    .dm-map-legend i { width:8px; height:8px; border-radius:50%; }

    /* Dark mode */
    .dark .fs-driver-map { --dm-bg:#171b25; --dm-soft:#121620; --dm-line:#293142; --dm-ink:#f1f5f9; --dm-muted:#94a3b8; --dm-faint:#64748b; }
    .dark .dm-live { border-color:#14532d; background:#11251b; color:#86efac; }
    .dark .dm-stat.active,.dark .dm-tab.active { background:#431407; color:#fdba74; box-shadow:inset 0 0 0 1px #9a3412; }
    .dark .dm-order__addr { color:#cbd5e1; }
    .dark .dm-order__note { color:#5eead4; }
    .dark .dm-btn { color:#e2e8f0; }
    .dark .dm-btn.primary { color:#fff; }
    .dark #driver-map-counter,.dark .dm-map-action { border-color:#334155; background:#1f2937; color:#e5e7eb; }
    .dark .dm-map-legend { border-color:#334155; background:rgba(23,27,37,.94); color:#cbd5e1; }

    /* Google Maps giới hạn chiều cao popup: cho cuộn để không cắt nội dung ở đáy. */
    .gm-style-iw-c:has(.dm-popup) { padding:0 !important; border-radius:16px !important; }
    .gm-style-iw-c:has(.dm-popup) .gm-style-iw-d { overflow-x:hidden !important; overflow-y:auto !important; scrollbar-width:thin; }
    .gm-style-iw-c:has(.dm-popup) .gm-style-iw-chr { position:absolute; top:5px; right:5px; z-index:2; }
    .gm-style-iw-c:has(.dm-popup) .gm-style-iw-ch { padding:0; }
    .gm-style-iw-c:has(.dm-popup) .gm-ui-hover-effect { width:28px !important; height:28px !important; }
    .gm-style-iw-c:has(.dm-popup) .gm-ui-hover-effect > span { width:18px !important; height:18px !important; margin:5px !important; }
    .dm-popup { --dp-bg:#fff; --dp-soft:#f8fafc; --dp-line:#e8edf2; --dp-text:#172033; --dp-muted:#64748b; width:320px; max-width:calc(100vw - 80px); background:var(--dp-bg); color:var(--dp-text); font-family:inherit; font-size:11px; line-height:1.45; text-align:left; }
    .dm-popup,.dm-popup * { box-sizing:border-box; }
    .dm-popup a { text-decoration:none; }
    .dm-popup a:hover { text-decoration:underline; }
    .dm-popup__head { display:flex; align-items:center; gap:10px; padding:16px 34px 10px 14px; }
    .dm-popup__avatar { position:relative; display:grid; flex:none; place-items:center; width:42px; height:42px; overflow:hidden; border:2px solid var(--dm-color); border-radius:14px; background:var(--dp-soft); color:var(--dm-color); font-size:17px; font-weight:800; }
    .dm-popup__avatar img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
    .dm-popup__identity { min-width:0; }
    .dm-popup__name { margin:0 0 3px; color:var(--dp-text); font-size:14px; font-weight:750; overflow-wrap:anywhere; }
    .dm-popup__status { display:inline-flex; align-items:center; gap:5px; color:var(--dm-color); font-size:10px; font-weight:650; }
    .dm-popup__status::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .dm-popup__actions { display:flex; gap:6px; padding:0 14px 10px; }
    .dm-popup__action { display:inline-flex; align-items:center; justify-content:center; gap:5px; min-height:30px; padding:5px 10px; border:1px solid var(--dp-line); border-radius:8px; color:var(--dp-text); font-size:11px; font-weight:650; }
    .dm-popup__action.primary { flex:1; border-color:#ffedd5; background:#fff7ed; color:#c2410c; }
    .dm-popup__action svg { width:13px; height:13px; }
    .dm-popup__shift { display:flex; gap:8px; padding:0 14px 11px; color:var(--dp-muted); font-size:10px; }
    .dm-popup__shift span { flex:none; }
    .dm-popup__shift b { color:var(--dp-text); font-weight:500; overflow-wrap:anywhere; }
    .dm-popup__section { display:flex; align-items:center; justify-content:space-between; padding:10px 14px 8px; border-top:1px solid var(--dp-line); color:var(--dp-muted); font-size:10px; font-weight:650; }
    .dm-popup__count { display:grid; place-items:center; min-width:20px; height:19px; padding:0 5px; border-radius:6px; background:#fff7ed; color:#c2410c; font-weight:750; }
    .dm-popup__orders { display:grid; grid-auto-rows:max-content; gap:9px; padding:4px 16px 20px; }
    .dm-popup__order-trigger { display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; padding:10px 12px; border:1px solid var(--dp-line); border-radius:9px; background:var(--dp-soft); color:var(--dp-text); text-align:left; cursor:pointer; font:inherit; }
    .dm-popup__order-trigger:hover,.dm-popup__order-trigger:focus-visible { border-color:#fb923c; outline:2px solid #fed7aa; outline-offset:1px; }
    .dm-popup__order-trigger b { color:#ea580c; font-size:12px; overflow-wrap:anywhere; }
    .dm-popup__hint { margin:0 14px 8px; color:var(--dp-muted); font-size:10px; }
    .dm-order-preview { position:fixed; z-index:9999; width:400px; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px); overflow-y:auto; overscroll-behavior:contain; border:1px solid var(--dp-line); border-radius:14px; box-shadow:0 16px 48px rgba(15,23,42,.24); font-size:13px; line-height:1.6; }
    .dm-order-preview__heading { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; color:var(--dp-muted); font-size:11px; }
    .dm-order-preview__close { display:grid; place-items:center; width:28px; height:28px; border:1px solid var(--dp-line); border-radius:8px; background:var(--dp-soft); color:var(--dp-text); cursor:pointer; font-size:18px; }
    .dm-order-preview .dm-popup-order { margin:0 12px 12px; }
    .dm-order-preview .dm-popup-order__head { flex-wrap:wrap; padding:10px 14px; }
    .dm-order-preview .dm-popup-order__code { font-size:14px; }
    .dm-order-preview .dm-popup-order__status { padding:3px 8px; font-size:11px; }
    .dm-order-preview .dm-popup-route { gap:16px; padding:14px; }
    .dm-order-preview .dm-popup-stop__label { margin-bottom:3px; font-size:10px; }
    .dm-order-preview .dm-popup-stop__contact { gap:4px 10px; margin-top:4px; font-size:12px; }
    .dm-order-preview .dm-popup-order__note { margin:0 14px 12px; padding:8px 10px; font-size:12px; }
    .dm-order-preview .dm-popup-order__link { padding:10px 14px; font-size:12px; }
    .dm-popup-order { min-width:0; border:1px solid var(--dp-line); border-radius:10px; overflow:hidden; }
    .dm-popup-order__head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; background:var(--dp-soft); border-bottom:1px solid var(--dp-line); }
    .dm-popup-order__code { color:var(--dp-text); font-size:12px; font-weight:750; overflow-wrap:anywhere; }
    .dm-popup-order__code span { color:var(--dp-muted); font-weight:500; }
    .dm-popup-order__status { flex:none; padding:2px 6px; border-radius:5px; background:#eff6ff; color:#2563eb; font-size:9px; font-weight:650; }
    .dm-popup-order__status.picked { background:#ecfdf5; color:#047857; }
    .dm-popup-route { display:grid; gap:10px; padding:10px; }
    .dm-popup-stop { position:relative; display:grid; grid-template-columns:8px minmax(0,1fr); gap:8px; }
    .dm-popup-stop__dot { position:relative; z-index:1; width:7px; height:7px; margin-top:4px; border:2px solid #f97316; border-radius:50%; background:var(--dp-bg); }
    .dm-popup-stop__label { margin-bottom:1px; color:var(--dp-muted); font-size:9px; }
    .dm-popup-stop__address { font-weight:550; overflow-wrap:anywhere; }
    .dm-popup-stop__contact { display:flex; flex-wrap:wrap; gap:2px 6px; margin-top:2px; color:var(--dp-muted); font-size:10px; overflow-wrap:anywhere; }
    .dm-popup-stop__contact a { color:#2563eb; white-space:nowrap; }
    .dm-popup-order__note { margin:0 10px 8px; padding:6px 8px; border-left:2px solid #fbbf24; border-radius:4px; background:#fffbeb; color:#92400e; font-size:10px; white-space:pre-wrap; overflow-wrap:anywhere; }
    .dm-popup-order__link { display:block; padding:7px 10px; border-top:1px solid var(--dp-line); color:#ea580c; text-align:right; font-size:10px; font-weight:650; }
    .dm-popup__empty { padding:4px 14px 16px; color:var(--dp-muted); font-size:11px; }
    .dm-popup__foot { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:5px 10px; padding:9px 14px; border-top:1px solid var(--dp-line); background:var(--dp-soft); color:var(--dp-muted); font-size:9px; }
    .dm-popup__foot a { color:var(--dp-text); font-size:10px; font-weight:650; }
    .dark .dm-popup { --dp-bg:#171b25; --dp-soft:#1e2532; --dp-line:#2d3748; --dp-text:#f1f5f9; --dp-muted:#94a3b8; }
    .dark .gm-style-iw-c:has(.dm-popup),.dark .gm-style-iw-c:has(.dm-popup) + .gm-style-iw-tc::after { background:#171b25; }
    .dark .gm-style-iw-c:has(.dm-popup) .gm-ui-hover-effect > span { background-color:#cbd5e1; }
    .dark .dm-popup__action.primary,.dark .dm-popup__count { border-color:#7c2d12; background:#431407; color:#fdba74; }
    .dark .dm-popup-order__status { background:#172e52; color:#93c5fd; }
    .dark .dm-popup-order__status.picked { background:#063b30; color:#6ee7b7; }
    .dark .dm-popup-stop__contact a { color:#93c5fd; }
    .dark .dm-popup-order__note { background:#382c15; color:#fde68a; }
    .dark .dm-popup-order__link { color:#fdba74; }

    @media(max-width:1180px){ .dm-stat span { display:none; } .dm-stat { padding:5px 9px; } }
    @media(max-width:900px){ .dm-layout { display:flex; flex-direction:column; height:auto; } .dm-sidebar { max-height:340px; border-right:0; border-bottom:1px solid var(--dm-line); } .dm-list { min-height:200px; } #driver-map-wrapper { min-height:55vh; } .dm-stat span { display:inline; } }

    /* ── Visual refresh: giữ nguyên layout, tăng độ rõ cho màn điều phối ────── */
    .fs-driver-map {
        --dm-accent:#f97316;
        --dm-accent-deep:#c2410c;
        --dm-shadow:0 18px 44px rgba(15,23,42,.09);
    }
    .fi-main:has(.fs-driver-map) {
        background:
            radial-gradient(circle at 96% 0,rgba(249,115,22,.075),transparent 23rem),
            linear-gradient(180deg,#fbfcfe,#f8fafc 32rem);
    }
    .dm-bar {
        gap:10px 18px;
        margin-bottom:12px;
        padding:9px 10px;
        border:1px solid var(--dm-line);
        border-radius:15px;
        background:rgba(255,255,255,.86);
        box-shadow:0 5px 18px rgba(15,23,42,.045);
        backdrop-filter:blur(10px);
    }
    .dm-bar__title { padding-inline:2px; }
    .dm-bar__title h1 { display:flex; align-items:center; gap:9px; font-size:18px; letter-spacing:-.035em; }
    .dm-bar__title h1::before {
        content:'';
        width:26px;
        height:26px;
        flex:none;
        background:linear-gradient(135deg,#fb923c,#ea580c);
        box-shadow:0 6px 14px rgba(234,88,12,.22);
        -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2a8 8 0 0 0-8 8c0 5 8 12 8 12s8-7 8-12a8 8 0 0 0-8-8Zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z'/%3E%3C/svg%3E") center/22px no-repeat;
        mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2a8 8 0 0 0-8 8c0 5 8 12 8 12s8-7 8-12a8 8 0 0 0-8-8Zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z'/%3E%3C/svg%3E") center/22px no-repeat;
    }
    .dm-live { min-height:27px; border-color:#bbf7d0; background:linear-gradient(135deg,#f0fdf4,#ecfdf5); box-shadow:inset 0 1px 0 #fff,0 3px 9px rgba(22,163,74,.07); }
    .dm-live i { box-shadow:0 0 0 4px rgba(34,197,94,.13); }

    .dm-stats { gap:5px; padding:4px; border-color:#dfe5ec; background:#f8fafc; box-shadow:inset 0 1px 2px rgba(15,23,42,.025); }
    .dm-stat { --stat-color:#94a3b8; min-height:38px; padding:6px 10px; overflow:hidden; border:1px solid transparent; background:transparent; }
    .dm-stat[data-stat="all"] { --stat-color:#22c55e; }
    .dm-stat[data-stat="free"] { --stat-color:#16a34a; }
    .dm-stat[data-stat="busy"] { --stat-color:#3b82f6; }
    .dm-stat[data-stat="stale"] { --stat-color:#f59e0b; }
    .dm-stat[data-stat="lost"] { --stat-color:#ef4444; }
    .dm-stat[data-stat="orders"] { --stat-color:#f97316; }
    .dm-stat i { box-shadow:0 0 0 4px color-mix(in srgb,var(--stat-color) 13%,transparent); }
    .dm-stat strong { font-size:14px; letter-spacing:-.025em; }
    .dm-stat:hover { border-color:#e2e8f0; background:#fff; }
    .dm-stat.active { border-color:color-mix(in srgb,var(--stat-color) 28%,#e2e8f0); background:#fff; color:var(--dm-ink); box-shadow:0 3px 9px rgba(15,23,42,.06); }
    .dm-stat.active strong { color:var(--stat-color); }
    .dm-stat[data-stat="orders"]::before { background:#cbd5e1; }

    .dm-layout { border-color:#dce3ea; border-radius:17px; box-shadow:var(--dm-shadow); }
    .dm-sidebar { border-color:#dce3ea; background:linear-gradient(180deg,#f8fafc,#f4f7fa); }
    .dm-tabs { position:relative; z-index:2; gap:5px; padding:7px; background:rgba(255,255,255,.94); box-shadow:0 3px 10px rgba(15,23,42,.035); }
    .dm-tab { min-height:36px; transition:background .18s ease,color .18s ease,box-shadow .18s ease; }
    .dm-tab:hover { background:#f8fafc; color:var(--dm-ink); }
    .dm-tab.active { background:linear-gradient(135deg,#fff7ed,#ffedd5); box-shadow:inset 0 0 0 1px #fed7aa,0 2px 6px rgba(194,65,12,.07); }
    .dm-tab b { min-width:21px; text-align:center; }
    .dm-tools { padding:9px; background:rgba(248,250,252,.88); }
    .dm-search {
        height:38px;
        padding:8px 12px 8px 36px;
        border-color:#dce3ea;
        border-radius:10px;
        background-color:#fff;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-4-4'/%3E%3C/svg%3E");
        background-position:12px center;
        background-repeat:no-repeat;
        background-size:15px;
        box-shadow:0 2px 7px rgba(15,23,42,.035);
        transition:border-color .18s ease,box-shadow .18s ease;
    }
    .dm-search:focus { background-color:#fff; box-shadow:0 0 0 3px rgba(249,115,22,.1),0 4px 10px rgba(15,23,42,.05); }
    .dm-list { padding:8px; }
    .dm-empty { margin:8px; border:1px dashed #cbd5e1; border-radius:12px; background:rgba(255,255,255,.6); }

    .dm-driver {
        position:relative;
        grid-template-columns:38px minmax(0,1fr);
        gap:10px;
        margin-bottom:5px;
        padding:9px 10px;
        overflow:hidden;
        border-color:rgba(226,232,240,.8);
        background:rgba(255,255,255,.78);
        box-shadow:0 2px 7px rgba(15,23,42,.025);
        cursor:pointer;
        transition:transform .18s ease,background .18s ease,border-color .18s ease,box-shadow .18s ease;
    }
    .dm-driver::before { content:''; position:absolute; inset:0 auto 0 0; width:3px; background:var(--dm-color); opacity:.82; }
    .dm-driver:hover,.dm-driver.active { border-color:color-mix(in srgb,var(--dm-color) 32%,#e2e8f0); background:#fff; box-shadow:0 7px 18px rgba(15,23,42,.075); transform:translateY(-1px); }
    .dm-driver.active { box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--dm-color) 25%,transparent),0 7px 18px rgba(15,23,42,.075); }
    .dm-avatar { width:38px; height:38px; border-width:2px; border-radius:11px; background:color-mix(in srgb,var(--dm-color) 9%,#f1f5f9); box-shadow:0 3px 8px rgba(15,23,42,.08); }
    .dm-driver__name { font-size:12px; font-weight:720; }
    .dm-driver__ago { padding:2px 6px; border-radius:999px; background:#f1f5f9; font-size:9px; }
    .dm-driver__meta { margin-top:4px; font-size:10px; }
    .dm-status { display:inline-flex; align-items:center; gap:5px; }
    .dm-status::before { content:''; width:6px; height:6px; flex:none; border-radius:50%; background:currentColor; box-shadow:0 0 0 3px color-mix(in srgb,currentColor 12%,transparent); }

    .dm-order { margin-bottom:8px; padding:11px; border-left-width:4px; border-radius:12px; box-shadow:0 3px 10px rgba(15,23,42,.04); transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease; }
    .dm-order:hover,.dm-order.active { box-shadow:0 8px 20px rgba(15,23,42,.08); transform:translateY(-1px); }
    .dm-order__top b { font-size:12px; }
    .dm-order__addr { border:1px solid #edf0f4; background:#f8fafc; line-height:1.55; }
    .dm-order__acts { align-items:center; }
    .dm-btn { min-height:29px; border-radius:8px; transition:transform .15s ease,box-shadow .15s ease; }
    .dm-btn:hover { transform:translateY(-1px); box-shadow:0 4px 9px rgba(15,23,42,.08); }
    .dm-btn.primary { border-color:#ea580c; background:linear-gradient(135deg,#fb923c,#ea580c); box-shadow:0 4px 9px rgba(234,88,12,.16); }

    #driver-map-wrapper { background:#e8edf3; }
    #driver-map-counter,.dm-map-action,.dm-map-legend { backdrop-filter:blur(10px); }
    #driver-map-counter { top:12px; left:12px; min-height:32px; border-color:rgba(255,255,255,.9); background:rgba(255,255,255,.9); box-shadow:0 8px 24px rgba(15,23,42,.14); }
    .dm-map-actions { top:12px; right:54px; }
    .dm-map-action { min-height:32px; border-color:rgba(255,255,255,.9); border-radius:10px; background:rgba(255,255,255,.9); box-shadow:0 8px 24px rgba(15,23,42,.14); transition:transform .15s ease,box-shadow .15s ease; }
    .dm-map-action:hover { transform:translateY(-1px); box-shadow:0 10px 28px rgba(15,23,42,.18); }
    .dm-map-legend { bottom:18px; left:12px; gap:12px; padding:7px 11px; border-radius:11px; background:rgba(255,255,255,.9); box-shadow:0 8px 24px rgba(15,23,42,.13); }
    .dm-map-legend i { box-shadow:0 0 0 3px rgba(255,255,255,.8); }

    .gm-style-iw-c:has(.dm-popup) { box-shadow:0 18px 48px rgba(15,23,42,.22) !important; }
    .dm-popup__head { background:linear-gradient(135deg,#fffaf5,#fff 58%); }
    .dm-popup__avatar { border-radius:12px; box-shadow:0 5px 14px color-mix(in srgb,var(--dm-color) 18%,transparent); }
    .dm-popup__action { transition:background .15s ease,border-color .15s ease; }
    .dm-popup__action:hover { border-color:#fed7aa; background:#fff7ed; }
    .dm-popup-order { box-shadow:0 3px 10px rgba(15,23,42,.035); }

    .dark .fi-main:has(.fs-driver-map) { background:radial-gradient(circle at 96% 0,rgba(249,115,22,.08),transparent 23rem),#111827; }
    .dark .dm-bar { border-color:var(--dm-line); background:rgba(23,27,37,.9); }
    .dark .dm-stats,.dark .dm-tools { border-color:var(--dm-line); background:#121620; }
    .dark .dm-stat:hover,.dark .dm-stat.active { border-color:#3b4658; background:#202735; }
    .dark .dm-tabs { background:rgba(23,27,37,.96); }
    .dark .dm-tab:hover { background:#202735; }
    .dark .dm-search { border-color:var(--dm-line); background-color:#171b25; color:#f1f5f9; }
    .dark .dm-empty { border-color:#334155; background:rgba(23,27,37,.6); }
    .dark .dm-driver { border-color:#293142; background:rgba(23,27,37,.78); }
    .dark .dm-driver:hover,.dark .dm-driver.active { background:#202735; }
    .dark .dm-driver__ago { background:#111620; }
    .dark .dm-order__addr { border-color:#293142; background:#111620; }
    .dark .dm-popup__head { background:linear-gradient(135deg,#2a211d,#171b25 60%); }

    @media(max-width:1180px) {
        .dm-bar { align-items:flex-start; }
        .dm-bar__title { width:100%; }
        .dm-stats { width:100%; }
        .dm-stat { flex:1; justify-content:center; }
    }
    @media(max-width:900px) {
        .fi-main:has(.fs-driver-map) { background:#f8fafc; }
        .dm-bar { padding:9px; }
        .dm-stats { flex-wrap:nowrap; justify-content:flex-start; overflow-x:auto; scrollbar-width:none; }
        .dm-stats::-webkit-scrollbar { display:none; }
        .dm-stat { flex:none; }
        .dm-stat span { display:inline; }
        .dm-layout { border-radius:15px; }
        .dm-sidebar { max-height:360px; }
        .dm-map-legend { max-width:calc(100% - 24px); overflow-x:auto; }
        .dark .fi-main:has(.fs-driver-map) { background:#111827; }
    }
    @media(max-width:560px) {
        .dm-bar__title { align-items:flex-start; flex-wrap:wrap; }
        .dm-bar__title h1 { width:100%; }
        .dm-live { margin-left:35px; }
        .dm-map-legend { gap:9px; font-size:9px; }
    }
</style>

{{-- Làm mới trạng thái đang đi đơn / đơn chờ mỗi 15s — vị trí GPS đã realtime qua Firebase riêng.
     Không dùng .visible: phần tử display:none luôn bị coi là ngoài màn hình nên poll không bao giờ chạy. --}}
<div wire:poll.15s="loadDriversMeta" style="display:none"></div>

<div wire:ignore>
    <div class="dm-bar">
        <div class="dm-bar__title">
            <h1>Bản đồ tài xế</h1>
            <span class="dm-live" title="Vị trí GPS cập nhật trực tiếp; trạng thái đơn tự làm mới mỗi 15 giây"><i></i>GPS trực tiếp</span>
        </div>
        <div class="dm-stats" role="group" aria-label="Lọc tài xế theo trạng thái">
            <button type="button" class="dm-stat" data-stat="all"><i style="background:#22c55e"></i><div><strong id="dm-stat-online">0</strong><span>Online</span></div></button>
            <button type="button" class="dm-stat" data-stat="free"><i style="background:#16a34a"></i><div><strong id="dm-stat-free">0</strong><span>Đang rảnh</span></div></button>
            <button type="button" class="dm-stat" data-stat="busy"><i style="background:#3b82f6"></i><div><strong id="dm-stat-busy">0</strong><span>Đang giao</span></div></button>
            <button type="button" class="dm-stat" data-stat="stale" title="Có GPS nhưng quá 3 phút chưa cập nhật"><i style="background:#f59e0b"></i><div><strong id="dm-stat-stale">0</strong><span>GPS chậm</span></div></button>
            <button type="button" class="dm-stat" data-stat="lost" title="Bật online trong hệ thống nhưng không có vị trí trên Firebase"><i style="background:#ef4444"></i><div><strong id="dm-stat-lost">0</strong><span>Mất kết nối</span></div></button>
            <button type="button" class="dm-stat" data-stat="orders"><i style="background:#f97316"></i><div><strong id="dm-stat-orders">{{ $pendingTotal }}</strong><span>Đơn chờ</span></div></button>
        </div>
    </div>
    <div class="dm-layout">
        <aside class="dm-sidebar">
            <div class="dm-tabs">
                <button type="button" class="dm-tab active" data-tab="drivers">Tài xế</button>
                <button type="button" class="dm-tab" data-tab="orders">Đơn chờ <b id="dm-tab-orders">{{ $pendingTotal }}</b></button>
            </div>
            <div id="dm-tools" class="dm-tools">
                <input id="dm-search" class="dm-search" type="search" placeholder="Tìm tên hoặc số điện thoại...">
            </div>
            <div id="dm-driver-list" class="dm-list"><div class="dm-empty">Đang tải vị trí tài xế...</div></div>
            <div id="dm-order-list" class="dm-list" style="display:none"></div>
        </aside>
        <div id="driver-map-wrapper">
            <div id="driver-map"></div>
            <div id="driver-map-counter">— online</div>
            <div class="dm-map-actions"><button id="dm-fit-all" class="dm-map-action" type="button">Hiện tất cả</button></div>
            <div class="dm-map-legend"><span><i style="background:#22c55e"></i>Rảnh</span><span><i style="background:#3b82f6"></i>Đang giao</span><span><i style="background:#f59e0b"></i>GPS chậm</span><span><i style="background:#ef4444"></i>Mất kết nối</span></div>
        </div>
    </div>
</div>

@if (!app()->runningInConsole())
<script>
(function () {
    var cfg = {
        firebase:    {!! json_encode($this->getFirebaseConfig()) !!},
        firebaseAuth: {!! json_encode($this->getFirebaseAuth()) !!},
        driversMeta: {!! json_encode($driversMeta) !!},
        ordersMeta:  {!! json_encode($ordersMeta) !!},
        ordersTotal: {{ (int) $pendingTotal }},
        wireId:      {!! json_encode($this->getId()) !!},
        center:      { lat: {{ $cities[0]['lat'] ?? 10.0125 }}, lng: {{ $cities[0]['lng'] ?? 105.0809 }} },
    };

    var COLORS = { free: '#22c55e', busy: '#3b82f6', stale: '#f59e0b', lost: '#ef4444' };
    var LABELS = { free: 'Đang rảnh', busy: 'Đang giao đơn', stale: 'GPS chậm', lost: 'Mất kết nối' };
    var STALE_MS = 180000;
    // GPS im quá lâu thì vị trí không còn dùng được: coi như mất kết nối (nếu hệ thống vẫn online) thay vì "GPS chậm" mãi mãi.
    var LOST_MS = 900000;
    var DARK_STYLE = [
        { elementType: 'geometry', stylers: [{ color: '#1d2433' }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#94a3b8' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#1d2433' }] },
        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2c364a' }] },
        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#3a4660' }] },
        { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0f1624' }] },
        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    ];
    var LIGHT_STYLE = [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }, { featureType: 'transit', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] }];

    function init() {
        if (window._dmTeardown) { try { window._dmTeardown(); } catch (e) {} }

        var mapEl = document.getElementById('driver-map');
        if (!mapEl) return;

        var map, infoWindow, circle, themeObserver, locRef, locHandler;
        var markers = {}, orderMarkers = {}, iconCache = {}, eligible = {};
        var rtdbGps = {};
        var dbMeta = cfg.driversMeta || {};
        var ordersMeta = cfg.ordersMeta || [];
        var ordersTotal = cfg.ordersTotal || ordersMeta.length; // tổng thật; ordersMeta chỉ gồm các đơn chờ lâu nhất
        var activeFilter = 'all', searchTerm = '', tab = 'drivers', selectedOrder = null, selectedDriver = null, fitted = false;

        var $ = function (id) { return document.getElementById(id); };
        var previewOrder = null, previewTrigger = null, previewTimer = null, previewPinned = false, previewClosedUntil = 0;
        var orderPreview = document.createElement('div');
        orderPreview.id = 'dm-order-preview-' + cfg.wireId;
        orderPreview.className = 'dm-popup dm-order-preview';
        orderPreview.setAttribute('role', 'region');
        orderPreview.setAttribute('aria-label', 'Chi tiết đơn');
        orderPreview.hidden = true;
        document.body.appendChild(orderPreview);
        orderPreview.addEventListener('mouseenter', function () { clearTimeout(previewTimer); });
        orderPreview.addEventListener('mouseleave', scheduleHidePreview);
        orderPreview.addEventListener('focusin', function () { clearTimeout(previewTimer); });
        orderPreview.addEventListener('focusout', function (event) {
            if (!orderPreview.contains(event.relatedTarget)) scheduleHidePreview();
        });
        orderPreview.addEventListener('click', function (event) {
            if (event.target.closest('[data-preview-close]')) {
                previewClosedUntil = Date.now() + 400;
                var trigger = previewTrigger;
                if (trigger && trigger.isConnected) trigger.focus();
                hideOrderPreview();
            }
        });
        function hideOrderPreview() {
            clearTimeout(previewTimer);
            orderPreview.hidden = true;
            if (previewTrigger) previewTrigger.setAttribute('aria-expanded', 'false');
            previewOrder = null; previewTrigger = null; previewPinned = false;
        }
        function scheduleHidePreview() {
            clearTimeout(previewTimer);
            if (!previewPinned) previewTimer = setTimeout(hideOrderPreview, 300);
        }
        function previewContent(order) {
            return '<div class="dm-order-preview__heading"><span>Chi tiết đơn đang nhận</span>' +
                '<button type="button" class="dm-order-preview__close" data-preview-close aria-label="Đóng chi tiết đơn">×</button></div>' + driverOrderInfo(order);
        }
        window._dmHideOrderPreview = scheduleHidePreview;
        window._dmPreviewOrder = function (driverId, orderId, trigger, pin) {
            if (!pin && Date.now() < previewClosedUntil) return;
            var order = ((dbMeta[driverId] || {}).active_orders || []).find(function (o) { return Number(o.id) === orderId; });
            if (!order) { hideOrderPreview(); return; }
            clearTimeout(previewTimer);
            if (previewTrigger) previewTrigger.setAttribute('aria-expanded', 'false');
            previewTrigger = trigger; previewPinned = !!pin;
            previewOrder = { driverId: driverId, orderId: orderId };
            trigger.setAttribute('aria-expanded', 'true');
            orderPreview.innerHTML = previewContent(order);
            orderPreview.hidden = false;
            var rect = trigger.getBoundingClientRect(), width = orderPreview.offsetWidth, height = orderPreview.offsetHeight;
            var left = rect.right + 12;
            if (left + width > window.innerWidth - 12) left = rect.left - width - 12;
            orderPreview.style.left = Math.max(12, Math.min(left, window.innerWidth - width - 12)) + 'px';
            orderPreview.style.top = Math.max(12, Math.min(rect.top - 12, window.innerHeight - height - 12)) + 'px';
            if (pin && trigger.matches(':focus-visible')) orderPreview.querySelector('button').focus();
        };
        function onPreviewKeydown(event) {
            if (event.key === 'Escape' && !orderPreview.hidden) {
                event.stopPropagation();
                previewClosedUntil = Date.now() + 400;
                var trigger = previewTrigger;
                if (trigger && trigger.isConnected) trigger.focus();
                hideOrderPreview();
            }
        }
        function onPreviewScroll(event) {
            if (!orderPreview.contains(event.target)) hideOrderPreview();
        }
        window.addEventListener('keydown', onPreviewKeydown, true);
        window.addEventListener('scroll', onPreviewScroll, true);
        window.addEventListener('resize', hideOrderPreview);
        // Escape cả nháy: kết quả còn được chèn vào thuộc tính (title, href, src) — thiếu nháy là ghi chú đơn chạy được script.
        function escapeHtml(v) {
            return (v == null ? '' : String(v)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function initial(meta, id) { var n = (meta && meta.name || '').trim(); return n ? n.charAt(0).toUpperCase() : '#'; }
        function isDark() { return document.documentElement.classList.contains('dark'); }

        // updated_at của Firebase là mili-giây
        // 45 phút · 1g04 · 12 ngày — như trang Đơn hàng
        function waitLabel(min) {
            min = Math.max(0, Math.floor(Number(min) || 0));
            if (min >= 1440) return Math.floor(min / 1440) + ' ngày';
            if (min >= 60) return Math.floor(min / 60) + 'g' + String(min % 60).padStart(2, '0');
            return min + ' phút';
        }
        function formatAgo(ms) {
            if (!ms) return 'Chưa rõ';
            var m = Math.max(0, Math.round((Date.now() - ms) / 60000));
            if (m < 1) return 'Vừa xong';
            if (m < 60) return m + ' phút trước';
            return Math.round(m / 60) + ' giờ trước';
        }
        function km(a, b) {
            var R = 6371, rad = Math.PI / 180;
            var dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
            var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return 2 * R * Math.asin(Math.sqrt(h));
        }

        /** free | busy | stale (có GPS) · lost (bật online nhưng không có GPS) · null (offline). */
        function classify(id) {
            var meta = dbMeta[id] || {}, gps = rtdbGps[id];
            // Hệ thống (MySQL) đã tắt online thì không tính, dù cờ trên Firebase còn sót.
            if (meta.is_online === false) return null;
            if (gps && gps.is_online === true) {
                var age = gps.updated_at ? Date.now() - gps.updated_at : Infinity;
                if (age > LOST_MS) return meta.is_online === true ? 'lost' : null;
                if (age > STALE_MS) return 'stale';
                return meta.busy === true ? 'busy' : 'free';
            }
            return meta.is_online === true ? 'lost' : null;
        }
        function hasPosition(state) { return state === 'free' || state === 'busy' || state === 'stale'; }

        // ── Bản đồ ───────────────────────────────────────────────────────────
        map = window._map = new google.maps.Map(mapEl, {
            center: cfg.center, zoom: 13, mapTypeControl: false, streetViewControl: false, fullscreenControl: true,
            styles: isDark() ? DARK_STYLE : LIGHT_STYLE,
        });
        infoWindow = new google.maps.InfoWindow();
        infoWindow.addListener('closeclick', function () { selectedDriver = null; hideOrderPreview(); });
        themeObserver = new MutationObserver(function () { map.setOptions({ styles: isDark() ? DARK_STYLE : LIGHT_STYLE }); });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        // Cả khung (cột trái + bản đồ) cao đúng phần màn hình còn lại: danh sách dài cuộn trong cột, không kéo dài trang.
        function fitMapHeight() {
            var w = document.querySelector('.dm-layout'); if (!w) return;
            if (window.innerWidth <= 900) { w.style.removeProperty('height'); return; }
            var top = w.getBoundingClientRect().top + window.scrollY;
            w.style.height = Math.max(420, window.innerHeight - top - 16) + 'px';
        }
        fitMapHeight();
        window.addEventListener('resize', fitMapHeight);

        function fitAll() {
            var b = new google.maps.LatLngBounds(), n = 0;
            Object.keys(markers).forEach(function (id) { if (markers[id].getVisible()) { b.extend(markers[id].getPosition()); n++; } });
            Object.keys(orderMarkers).forEach(function (id) { b.extend(orderMarkers[id].getPosition()); n++; });
            if (!n) return;
            map.fitBounds(b, 60);
            google.maps.event.addListenerOnce(map, 'idle', function () { if (map.getZoom() > 15) map.setZoom(15); });
        }

        // ── Icon tài xế (avatar tròn viền màu; không có ảnh thì chữ cái đầu) ──
        function buildIcon(id, meta, state, cb) {
            var key = id + '_' + state + '_' + (meta.avatar ? 'a' : 'n');
            if (iconCache[key]) { cb(iconCache[key]); return; }
            var S = 40, B = 3, color = COLORS[state];
            var canvas = document.createElement('canvas'); canvas.width = S; canvas.height = S;
            var ctx = canvas.getContext('2d');
            function draw(img) {
                ctx.clearRect(0, 0, S, S);
                ctx.beginPath(); ctx.arc(S / 2, S / 2, S / 2 - 1, 0, Math.PI * 2); ctx.fillStyle = color; ctx.fill();
                ctx.save(); ctx.beginPath(); ctx.arc(S / 2, S / 2, S / 2 - B, 0, Math.PI * 2); ctx.clip();
                if (img) { ctx.drawImage(img, B, B, S - B * 2, S - B * 2); }
                else {
                    ctx.fillStyle = '#e5e7eb'; ctx.fillRect(0, 0, S, S);
                    ctx.fillStyle = color; ctx.font = 'bold 18px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(initial(meta, id), S / 2, S / 2 + 1);
                }
                ctx.restore();
                var icon;
                try { icon = { url: canvas.toDataURL(), scaledSize: new google.maps.Size(S, S), anchor: new google.maps.Point(S / 2, S / 2) }; }
                catch (e) { icon = { path: google.maps.SymbolPath.CIRCLE, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 9 }; }
                iconCache[key] = icon; cb(icon);
            }
            if (meta.avatar) {
                var img = new Image(); img.crossOrigin = 'anonymous';
                img.onload = function () { draw(img); }; img.onerror = function () { draw(null); };
                img.src = meta.avatar;
            } else draw(null);
        }

        function driverOrderInfo(order) {
            var note = String(order.note || '').replace(/^\s*\[Chưa có điểm giao[^\]]*\]\s*/u, '').trim();
            var stop = function (label, address, name, phone) {
                var number = String(phone || '').replace(/\D+/g, '');
                return '<div class="dm-popup-stop"><i class="dm-popup-stop__dot"></i><div>' +
                    '<div class="dm-popup-stop__label">' + label + '</div><div class="dm-popup-stop__address">' + escapeHtml(address || 'Chưa có địa chỉ') + '</div>' +
                    (name || number ? '<div class="dm-popup-stop__contact">' + (name ? '<span>' + escapeHtml(name) + '</span>' : '') +
                    (number ? '<a href="tel:' + number + '">' + escapeHtml(phone) + '</a>' : '') + '</div>' : '') + '</div></div>';
            };
            return '<article class="dm-popup-order"><div class="dm-popup-order__head">' +
                '<a class="dm-popup-order__code" href="' + escapeHtml(order.url) + '"><span>Đơn</span> #' + escapeHtml(order.code) + '</a>' +
                '<span class="dm-popup-order__status' + (order.status === 'processing' ? ' picked' : '') + '">' + escapeHtml(order.status_label) + '</span></div>' +
                '<div class="dm-popup-route">' + stop('LẤY HÀNG', order.pickup_address, order.sender_name, order.pickup_phone) + '</div>' +
                (note ? '<div class="dm-popup-order__note">' + escapeHtml(note) + '</div>' : '') +
                '<a class="dm-popup-order__link" href="' + escapeHtml(order.url) + '">Chi tiết đơn →</a></article>';
        }

        function driverPopup(id) {
            var meta = dbMeta[id] || {}, gps = rtdbGps[id], state = classify(id) || 'lost', color = COLORS[state];
            var phone = (meta.phone || '').replace(/\D+/g, '');
            var orders = meta.active_orders || [];
            var phoneIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z"/></svg>';
            return '<div class="dm-popup" style="--dm-color:' + color + '">' +
                '<div class="dm-popup__head"><div class="dm-popup__avatar">' + escapeHtml(initial(meta, id)) +
                (meta.avatar ? '<img src="' + escapeHtml(meta.avatar) + '" alt="" onerror="this.remove()">' : '') + '</div>' +
                '<div class="dm-popup__identity"><div class="dm-popup__name">' + escapeHtml(meta.name || ('Tài xế #' + id)) + '</div>' +
                '<span class="dm-popup__status">' + LABELS[state] + '</span></div></div>' +
                (phone ? '<div class="dm-popup__actions"><a class="dm-popup__action primary" href="tel:' + phone + '">' + phoneIcon + escapeHtml(meta.phone) + '</a>' +
                '<a class="dm-popup__action" href="https://zalo.me/' + phone + '" target="_blank" rel="noopener">Zalo ↗</a></div>' : '') +
                '<div class="dm-popup__shift"><span>Ca làm</span><b>' + escapeHtml(meta.shift_names || 'Chưa đăng ký ca') + '</b></div>' +
                '<div class="dm-popup__section"><span>Đơn đang nhận</span><span class="dm-popup__count">' + orders.length + '</span></div>' +
                (orders.length ? '<p class="dm-popup__hint">Rê chuột hoặc bấm mã đơn để xem chi tiết</p><div class="dm-popup__orders">' + orders.map(function (order) {
                    var args = Number(id) + ',' + Number(order.id) + ',this';
                    return '<button type="button" class="dm-popup__order-trigger" aria-expanded="false" aria-controls="' + escapeHtml(orderPreview.id) + '" ' +
                        'onmouseenter="if(window.matchMedia(\'(hover: hover)\').matches)_dmPreviewOrder(' + args + ',false)" onmouseleave="_dmHideOrderPreview()" ' +
                        'onfocus="if(this.matches(\':focus-visible\'))_dmPreviewOrder(' + args + ',false)" onblur="_dmHideOrderPreview()" onclick="_dmPreviewOrder(' + args + ',true)">' +
                        '<b>#' + escapeHtml(order.code) + '</b><span class="dm-popup-order__status' + (order.status === 'processing' ? ' picked' : '') + '">' + escapeHtml(order.status_label) + '</span></button>';
                }).join('') + '</div>' : '<div class="dm-popup__empty">' + (meta.orders_elsewhere ? 'Đang giao ' + Number(meta.orders_elsewhere) + ' đơn ở khu vực khác.' : 'Tài xế chưa có đơn đang nhận.') + '</div>') +
                '<div class="dm-popup__foot"><span>GPS · ' + escapeHtml(formatAgo(gps && gps.updated_at)) + '</span>' +
                (meta.url ? '<a href="' + escapeHtml(meta.url) + '">Hồ sơ tài xế →</a>' : '') + '</div></div>';
        }

        // ── Marker tài xế ────────────────────────────────────────────────────
        function renderMarkers() {
            // CHỈ lấy id từ dbMeta (đã lọc đúng khu vực ở PHP): node "locations" trên Firebase chứa mọi khu vực,
            // dùng nó để vẽ sẽ lộ vị trí tài xế ngoài phạm vi được xem.
            var ids = Object.keys(dbMeta).map(Number);
            var idSet = new Set(ids);
            Object.keys(markers).forEach(function (id) { if (!idSet.has(Number(id))) { markers[id].setMap(null); delete markers[id]; } });

            ids.forEach(function (id) {
                var meta = dbMeta[id] || {}, gps = rtdbGps[id], state = classify(id);
                if (!hasPosition(state) || gps.lat == null || gps.lng == null) { if (markers[id]) markers[id].setVisible(false); return; }
                var pos = { lat: gps.lat, lng: gps.lng };
                var m = markers[id];
                if (!m) {
                    m = markers[id] = new google.maps.Marker({
                        position: pos, map: map, visible: true, zIndex: 10, title: meta.name || ('#' + id),
                        icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: COLORS[state], fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 9 },
                    });
                    m.addListener('click', function () { hideOrderPreview(); selectedDriver = id; infoWindow.setContent(driverPopup(id)); infoWindow.open(map, m); });
                } else { m.setPosition(pos); m.setVisible(true); m.setTitle(meta.name || ('#' + id)); }
                buildIcon(id, meta, state, function (icon) { if (markers[id]) markers[id].setIcon(icon); });
            });

            if (!fitted && Object.keys(markers).some(function (id) { return markers[id].getVisible(); })) { fitted = true; fitAll(); }
            renderDriverList();
            if (selectedOrder) renderOrderList();
        }

        // Mỗi lần một tài xế (ở bất kỳ khu vực nào) gửi GPS, Firebase đẩy cả node: gom về tối đa 1 lần vẽ/giây,
        // để danh sách không bị dựng lại liên tục dưới con trỏ.
        var renderTimer = null;
        function scheduleRender() {
            if (renderTimer) return;
            renderTimer = setTimeout(function () { renderTimer = null; renderMarkers(); }, 1000);
        }

        // ── Đơn chờ ──────────────────────────────────────────────────────────
        function orderInfo(o) {
            return '<div style="font-family:inherit;min-width:200px"><b>#' + escapeHtml(o.code) + '</b><br><span style="font-size:12px">' + escapeHtml(o.address || '—') + '</span><br>' +
                '<span style="font-size:12px;color:#dc2626">Chờ ' + waitLabel(o.minutes) + ' · chưa ai nhận' + '</span><br>' +
                '<button type="button" onclick="_dmSelectOrder(' + o.id + ',true)" style="margin-top:6px;padding:4px 10px;border-radius:8px;background:#0ea5e9;color:#fff;font-size:12px;border:0;cursor:pointer">Xem tài xế gần nhất</button></div>';
        }
        function renderOrderMarkers() {
            var seen = {};
            ordersMeta.forEach(function (o) {
                seen[o.id] = true;
                var pos = { lat: o.lat, lng: o.lng }, m = orderMarkers[o.id];
                if (!m) {
                    m = orderMarkers[o.id] = new google.maps.Marker({
                        map: map, position: pos, zIndex: 999,
                        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: '#ef4444', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
                    });
                    m.addListener('click', function () { hideOrderPreview(); selectedDriver = null; _select(o.id, false); infoWindow.setContent(orderInfo(m._order)); infoWindow.open(map, m); });
                }
                m._order = o; m.setPosition(pos);
            });
            Object.keys(orderMarkers).forEach(function (id) { if (!seen[id]) { orderMarkers[id].setMap(null); delete orderMarkers[id]; } });
        }

        /** Tài xế gần điểm lấy nhất: ưu tiên người rảnh; chỉ khi không ai rảnh mới gợi ý người đang giao. */
        function nearestDrivers(o) {
            var rows = [];
            Object.keys(dbMeta).forEach(function (rawId) {
                var id = Number(rawId), state = classify(id), gps = rtdbGps[id];
                if (state !== 'free' && state !== 'busy') return;
                if (Array.isArray(eligible[o.id]) && eligible[o.id].indexOf(id) === -1) return; // bị loại khỏi hộp thoại gán (nợ, khóa điểm, nghỉ phép...)
                rows.push({ id: id, state: state, meta: dbMeta[id], gps: gps, km: km({ lat: o.lat, lng: o.lng }, { lat: gps.lat, lng: gps.lng }) });
            });
            var free = rows.filter(function (r) { return r.state === 'free'; }).sort(function (a, b) { return a.km - b.km; });
            if (free.length) return { free: true, rows: free.slice(0, 5) };
            return { free: false, rows: rows.sort(function (a, b) { return a.km - b.km; }).slice(0, 5) };
        }

        function renderOrderList() {
            var list = $('dm-order-list'); if (!list) return;
            if (!ordersMeta.length) { list.innerHTML = '<div class="dm-empty">Không có đơn nào đang chờ tài xế.</div>'; return; }
            var more = ordersTotal > ordersMeta.length
                ? '<div class="dm-near__note" style="padding:6px 10px">Đang hiện ' + ordersMeta.length + ' đơn chờ lâu nhất trên tổng ' + ordersTotal + ' đơn — xem đủ ở trang Đơn hàng.</div>' : '';
            list.innerHTML = more + ordersMeta.map(function (o) {
                var active = selectedOrder === o.id, near = '';
                if (active) {
                    var n = nearestDrivers(o);
                    near = '<div class="dm-near"><div class="dm-near__title">' + (eligible[o.id] === undefined ? 'Đang kiểm tra điều kiện nhận đơn…' : n.rows.length ? (n.free ? 'Tài xế rảnh gần nhất' : 'Không có ai rảnh — tài xế đang giao gần nhất') : '') + '</div>' +
                        (n.rows.length ? n.rows.map(function (r) {
                            return '<div class="dm-near__row"><strong>' + escapeHtml(r.meta.name || ('#' + r.id)) + '</strong><span>' + r.km.toFixed(1).replace('.', ',') + ' km · ' + escapeHtml(formatAgo(r.gps.updated_at)) + '</span>' +
                                '<button type="button" class="dm-btn primary" onclick="event.stopPropagation();_dmAssign(' + o.id + ',' + r.id + ')">Gán</button></div>';
                        }).join('') : '<div class="dm-near__note">Chưa có tài xế nào có vị trí. Dùng nút "Gán tài xế" để chọn tay.</div>') +
                        '<div class="dm-near__note">Khoảng cách là đường chim bay, chỉ để ước lượng.</div></div>';
                }
                return '<div class="dm-order' + (active ? ' active' : '') + '" data-order-id="' + o.id + '" role="button" tabindex="0">' +
                    '<div class="dm-order__top"><b>#' + escapeHtml(o.code) + '</b>' + '<em>Chờ ' + waitLabel(o.minutes) + '</em></div>' +
                    '<div class="dm-order__addr">↑ ' + escapeHtml(o.address || '—') + '<br>↓ ' + escapeHtml(o.delivery || '—') + '</div>' +
                    (o.note ? '<div class="dm-order__note" title="' + escapeHtml(o.note) + '">Ghi chú: ' + escapeHtml(o.note) + '</div>' : '') +
                    '<div class="dm-order__acts"><button type="button" class="dm-btn primary" onclick="event.stopPropagation();_dmAssign(' + o.id + ')">Gán tài xế</button><a class="dm-btn" href="' + escapeHtml(o.url) + '" onclick="event.stopPropagation()">Mở đơn</a><span class="dm-near__note" style="align-self:center">' + Number(o.fee).toLocaleString('vi-VN') + 'đ</span></div>' +
                    near + '</div>';
            }).join('');
            list.querySelectorAll('.dm-order').forEach(function (el) {
                el.addEventListener('click', function () { _select(Number(el.dataset.orderId), true); });
            });
        }

        function _select(id, pan) {
            var o = ordersMeta.filter(function (x) { return x.id === id; })[0];
            if (!o) return;
            selectedOrder = id;
            if (circle) circle.setMap(null);
            circle = new google.maps.Circle({ map: map, center: { lat: o.lat, lng: o.lng }, radius: 2000, strokeColor: '#ef4444', strokeOpacity: .8, strokeWeight: 2, fillColor: '#ef4444', fillOpacity: .08, clickable: false });
            if (pan) { map.panTo({ lat: o.lat, lng: o.lng }); if (map.getZoom() < 14) map.setZoom(14); }
            setTab('orders');
            renderOrderList();
            if (eligible[id] === undefined) fetchEligible(id);
            var el = document.querySelector('.dm-order[data-order-id="' + id + '"]');
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }
        // Lọc gợi ý theo đúng điều kiện của hộp thoại gán; lỗi mạng thì giữ nguyên gợi ý theo vị trí
        function fetchEligible(id) {
            if (!(window.Livewire && cfg.wireId)) return;
            Promise.resolve(window.Livewire.find(cfg.wireId).call('eligibleDriverIds', id)).then(function (ids) {
                eligible[id] = Array.isArray(ids) ? ids.map(Number) : null;
                if (selectedOrder === id) renderOrderList();
            }).catch(function () { eligible[id] = null; if (selectedOrder === id) renderOrderList(); });
        }
        function clearSelection() { selectedOrder = null; if (circle) { circle.setMap(null); circle = null; } }

        window._dmSelectOrder = function (id, pan) { _select(id, pan); };
        window._dmAssign = function (orderId, driverId) {
            if (!(window.Livewire && cfg.wireId)) return;
            var args = { order: orderId };
            if (driverId) args.driver = driverId;
            window.Livewire.find(cfg.wireId).call('mountAction', 'assign', args);
        };

        // ── Danh sách tài xế + số liệu ───────────────────────────────────────
        function renderDriverList() {
            var rows = [], counts = { free: 0, busy: 0, stale: 0, lost: 0 };
            Object.keys(dbMeta).forEach(function (rawId) {
                var id = Number(rawId), state = classify(id);
                if (!state) return;
                counts[state]++;
                rows.push({ id: id, meta: dbMeta[id] || {}, gps: rtdbGps[id], state: state });
            });
            var online = counts.free + counts.busy + counts.stale;
            var set = { 'dm-stat-online': online, 'dm-stat-free': counts.free, 'dm-stat-busy': counts.busy, 'dm-stat-stale': counts.stale, 'dm-stat-lost': counts.lost, 'dm-stat-orders': ordersTotal, 'dm-tab-orders': ordersTotal };
            Object.keys(set).forEach(function (k) { var el = $(k); if (el) el.textContent = set[k]; });
            var counter = $('driver-map-counter');
            if (counter) counter.textContent = online + ' đang online' + (counts.lost ? ' · ' + counts.lost + ' mất kết nối' : '');

            rows = rows.filter(function (r) {
                var okFilter = activeFilter === 'all' || activeFilter === r.state;
                var hay = ((r.meta.name || '') + ' ' + (r.meta.phone || '')).toLowerCase();
                return okFilter && (!searchTerm || hay.indexOf(searchTerm) !== -1);
            }).sort(function (a, b) {
                var w = { lost: 0, stale: 1, busy: 2, free: 3 };
                if (w[a.state] !== w[b.state]) return w[a.state] - w[b.state];
                return (a.meta.name || '').localeCompare(b.meta.name || '', 'vi');
            });

            var list = $('dm-driver-list'); if (!list) return;
            if (!rows.length) { list.innerHTML = '<div class="dm-empty">Không tìm thấy tài xế phù hợp.</div>'; return; }
            list.innerHTML = rows.map(function (r) {
                var color = COLORS[r.state];
                var ago = r.gps && r.gps.updated_at ? formatAgo(r.gps.updated_at) : (r.state === 'lost' ? 'Chưa có vị trí' : '');
                var order = r.meta.active_order_code ? ' · #' + escapeHtml(r.meta.active_order_code) : '';
                return '<button type="button" class="dm-driver' + (r.id === selectedDriver ? ' active' : '') + '" data-driver-id="' + r.id + '" style="--dm-color:' + color + '">' +
                    '<span class="dm-avatar"><span>' + escapeHtml(initial(r.meta, r.id)) + '</span>' + (r.meta.avatar ? '<img src="' + escapeHtml(r.meta.avatar) + '" alt="" onerror="this.remove()">' : '') + '</span>' +
                    '<span><span class="dm-driver__top"><span class="dm-driver__name">' + escapeHtml(r.meta.name || ('#' + r.id)) + '</span><span class="dm-driver__ago">' + escapeHtml(ago) + '</span></span>' +
                    '<span class="dm-driver__meta"><span class="dm-status">' + LABELS[r.state] + order + '</span><span>' + escapeHtml(r.meta.phone || '—') + '</span></span></span></button>';
            }).join('');
            list.querySelectorAll('.dm-driver').forEach(function (button) {
                button.addEventListener('click', function () {
                    list.querySelectorAll('.dm-driver').forEach(function (i) { i.classList.remove('active'); });
                    button.classList.add('active');
                    var id = Number(button.dataset.driverId), marker = markers[id];
                    if (marker && marker.getVisible()) {
                        map.panTo(marker.getPosition()); map.setZoom(Math.max(map.getZoom(), 15));
                        google.maps.event.trigger(marker, 'click');
                    } else {
                        // Mất kết nối: không có vị trí để bay tới, hiện thông tin liên hệ ở giữa bản đồ
                        hideOrderPreview(); selectedDriver = id;
                        infoWindow.setContent(driverPopup(id)); infoWindow.setPosition(map.getCenter()); infoWindow.open(map);
                    }
                });
            });
        }

        // ── Tab / bộ lọc / thẻ số liệu ───────────────────────────────────────
        function setTab(t) {
            tab = t;
            document.querySelectorAll('.dm-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === t); });
            $('dm-tools').style.display = t === 'drivers' ? '' : 'none';
            $('dm-driver-list').style.display = t === 'drivers' ? '' : 'none';
            $('dm-order-list').style.display = t === 'orders' ? '' : 'none';
            if (t === 'orders') renderOrderList(); else if (selectedOrder) { clearSelection(); }
            document.querySelectorAll('.dm-stat').forEach(function (b) { b.classList.toggle('active', (t === 'orders' && b.dataset.stat === 'orders') || (t === 'drivers' && b.dataset.stat === activeFilter)); });
        }
        function setFilter(f) {
            activeFilter = f;
            document.querySelectorAll('.dm-filter').forEach(function (b) { b.classList.toggle('active', b.dataset.filter === f); });
            setTab('drivers'); renderDriverList();
        }
        document.querySelectorAll('.dm-tab').forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });
        document.querySelectorAll('.dm-filter').forEach(function (b) { b.addEventListener('click', function () { setFilter(b.dataset.filter); }); });
        document.querySelectorAll('.dm-stat').forEach(function (b) {
            b.addEventListener('click', function () { b.dataset.stat === 'orders' ? setTab('orders') : setFilter(b.dataset.stat); });
        });
        $('dm-search').addEventListener('input', function () { searchTerm = this.value.trim().toLowerCase(); renderDriverList(); });
        $('dm-fit-all').addEventListener('click', fitAll);
        setTab('drivers'); setFilter('all');

        // ── Dữ liệu ──────────────────────────────────────────────────────────
        function onMeta(e) {
            // Trạng thái tài xế vừa làm mới (online, đủ đơn, nghỉ phép...): bỏ danh sách đủ điều kiện cũ, hỏi lại cho đơn đang chọn.
            eligible = {};
            if (selectedOrder) fetchEligible(selectedOrder);
            dbMeta = e.detail.meta || {}; renderMarkers();
            if (selectedDriver !== null) {
                if (dbMeta[selectedDriver]) {
                    var ordersEl = mapEl.querySelector('.gm-style-iw-d:has(.dm-popup)');
                    var scrollTop = ordersEl ? ordersEl.scrollTop : 0;
                    google.maps.event.addListenerOnce(infoWindow, 'domready', function () {
                        var updatedOrders = mapEl.querySelector('.gm-style-iw-d:has(.dm-popup)');
                        if (updatedOrders) updatedOrders.scrollTop = scrollTop;
                    });
                    infoWindow.setContent(driverPopup(selectedDriver));
                }
                else { infoWindow.close(); selectedDriver = null; hideOrderPreview(); }
            }
            if (previewOrder) {
                var order = ((dbMeta[previewOrder.driverId] || {}).active_orders || []).find(function (o) { return Number(o.id) === previewOrder.orderId; });
                if (!order) hideOrderPreview();
                else {
                    var content = previewContent(order);
                    if (orderPreview.innerHTML !== content) {
                        var scrollTop = orderPreview.scrollTop;
                        orderPreview.innerHTML = content; orderPreview.scrollTop = scrollTop;
                    }
                }
            }
        }
        function onOrders(e) {
            ordersMeta = e.detail.orders || [];
            ordersTotal = e.detail.total != null ? e.detail.total : ordersMeta.length;
            if (selectedOrder && !ordersMeta.some(function (o) { return o.id === selectedOrder; })) clearSelection();
            renderOrderMarkers(); renderDriverList(); if (tab === 'orders') renderOrderList();
        }
        window.addEventListener('metaUpdated', onMeta);
        window.addEventListener('ordersUpdated', onOrders);

        if (!firebase.apps.length) firebase.initializeApp(cfg.firebase);
        var tornDown = false;
        locHandler = function (snap) {
            var raw = snap.val() || {}, next = {};
            Object.keys(raw).forEach(function (key) {
                var id = parseInt(key.replace('driver_', ''), 10), v = raw[key];
                if (!isNaN(id) && v && v.lat && v.lng) next[id] = { lat: v.lat, lng: v.lng, updated_at: v.updated_at, is_online: v.is_online };
            });
            rtdbGps = next; scheduleRender();
        };
        // Rules chỉ cho tài khoản admin đọc vị trí mọi tài xế: đăng nhập Firebase trước rồi mới nghe.
        var startLocations = function () {
            if (tornDown) return;
            locRef = firebase.database().ref('locations');
            locRef.on('value', locHandler, function (err) { console.error('[DriverMap] Firebase read failed:', err); });
        };
        var fbUser = firebase.auth().currentUser;
        if (fbUser && fbUser.uid === cfg.firebaseAuth.uid) startLocations();
        else if (cfg.firebaseAuth.token) {
            firebase.auth().signInWithCustomToken(cfg.firebaseAuth.token).then(startLocations)
                .catch(function (err) { console.error('[DriverMap] Firebase sign-in failed:', err); startLocations(); });
        } else startLocations();

        renderMarkers(); renderOrderMarkers();

        window._dmTeardown = function () {
            hideOrderPreview(); orderPreview.remove();
            window.removeEventListener('keydown', onPreviewKeydown, true);
            window.removeEventListener('scroll', onPreviewScroll, true);
            window.removeEventListener('resize', hideOrderPreview);
            delete window._dmPreviewOrder; delete window._dmHideOrderPreview;
            tornDown = true;
            try { if (locRef) locRef.off('value', locHandler); } catch (e) {}
            window.removeEventListener('metaUpdated', onMeta);
            window.removeEventListener('ordersUpdated', onOrders);
            window.removeEventListener('resize', fitMapHeight);
            if (themeObserver) themeObserver.disconnect();
            if (renderTimer) clearTimeout(renderTimer);
            window._dmTeardown = null;
        };
    }

    function whenReady() {
        if (window.firebase && firebase.database && firebase.auth && window.google && window.google.maps) { init(); return; }
        setTimeout(whenReady, 100);
    }
    function loadScript(src, cb) {
        var s = document.createElement('script'); s.src = src; s.onload = cb; document.head.appendChild(s);
    }
    var AUTH_JS = 'https://www.gstatic.com/firebasejs/10.12.0/firebase-auth-compat.js';
    if (window.firebase && firebase.database && firebase.auth) whenReady();
    else if (window._dmFirebaseLoading || window._dmAuthLoading) whenReady();
    else if (window.firebase && firebase.database) {
        // Tab đã nạp Firebase từ bản trang cũ (chưa có auth): nạp thêm auth.
        window._dmAuthLoading = true;
        loadScript(AUTH_JS, whenReady);
    }
    else {
        window._dmFirebaseLoading = true;
        loadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js', function () {
            loadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-database-compat.js', function () {
                loadScript(AUTH_JS, whenReady);
            });
        });
    }
})();
</script>
@endif

</x-filament-panels::page>
