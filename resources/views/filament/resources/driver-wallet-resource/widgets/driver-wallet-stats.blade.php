<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/driver-wallet.css') }}?v={{ filemtime(public_path('css/driver-wallet.css')) }}">
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $recon = $s['recon'];
        $totalIn = $flow->sum('in');
        $totalOut = $flow->sum('out');
        $maxFlow = max(1, (int) $flow->map(fn ($f) => max($f['in'], $f['out']))->max());
    @endphp

    <div class="fs-wallet">
        <section class="fs-wallet__kpis" aria-label="Tổng quan ví tài xế">
            <article class="fs-wallet__kpi fs-wallet__kpi--hero">
                <div class="fs-wallet__kpi-head"><span>Phải trả tài xế đang hoạt động</span><x-heroicon-o-wallet /></div>
                <strong>{{ $money($s['activeMoney']) }}</strong>
                <small>{{ $num($s['activeCount']) }} ví · tiền trong ví là tiền của tài xế</small>
            </article>
            <article class="fs-wallet__kpi fs-wallet__kpi--gray">
                <div class="fs-wallet__kpi-head"><span>Trong ví tài xế đã khóa</span><x-heroicon-o-lock-closed /></div>
                <strong>{{ $money($s['lockedMoney']) }}</strong>
                <small>{{ $num($s['lockedWithMoney']) }} trên {{ $num($s['lockedCount']) }} ví còn tiền</small>
            </article>
            <article class="fs-wallet__kpi fs-wallet__kpi--amber">
                <div class="fs-wallet__kpi-head"><span>Đang chờ rút</span><x-heroicon-o-arrow-up-tray /></div>
                <strong>{{ $money($s['pendingMoney']) }}</strong>
                <small>{{ $s['pendingCount'] ? $num($s['pendingCount']).' yêu cầu chờ duyệt (tiền đã được giữ khỏi ví)' : 'không có yêu cầu chờ' }}</small>
            </article>
            <article class="fs-wallet__kpi fs-wallet__kpi--blue">
                <div class="fs-wallet__kpi-head"><span>Hôm nay</span><x-heroicon-o-arrows-up-down /></div>
                <strong><span class="fs-wallet__up">+{{ $money($s['in']) }}</span></strong>
                <small>vào · <span class="fs-wallet__down">−{{ $money($s['out']) }}</span> ra</small>
            </article>
        </section>

        @if ($recon['bad'] > 0)
            <div class="fs-wallet__ledger is-bad" role="alert">
                <x-heroicon-o-exclamation-triangle />
                <span><b>Sổ cái lệch ở {{ $num($recon['bad']) }} trên {{ $num($recon['total']) }} ví</b>: số dư không bằng tổng giao dịch. Cần kiểm tra tài xế {{ implode(', ', array_map(fn ($id) => '#'.$id, $recon['sample'])) }}.</span>
            </div>
        @else
            <div class="fs-wallet__ledger" role="status">
                <x-heroicon-o-check-circle />
                Sổ cái khớp {{ $num($recon['total']) }}/{{ $num($recon['total']) }} ví <span>· số dư từng ví bằng đúng tổng giao dịch</span>
            </div>
        @endif

        <div class="fs-wallet__grid">
            <section class="fs-wallet__card" aria-label="Dòng tiền 30 ngày">
                <header class="fs-wallet__card-head">
                    <div>
                        <h2>Dòng tiền 30 ngày</h2>
                        <p>Vào <b class="fs-wallet__up">{{ $money($totalIn) }}</b> · ra <b class="fs-wallet__down">{{ $money($totalOut) }}</b>, theo nguồn</p>
                    </div>
                </header>
                @if ($flow->isEmpty())
                    <p class="fs-wallet__empty">Chưa có giao dịch nào trong 30 ngày.</p>
                @else
                    <div class="fs-wallet__flow">
                        @foreach ($flow as $f)
                            <div class="fs-wallet__flow-row">
                                <span class="fs-wallet__flow-name"><b>{{ $f['label'] }}</b><small>{{ $num($f['n']) }} giao dịch</small></span>
                                <span class="fs-wallet__bars" aria-hidden="true">
                                    @if ($f['in'])<i class="is-in" style="width: {{ max(1, round($f['in'] / $maxFlow * 100)) }}%"></i>@endif
                                    @if ($f['out'])<i class="is-out" style="width: {{ max(1, round($f['out'] / $maxFlow * 100)) }}%"></i>@endif
                                </span>
                                <span class="fs-wallet__flow-amt">
                                    @if ($f['in'])<span class="is-in">+{{ $money($f['in']) }}</span>@endif
                                    @if ($f['out'])<span class="is-out">−{{ $money($f['out']) }}</span>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="fs-wallet__card" aria-label="Admin điều chỉnh gần đây">
                <header class="fs-wallet__card-head"><div><h2>Admin điều chỉnh gần đây</h2><p>Ai, bao nhiêu và vì sao</p></div></header>
                @if ($adjustments->isEmpty())
                    <p class="fs-wallet__empty">Chưa có điều chỉnh nào.</p>
                @else
                    <ul class="fs-wallet__adj">
                        @foreach ($adjustments as $a)
                            @php $in = $a['type'] === 'credit'; @endphp
                            <li>
                                <span class="fs-wallet__sign {{ $in ? 'is-in' : 'is-out' }}" aria-hidden="true">{{ $in ? '+' : '−' }}</span>
                                <a href="{{ $a['url'] }}" wire:navigate>{{ $a['driver'] }}</a>
                                <b class="{{ $in ? 'is-in' : 'is-out' }}">{{ $in ? '+' : '−' }}{{ $money($a['amount']) }}</b>
                                <small>{{ \Carbon\Carbon::parse($a['created_at'])->format('H:i d/m') }} · {{ $a['admin'] ?? 'Không rõ ai' }} · {{ \Illuminate\Support\Str::limit(preg_replace('/ \(admin\)$/', '', (string) $a['description']), 70) }}</small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-widgets::widget>
