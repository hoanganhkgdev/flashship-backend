<x-filament-widgets::widget>
    {{-- CSS của cả trang Công nợ (KPI, bảng, trang chi tiết). --}}
    <link rel="stylesheet" href="{{ asset('css/driver-debt.css') }}?v={{ filemtime(public_path('css/driver-debt.css')) }}">
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/', trim($name)))->filter()->slice(-2)->map(fn ($w) => mb_substr($w, 0, 1))->join(''));
        $rate = $s['collectRate'];
    @endphp

    <div class="fs-debt">
        <section class="fs-debt__kpis" aria-label="Tổng quan công nợ">
            <article class="fs-debt__kpi fs-debt__kpi--hero">
                <div class="fs-debt__kpi-head"><span>Còn phải thu</span><x-heroicon-o-banknotes /></div>
                <strong>{{ $money($s['openMoney']) }}</strong>
                @if ($rate !== null)
                    <span class="fs-debt__meter" role="img" aria-label="Đã thu {{ $rate }}% nợ phát sinh 30 ngày"><i style="width: {{ min(100, $rate) }}%"></i></span>
                @endif
                <small>{{ $num($s['openCount']) }} khoản chưa thanh toán · {{ $rate === null ? 'chưa có nợ phát sinh 30 ngày' : 'đã thu '.$rate.'% nợ phát sinh 30 ngày' }}</small>
            </article>
            <article class="fs-debt__kpi fs-debt__kpi--red">
                <div class="fs-debt__kpi-head"><span>Quá hạn</span><x-heroicon-o-clock /></div>
                <strong @class(['is-alert' => $s['overdueCount'] > 0])>{{ $money($s['overdueMoney']) }}</strong>
                <small>{{ $num($s['overdueCount']) }} khoản đã qua hạn đóng</small>
            </article>
            <article class="fs-debt__kpi fs-debt__kpi--amber">
                <div class="fs-debt__kpi-head"><span>Bị chặn nhận đơn</span><x-heroicon-o-no-symbol /></div>
                <strong @class(['is-alert' => $s['blockedDrivers'] > 0])>{{ $num($s['blockedDrivers']) }}<em>tài xế</em></strong>
                <small>đang hoạt động, nợ quá hạn {{ $money($s['blockedMoney']) }}</small>
            </article>
            <article class="fs-debt__kpi fs-debt__kpi--gray">
                <div class="fs-debt__kpi-head"><span>Tài xế đã khóa còn nợ</span><x-heroicon-o-lock-closed /></div>
                <strong>{{ $money($s['lockedMoney']) }}</strong>
                <small>{{ $num($s['lockedDrivers']) }} người · khó thu, cân nhắc miễn nợ</small>
            </article>
        </section>

        @if ($blocked->isNotEmpty())
            <section class="fs-debt__card" aria-label="Tài xế đang bị chặn nhận đơn">
                <header class="fs-debt__card-head">
                    <div>
                        <h2>Tài xế đang bị chặn nhận đơn <span class="fs-debt__count">{{ $num($s['blockedDrivers']) }}</span></h2>
                        <p>Có nợ quá hạn nên không bật được online. Thu xong là nhận đơn lại ngay{{ $s['blockedDrivers'] > $blocked->count() ? ' · đang hiện '.$blocked->count().' người nợ nhiều nhất' : '' }}.</p>
                    </div>
                </header>
                <div class="fs-debt__blocked">
                    @foreach ($blocked as $b)
                        <a class="fs-debt__driver" href="{{ $b['url'] }}" wire:navigate>
                            <span class="fs-debt__avatar" aria-hidden="true">{{ $initials($b['name']) }}</span>
                            <span class="fs-debt__driver-main">
                                <b>{{ $b['name'] }}</b>
                                <small>{{ $num($b['items']) }} khoản · từ {{ \Carbon\Carbon::parse($b['since'])->format('d/m') }}</small>
                            </span>
                            <span class="fs-debt__driver-amount">{{ $money($b['remaining']) }}</span>
                            @if ((float) $b['wallet'] >= (float) $b['remaining'])
                                <span class="fs-debt__driver-wallet is-ok">Ví {{ $money($b['wallet']) }} · đủ để thu từ ví</span>
                            @else
                                <span class="fs-debt__driver-wallet is-short">Ví {{ $money($b['wallet']) }} · thiếu {{ $money($b['remaining'] - $b['wallet']) }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
