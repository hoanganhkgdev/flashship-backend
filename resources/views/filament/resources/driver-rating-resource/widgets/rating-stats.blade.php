<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/rating.css') }}?v={{ filemtime(public_path('css/rating.css')) }}">
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $dec = fn ($v, $d = 1) => number_format((float) $v, $d, ',', '.');
        $diff = $s['prevAvg'] !== null && $s['count'] ? round($s['avg'] - $s['prevAvg'], 2) : null;
        $colors = [5 => '#16a34a', 4 => '#84cc16', 3 => '#f59e0b', 2 => '#f97316', 1 => '#dc2626'];
        $total = max(1, $s['count']);
        $good = $s['dist'][5] + $s['dist'][4];
        $pendingUrl = \App\Filament\Resources\DriverRatingResource::getUrl('index', ['activeTab' => 'pending']);
    @endphp

    <div class="fs-rate">
        <section class="fs-rate__kpis" aria-label="Tổng quan đánh giá">
            <article class="fs-rate__kpi fs-rate__kpi--hero">
                <div class="fs-rate__kpi-head"><span>Điểm trung bình {{ $s['days'] }} ngày</span><x-heroicon-o-star /></div>
                <strong>
                    {{ $s['count'] ? $dec($s['avg']) : '—' }}
                    @if ($s['count'])<span class="fs-rate__stars" role="img" aria-label="{{ $dec($s['avg']) }} trên 5 sao"><i style="--fill: {{ round($s['avg'] / 5 * 100, 1) }}%"></i></span>@endif
                </strong>
                <small>
                    {{ $num($s['count']) }} lượt đánh giá
                    @if ($diff !== null) · <span class="{{ $diff >= 0 ? 'fs-rate__up' : 'fs-rate__down' }}">{{ $diff > 0 ? '+' : '' }}{{ $dec($diff, 2) }}</span> so với {{ $s['days'] }} ngày trước @endif
                </small>
            </article>
            <a class="fs-rate__kpi fs-rate__kpi--red" href="{{ $pendingUrl }}" wire:navigate>
                <div class="fs-rate__kpi-head"><span>Cần xử lý</span><x-heroicon-o-exclamation-triangle /></div>
                <strong @class(['is-alert' => $s['pending'] > 0])>{{ $num($s['pending']) }}</strong>
                <small>{{ $s['pending'] ? 'đánh giá 1–2 sao chưa ai xử lý' : 'đã xử lý hết đánh giá thấp' }}</small>
            </a>
            <article class="fs-rate__kpi fs-rate__kpi--blue">
                <div class="fs-rate__kpi-head"><span>Tỷ lệ được đánh giá</span><x-heroicon-o-chat-bubble-left-right /></div>
                <strong>{{ $dec($s['rate']) }}<em>%</em></strong>
                <small>đơn hoàn thành {{ $s['days'] }} ngày có khách hoặc shop đánh giá</small>
            </article>
            <article class="fs-rate__kpi fs-rate__kpi--green">
                <div class="fs-rate__kpi-head"><span>Đánh giá 4–5 sao</span><x-heroicon-o-hand-thumb-up /></div>
                <strong>{{ $s['count'] ? $dec($good / $total * 100) : '0' }}<em>%</em></strong>
                <small>{{ $num($good) }} trên {{ $num($s['count']) }} lượt</small>
            </article>
        </section>

        <div class="fs-rate__grid">
            <section class="fs-rate__card" aria-label="Phân bố số sao">
                <header class="fs-rate__card-head"><div><h2>Phân bố số sao</h2><p>{{ $s['days'] }} ngày qua · không tính đánh giá đã ẩn</p></div></header>
                <div class="fs-rate__dist">
                    @foreach ($colors as $star => $color)
                        <div class="fs-rate__dist-row">
                            <span>{{ $star }} ★</span>
                            <span class="fs-rate__track"><i style="width: {{ round($s['dist'][$star] / $total * 100, 1) }}%; background: {{ $color }}"></i></span>
                            <b>{{ $num($s['dist'][$star]) }} <small>{{ $s['count'] ? round($s['dist'][$star] / $total * 100) : 0 }}%</small></b>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="fs-rate__card" aria-label="Tài xế cần chú ý">
                <header class="fs-rate__card-head"><div><h2>Tài xế cần chú ý</h2><p>Điểm trung bình thấp nhất 90 ngày, từ {{ \App\Services\DriverRatingService::MIN_REVIEWS }} lượt đánh giá</p></div></header>
                @if ($watch->isEmpty())
                    <p class="fs-rate__empty">Chưa có tài xế nào đủ lượt đánh giá.</p>
                @else
                    <ul class="fs-rate__watch">
                        @foreach ($watch as $w)
                            <li>
                                <a href="{{ $w['url'] }}" wire:navigate>
                                    <b>{{ $w['name'] }}</b>
                                    <small>{{ $num($w['count']) }} lượt{{ $w['low'] ? ' · '.$num($w['low']).' lượt 1–2 sao' : '' }}</small>
                                    <strong @class(['is-low' => $w['avg'] < 3.5])>{{ $dec($w['avg']) }} ★</strong>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-widgets::widget>
