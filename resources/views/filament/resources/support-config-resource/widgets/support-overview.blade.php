<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Kênh cho khách</span><strong @class(['fs-dr-low' => $s['customer'] === 0])>{{ $num($s['customer']) }}</strong><small>{{ $s['customer'] ? 'đang hiển thị' : 'chưa có kênh nào' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Kênh cho tài xế</span><strong @class(['fs-dr-low' => $s['driver'] === 0])>{{ $num($s['driver']) }}</strong><small>{{ $s['driver'] ? 'đang hiển thị' : 'chưa có kênh nào' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Kênh cho cửa hàng</span><strong @class(['fs-dr-low' => $s['shop'] === 0])>{{ $num($s['shop']) }}</strong><small>{{ $s['shop'] ? 'đang hiển thị' : 'chưa có kênh nào' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tổng số kênh</span><strong>{{ $num($s['total']) }}</strong><small>{{ $num($s['active']) }} đang bật</small></article>
    </div>

    @if ($logs->isNotEmpty())
        <section class="fs-rr-card" style="margin-top:1.25rem">
            <header class="fs-rr-card__head"><div><h2>Thay đổi gần đây</h2><p>Ai thêm, sửa, ẩn hoặc xóa kênh</p></div></header>
            @foreach ($logs as $l)
                <div class="fs-cp-addr"><b>{{ $l['label'] }}</b><span>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}{{ $l['detail'] ? ' · '.$l['detail'] : '' }}</span></div>
            @endforeach
        </section>
    @endif
</x-filament-widgets::widget>
