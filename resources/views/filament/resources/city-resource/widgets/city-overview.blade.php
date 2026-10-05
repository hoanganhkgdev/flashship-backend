<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Khu vực phục vụ</span><strong>{{ $num($active) }}</strong><small>đang bật và hiện trong app, trên {{ $num($total) }} khu vực</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn 30 ngày</span><strong>{{ $num($orders30) }}</strong><small>tất cả khu vực</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Cần rà soát</span><strong @class(['fs-dr-low' => $attention > 0])>{{ $num($attention) }}</strong><small>{{ $attention ? 'khu vực có cảnh báo, xem tab "Cần rà soát"' : 'không có cảnh báo' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn không có khu vực</span><strong>{{ $num($orphans) }}</strong><small>đơn cũ chưa gắn khu vực nào</small></article>
    </div>
</x-filament-widgets::widget>
