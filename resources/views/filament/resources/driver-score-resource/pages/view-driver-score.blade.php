<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-scores', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $o = $this->getOverview();
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $ringColor = ['success' => '#16a34a', 'info' => '#0ea5e9', 'primary' => '#f97316', 'gray' => '#94a3b8', 'danger' => '#dc2626'][$o['color']] ?? '#f97316';
        $pill = ['success' => 'success', 'info' => 'info', 'primary' => 'warning', 'gray' => 'gray', 'danger' => 'danger'][$o['color']] ?? 'gray';
        $relationManagers = $this->getRelationManagers();

        // Biểu đồ đường 30 ngày (SVG, không phụ thuộc thư viện).
        $series = $o['series'];
        $w = 640; $h = 160; $pad = 8;
        $min = max(0, min(array_column($series, 'score')) - 5);
        $maxY = max(array_column($series, 'score')) + 5;
        $span = max(1, $maxY - $min);
        $pts = [];
        foreach ($series as $i => $pt) {
            $x = $pad + ($w - 2 * $pad) * $i / max(1, count($series) - 1);
            $y = $h - $pad - ($h - 2 * $pad) * ($pt['score'] - $min) / $span;
            $pts[] = round($x, 1).','.round($y, 1);
        }
        $yFor = fn ($v) => round($h - $pad - ($h - 2 * $pad) * ($v - $min) / $span, 1);
    @endphp

    @if ($o['suspendedUntil'])
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Đang bị suspend nhận đơn đến {{ $o['suspendedUntil']->format('H:i d/m/Y') }}</b> do điểm thấp.</span>
        </div>
    @endif
    @if ($o['status'] !== 1)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-information-circle class="h-5 w-5" />
            <span>Tài khoản {{ $o['status'] === 2 ? 'đang bị khóa' : 'đang chờ duyệt' }} nên <b>không được tính thưởng/phạt tuần</b>.</span>
        </div>
    @endif

    <section class="fs-rr-card">
        <div class="fs-sc-hero">
            <div class="fs-sc-ring" style="--pct: {{ $o['pct'] }}; --ring-color: {{ $ringColor }}">
                <div><span><strong>{{ $o['score'] }}</strong><br><small>/ {{ $o['max'] }}</small></span></div>
            </div>
            <div class="fs-cp-profile__main">
                <h2>{{ $record->name }} <span class="fs-order-pill fs-order-pill--{{ $pill }}">{{ $o['rank'] }}</span></h2>
                <dl>
                    <div><dt>Mốc thưởng</dt><dd>từ {{ $o['bonusScore'] }} điểm</dd></div>
                    <div><dt>Mốc phạt</dt><dd>từ {{ $o['penaltyScore'] }} điểm trở xuống</dd></div>
                    <div><dt>Chuỗi đơn liên tiếp</dt><dd>{{ $num($o['streak']) }} đơn</dd></div>
                    <div><dt>Thưởng chuỗi hôm nay</dt><dd>+{{ $o['bonusToday'] }} / {{ $o['bonusCap'] }}</dd></div>
                    <div>
                        <dt>Dự kiến chốt tuần</dt>
                        <dd>
                            @if ($o['outcome'] === 'bonus') <span class="fs-sc-up">Thưởng {{ $money($o['outcomeAmount']) }}</span>
                            @elseif ($o['outcome'] === 'penalty') <span class="fs-sc-down">Phạt {{ $money($o['outcomeAmount']) }}</span>
                            @else Không thưởng/phạt @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Lần đặt lại gần nhất</dt>
                        <dd>
                            @if ($o['lastReset'])
                                {{ \Carbon\Carbon::parse($o['lastReset']->created_at)->format('d/m/Y H:i') }}
                                · {{ $o['lastReset']->reason === 'manual_reset' ? 'thủ công' : 'đầu tuần' }}
                            @else — @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Điểm 30 ngày</h2><p>Điểm cuối mỗi ngày, từ {{ $series[0]['label'] }} đến {{ end($series)['label'] }}</p></div></header>
            <svg class="fs-sc-trend" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="img" aria-label="Biểu đồ điểm 30 ngày">
                @foreach ([$o['penaltyScore'] => '#dc2626', $o['bonusScore'] => '#16a34a'] as $line => $c)
                    @if ($line > $min && $line < $maxY)
                        <line x1="0" x2="{{ $w }}" y1="{{ $yFor($line) }}" y2="{{ $yFor($line) }}" stroke="{{ $c }}" stroke-width="1" stroke-dasharray="4 4" opacity=".55" vector-effect="non-scaling-stroke" />
                    @endif
                @endforeach
                <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#f97316" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </svg>
            <div class="fs-rr-legend" style="margin-top:.5rem">
                <span><i class="fs-rr-legend__line" style="background:#f97316"></i>Điểm</span>
                <span><i class="fs-rr-legend__dash" style="border-color:#16a34a"></i>Mốc thưởng {{ $o['bonusScore'] }}</span>
                <span><i class="fs-rr-legend__dash" style="border-color:#dc2626"></i>Mốc phạt {{ $o['penaltyScore'] }}</span>
            </div>
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Điểm đến từ đâu</h2><p>30 ngày, tính điểm thực đổi</p></div></header>
            @forelse ($o['breakdown'] as $b)
                <div class="fs-dr-row">
                    <span>{{ $b['label'] }} <small>({{ $num($b['times']) }} lần)</small></span>
                    <b class="{{ $b['net'] > 0 ? 'fs-sc-up' : 'fs-sc-down' }}">{{ $b['net'] > 0 ? '+' : '−' }}{{ $num(abs($b['net'])) }}</b>
                </div>
            @empty
                <p class="fs-rr-note">Chưa có biến động điểm trong 30 ngày.</p>
            @endforelse
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
