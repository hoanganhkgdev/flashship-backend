<x-filament-widgets::widget>
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Còn phải thu</span>
            <strong>{{ $money($s['openMoney']) }}</strong>
            <small>{{ $num($s['openCount']) }} khoản chưa thanh toán</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Quá hạn</span>
            <strong @class(['fs-dr-low' => $s['overdueCount'] > 0])>{{ $money($s['overdueMoney']) }}</strong>
            <small>{{ $num($s['overdueCount']) }} khoản quá hạn</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đang bị chặn nhận đơn</span>
            <strong @class(['fs-dr-low' => $s['blockedDrivers'] > 0])>{{ $num($s['blockedDrivers']) }}</strong>
            <small>tài xế hoạt động nợ {{ $money($s['blockedMoney']) }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tỷ lệ thu 30 ngày</span>
            <strong>{{ $s['collectRate'] !== null ? $s['collectRate'].'%' : '—' }}</strong>
            <small>nợ của tài xế đã khóa: {{ $money($s['lockedMoney']) }} ({{ $num($s['lockedDrivers']) }} người)</small>
        </article>
    </div>

    <section class="fs-rr-card" style="margin-top:1.25rem">
        <header class="fs-rr-card__head"><div><h2>Tài xế đang bị chặn nhận đơn</h2><p>Có nợ quá hạn nên không bật được online. Thu sớm để trả lại nguồn tài xế.</p></div></header>
        @forelse ($blocked as $b)
            <a class="fs-dr-row" href="{{ $b['url'] }}" wire:navigate>
                <span>{{ $b['name'] }} <small>({{ $num($b['items']) }} khoản · từ {{ \Carbon\Carbon::parse($b['since'])->format('d/m') }} · ví {{ $money($b['wallet']) }})</small></span>
                <b class="fs-sc-down">{{ $money($b['remaining']) }}</b>
            </a>
        @empty
            <div class="fs-empty"><div>Không có tài xế nào đang bị chặn vì nợ.</div></div>
        @endforelse
    </section>
</x-filament-widgets::widget>
