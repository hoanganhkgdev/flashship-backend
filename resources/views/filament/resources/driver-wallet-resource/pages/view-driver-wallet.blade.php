<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-wallets', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/driver-wallet.css') }}?v={{ filemtime(public_path('css/driver-wallet.css')) }}">
    @php
        $o = $this->getOverview();
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $relationManagers = $this->getRelationManagers();
        $threshold = \App\Filament\Resources\DriverWalletResource::lowThreshold();
        $maxCat = max(1, (int) $o['categories']->map(fn ($c) => max($c['in'], $c['out']))->max());
        [$statusLabel, $statusChip] = match (true) {
            $o['status'] === 2 => ['Đã khóa', 'red'],
            $o['status'] === 0 => ['Chờ duyệt', 'amber'],
            default => ['Đang hoạt động', 'green'],
        };
        $balanceClass = $o['balance'] < 0 ? 'is-neg' : ($o['low'] ? 'is-low' : '');
    @endphp

    <div class="fs-wallet">
        @if ($o['status'] === 2 && $o['balance'] > 0)
            <div class="fs-wallet__ledger is-warn" role="status">
                <x-heroicon-o-lock-closed />
                <span>Tài xế đang <b>bị khóa</b> nhưng ví còn {{ $money($o['balance']) }}.</span>
            </div>
        @endif
        @if (! $o['recon_ok'])
            <div class="fs-wallet__ledger is-bad" role="alert">
                <x-heroicon-o-exclamation-triangle />
                <span><b>Số dư không khớp tổng giao dịch.</b> Cần kiểm tra sổ cái trước khi điều chỉnh.</span>
            </div>
        @endif
        @if ($o['overdueDebt'] > 0)
            <div class="fs-wallet__ledger is-warn" role="status">
                <x-heroicon-o-banknotes />
                <span>Tài xế còn <b>nợ quá hạn {{ $money($o['overdueDebt']) }}</b>. Khoản này được giữ lại trong ví, chỉ rút được phần vượt quá. <a href="{{ $o['debtUrl'] }}" wire:navigate>Xem công nợ</a></span>
            </div>
        @endif

        <section class="fs-wallet__card fs-wallet__summary" aria-label="Số dư ví">
            <div class="fs-wallet__balance">
                <span>Số dư ví</span>
                <strong class="{{ $balanceClass }}">{{ $money($o['balance']) }}</strong>
                <div class="fs-wallet__chips">
                    <span class="fs-wallet__chip fs-wallet__chip--{{ $statusChip }}">{{ $statusLabel }}</span>
                    @if ($o['low'])<span class="fs-wallet__chip fs-wallet__chip--amber">Dưới ngưỡng {{ $money($threshold) }}</span>@endif
                    @if ($o['recon_ok'])<span class="fs-wallet__chip fs-wallet__chip--gray">Sổ cái khớp</span>@endif
                </div>
                <div class="fs-wallet__inout">
                    <span>Vào 30 ngày <b class="fs-wallet__up">+{{ $money($o['in']) }}</b></span>
                    <span>Ra 30 ngày <b class="fs-wallet__down">−{{ $money($o['out']) }}</b></span>
                </div>
            </div>

            <dl class="fs-wallet__facts">
                <div><dt>Rút được tối đa</dt><dd>{{ $money($o['withdrawable']) }}@if ($o['overdueDebt'] > 0)<small>đã trừ nợ quá hạn</small>@endif</dd></div>
                <div><dt>Nợ quá hạn đang giữ</dt><dd @class(['is-alert' => $o['overdueDebt'] > 0])>{{ $money($o['overdueDebt']) }}</dd></div>
                <div><dt>Công nợ còn lại</dt><dd @class(['is-alert' => $o['debt'] > 0])>{{ $money($o['debt']) }} <small><a href="{{ $o['debtUrl'] }}" wire:navigate>xem công nợ</a></small></dd></div>
                <div><dt>Chờ rút</dt><dd>{{ $money($o['pendingWithdraw']) }} <small><a href="{{ $o['withdrawUrl'] }}" wire:navigate>xem yêu cầu rút</a></small></dd></div>
                <div><dt>Khu vực</dt><dd>{{ $o['city'] ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="fs-wallet__card" aria-label="Tiền vào ra theo nguồn">
            <header class="fs-wallet__card-head"><div><h2>Tiền vào/ra theo nguồn</h2><p>30 ngày qua · {{ number_format($o['transactions'], 0, ',', '.') }} giao dịch từ trước tới nay</p></div></header>
            @if ($o['categories']->isEmpty())
                <p class="fs-wallet__empty">Chưa có giao dịch trong 30 ngày.</p>
            @else
                <div class="fs-wallet__flow">
                    @foreach ($o['categories'] as $c)
                        <div class="fs-wallet__flow-row">
                            <span class="fs-wallet__flow-name"><b>{{ $c['label'] }}</b></span>
                            <span class="fs-wallet__bars" aria-hidden="true">
                                @if ($c['in'])<i class="is-in" style="width: {{ max(1, round($c['in'] / $maxCat * 100)) }}%"></i>@endif
                                @if ($c['out'])<i class="is-out" style="width: {{ max(1, round($c['out'] / $maxCat * 100)) }}%"></i>@endif
                            </span>
                            <span class="fs-wallet__flow-amt">
                                @if ($c['in'])<span class="is-in">+{{ $money($c['in']) }}</span>@endif
                                @if ($c['out'])<span class="is-out">−{{ $money($c['out']) }}</span>@endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        @if (count($relationManagers))
            <x-filament-panels::resources.relation-managers
                :active-manager="$this->activeRelationManager ?? array_key_first($relationManagers)"
                :managers="$relationManagers"
                :owner-record="$record"
                :page-class="static::class"
            />
        @endif
    </div>
</x-filament-panels::page>
