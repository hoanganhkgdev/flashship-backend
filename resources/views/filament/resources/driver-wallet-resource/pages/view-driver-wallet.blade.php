<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-wallets', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $o = $this->getOverview();
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $relationManagers = $this->getRelationManagers();
    @endphp

    @if ($o['status'] === 2 && $o['balance'] > 0)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-lock-closed class="h-5 w-5" />
            <span>Tài xế đang <b>bị khóa</b> nhưng ví còn {{ $money($o['balance']) }}.</span>
        </div>
    @endif
    @if (! $o['recon_ok'])
        <div class="fs-rr-notice" role="alert">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Số dư không khớp tổng giao dịch.</b> Cần kiểm tra sổ cái trước khi điều chỉnh.</span>
        </div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Số dư</span><strong @class(['fs-dr-low' => $o['balance'] < 0])>{{ $money($o['balance']) }}</strong><small>{{ $o['low'] ? 'dưới ngưỡng cảnh báo '.$money(\App\Filament\Resources\DriverWalletResource::lowThreshold()) : 'ví hiện tại' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Vào 30 ngày</span><strong><span class="fs-sc-up">+{{ $money($o['in']) }}</span></strong><small>tiền cộng vào ví</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Ra 30 ngày</span><strong><span class="fs-sc-down">−{{ $money($o['out']) }}</span></strong><small>tiền trừ khỏi ví</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Chờ rút</span><strong>{{ $money($o['pendingWithdraw']) }}</strong><small><a href="{{ $o['withdrawUrl'] }}" wire:navigate>Xem yêu cầu rút tiền</a></small></article>
    </div>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Tiền vào/ra theo nguồn</h2><p>30 ngày qua</p></div></header>
            @forelse ($o['categories'] as $c)
                <div class="fs-dr-row">
                    <span>{{ $c['label'] }}</span>
                    <span class="fs-sc-settle">
                        @if ($c['in']) <b class="fs-sc-up">+{{ $money($c['in']) }}</b> @endif
                        @if ($c['out']) <b class="fs-sc-down">−{{ $money($c['out']) }}</b> @endif
                    </span>
                </div>
            @empty
                <p class="fs-rr-note">Chưa có giao dịch trong 30 ngày.</p>
            @endforelse
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Công nợ</h2><p>Khoản chưa thanh toán</p></div></header>
            <div class="fs-dr-row"><span>Còn nợ</span><b @class(['fs-dr-low' => $o['debt'] > 0])>{{ $money($o['debt']) }}</b></div>
            <p class="fs-rr-note"><a href="{{ $o['debtUrl'] }}" wire:navigate>Xem công nợ</a></p>
        </section>
    </div>

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers
            :active-manager="$this->activeRelationManager ?? array_key_first($relationManagers)"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        />
    @endif
</x-filament-panels::page>
