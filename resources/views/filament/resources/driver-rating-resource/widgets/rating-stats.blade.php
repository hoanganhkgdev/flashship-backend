<x-filament-widgets::widget>
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $dec = fn ($v) => number_format($v, 1, ',', '.');
        $max = max(1, ...array_values($s['dist']));
        $diff = $s['prevAvg'] !== null && $s['count'] ? round($s['avg'] - $s['prevAvg'], 2) : null;
        $colors = [5 => '#16a34a', 4 => '#84cc16', 3 => '#f59e0b', 2 => '#f97316', 1 => '#dc2626'];
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Điểm trung bình</span>
            <strong>{{ $s['count'] ? $dec($s['avg']).' ★' : '—' }}</strong>
            <small>
                {{ $s['days'] }} ngày qua
                @if ($diff !== null) · <span class="{{ $diff >= 0 ? 'fs-sc-up' : 'fs-sc-down' }}">{{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2, ',', '.') }}</span> so với kỳ trước @endif
            </small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Lượt đánh giá</span>
            <strong>{{ $num($s['count']) }}</strong>
            <small>{{ $dec($s['rate']) }}% đơn hoàn thành được đánh giá</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Cần xử lý</span>
            <strong @class(['fs-dr-low' => $s['pending'] > 0])>{{ $num($s['pending']) }}</strong>
            <small>{{ $s['pending'] ? 'đánh giá 1–2 sao chưa xử lý' : 'đã xử lý hết' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đánh giá 4–5 sao</span>
            <strong>{{ $s['count'] ? $dec(($s['dist'][5] + $s['dist'][4]) / $s['count'] * 100) : '0' }}%</strong>
            <small>{{ $num($s['dist'][5] + $s['dist'][4]) }} trên {{ $num($s['count']) }} lượt</small>
        </article>
    </div>

    <div class="fs-cp-grid fs-sc-panels">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Phân bố số sao</h2><p>{{ $s['days'] }} ngày qua · đã loại đánh giá ẩn</p></div></header>
            @foreach ($colors as $star => $color)
                <div class="fs-sc-loss">
                    <div class="fs-sc-loss__top"><span>{{ $star }} sao</span><b class="fs-plain">{{ $num($s['dist'][$star]) }}</b></div>
                    <div class="fs-sc-bar"><i style="width: {{ round($s['dist'][$star] / $max * 100) }}%; background: {{ $color }}"></i></div>
                </div>
            @endforeach
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Tài xế cần chú ý</h2><p>Điểm trung bình thấp nhất 90 ngày, từ {{ \App\Services\DriverRatingService::MIN_REVIEWS }} lượt đánh giá</p></div></header>
            @forelse ($watch as $w)
                <a class="fs-dr-row" href="{{ $w['url'] }}" wire:navigate>
                    <span>{{ $w['name'] }} <small>({{ $num($w['count']) }} lượt{{ $w['low'] ? ' · '.$num($w['low']).' lượt 1–2 sao' : '' }})</small></span>
                    <b class="{{ $w['avg'] < 3.5 ? 'fs-sc-down' : '' }}">{{ $dec($w['avg']) }} ★</b>
                </a>
            @empty
                <div class="fs-empty"><div>Chưa có tài xế nào đủ lượt đánh giá.</div></div>
            @endforelse
        </section>
    </div>
</x-filament-widgets::widget>
