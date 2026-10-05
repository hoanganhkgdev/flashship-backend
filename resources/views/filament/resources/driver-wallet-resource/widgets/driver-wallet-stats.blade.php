<x-filament-widgets::widget>
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $recon = $s['recon'];
        $totalIn = $flow->sum('in'); $totalOut = $flow->sum('out');
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Số dư tài xế đang hoạt động</span>
            <strong>{{ $money($s['activeMoney']) }}</strong>
            <small>{{ $num($s['activeCount']) }} ví · tiền phải trả cho tài xế</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Trong ví tài xế đã khóa</span>
            <strong>{{ $money($s['lockedMoney']) }}</strong>
            <small>{{ $num($s['lockedWithMoney']) }} trên {{ $num($s['lockedCount']) }} ví còn tiền</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ rút</span>
            <strong>{{ $money($s['pendingMoney']) }}</strong>
            <small>{{ $s['pendingCount'] ? $num($s['pendingCount']).' yêu cầu đang chờ duyệt' : 'không có yêu cầu chờ' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Hôm nay</span>
            <strong><span class="fs-sc-up">+{{ $money($s['in']) }}</span></strong>
            <small>vào · <span class="fs-sc-down">−{{ $money($s['out']) }}</span> ra</small>
        </article>
    </div>

    @if ($recon['bad'] > 0)
        <div class="fs-rr-notice" role="alert">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Sổ cái lệch ở {{ $num($recon['bad']) }} trên {{ $num($recon['total']) }} ví</b> — số dư không bằng tổng giao dịch. Tài xế cần kiểm tra: {{ implode(', ', array_map(fn ($id) => '#'.$id, $recon['sample'])) }}.</span>
        </div>
    @else
        <p class="fs-rr-note" style="margin-top:.75rem"><span class="fs-sc-up">✓ Sổ cái khớp {{ $num($recon['total']) }}/{{ $num($recon['total']) }} ví</span> — số dư từng ví bằng đúng tổng giao dịch.</p>
    @endif

    <div class="fs-cp-grid fs-sc-panels">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Dòng tiền 30 ngày</h2><p>Vào {{ $money($totalIn) }} · ra {{ $money($totalOut) }}, theo nguồn</p></div></header>
            @forelse ($flow as $f)
                <div class="fs-dr-row">
                    <span>{{ $f['label'] }} <small>({{ $num($f['n']) }} giao dịch)</small></span>
                    <span class="fs-sc-settle">
                        @if ($f['in']) <b class="fs-sc-up">+{{ $money($f['in']) }}</b> @endif
                        @if ($f['out']) <b class="fs-sc-down">−{{ $money($f['out']) }}</b> @endif
                    </span>
                </div>
            @empty
                <div class="fs-empty"><div>Chưa có giao dịch nào trong 30 ngày.</div></div>
            @endforelse
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Admin điều chỉnh gần đây</h2><p>Ai, bao nhiêu và vì sao</p></div></header>
            @forelse ($adjustments as $a)
                <div class="fs-cp-addr">
                    <b><a href="{{ $a['url'] }}" wire:navigate>{{ $a['driver'] }}</a>
                        <span class="{{ $a['type'] === 'credit' ? 'fs-sc-up' : 'fs-sc-down' }}">{{ $a['type'] === 'credit' ? '+' : '−' }}{{ $money($a['amount']) }}</span></b>
                    <span>{{ \Carbon\Carbon::parse($a['created_at'])->format('H:i d/m') }} · {{ $a['admin'] ?? 'Không rõ ai' }} · {{ \Illuminate\Support\Str::limit(preg_replace('/ \(admin\)$/', '', (string) $a['description']), 60) }}</span>
                </div>
            @empty
                <p class="fs-rr-note">Chưa có điều chỉnh nào.</p>
            @endforelse
        </section>
    </div>
</x-filament-widgets::widget>
