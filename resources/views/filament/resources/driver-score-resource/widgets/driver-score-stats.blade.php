<x-filament-widgets::widget>
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $maxLoss = max(1, (int) ($loss->max('lost') ?? 0));
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Điểm trung bình</span>
            <strong>{{ number_format($p['avg'], 1, ',', '.') }}</strong>
            <small>{{ $num($p['active']) }} tài xế đang hoạt động</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đủ điều kiện thưởng</span>
            <strong>{{ $num($p['bonus']) }}</strong>
            <small>từ {{ $p['bonusScore'] }} điểm · {{ $money($p['bonusAmount']) }}/người</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Cần cải thiện</span>
            <strong>{{ $num($needsWork) }}</strong>
            <small>từ {{ $p['penaltyScore'] }} điểm trở xuống · phạt {{ $money($p['penaltyAmount']) }}/người</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Dự kiến chốt tuần</span>
            <strong>{{ $p['net'] > 0 ? 'Thu ' : ($p['net'] < 0 ? 'Chi ' : '') }}{{ $money(abs($p['net'])) }}</strong>
            <small>{{ $num($p['bonus']) }} thưởng ({{ $money($p['bonusMoney']) }}) · {{ $num($p['penalty']) }} phạt ({{ $money($p['penaltyMoney']) }})</small>
        </article>
    </div>

    <div class="fs-cp-grid fs-sc-panels">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head">
                <div><h2>Vì sao tài xế mất điểm</h2><p>30 ngày qua · {{ $num($lossTotal) }} điểm thực tế bị trừ</p></div>
            </header>
            @forelse ($loss as $row)
                <div class="fs-sc-loss">
                    <div class="fs-sc-loss__top"><span>{{ $row['label'] }}</span><b>−{{ $num($row['lost']) }}</b></div>
                    <div class="fs-sc-bar"><i style="width: {{ round($row['lost'] / $maxLoss * 100) }}%"></i></div>
                    <small>{{ $num($row['times']) }} lần · {{ $num($row['drivers']) }} tài xế</small>
                </div>
            @empty
                <div class="fs-empty"><div>Chưa có điểm nào bị trừ trong 30 ngày.</div></div>
            @endforelse
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head">
                <div><h2>Lịch sử chốt tuần</h2><p>Chốt vào 00:02 thứ Hai · tuần này sẽ chốt {{ $weekEnd->copy()->addDay()->format('d/m') }}</p></div>
            </header>
            @forelse ($history as $h)
                <div class="fs-dr-row">
                    <span>{{ \Carbon\Carbon::parse($h->week_start)->format('d/m') }} → {{ \Carbon\Carbon::parse($h->week_end)->format('d/m') }}</span>
                    <span class="fs-sc-settle">
                        <b class="fs-sc-up">{{ $num($h->bonus_count) }} thưởng · {{ $money($h->bonus_money) }}</b>
                        <b class="fs-sc-down">{{ $num($h->penalty_count) }} phạt · {{ $money($h->penalty_money) }}</b>
                    </span>
                </div>
            @empty
                <div class="fs-empty"><div>Chưa có tuần nào được chốt.</div></div>
            @endforelse
        </section>
    </div>
</x-filament-widgets::widget>
