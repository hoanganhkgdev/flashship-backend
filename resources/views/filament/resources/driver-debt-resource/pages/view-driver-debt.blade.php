<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-debts', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/driver-debt.css') }}?v={{ filemtime(public_path('css/driver-debt.css')) }}">
    @php
        $d = $record;
        $driver = $d->driver;
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $remaining = \App\Services\DriverDebtService::remaining($d);
        [$statusLabel, $statusColor] = \App\Filament\Resources\DriverDebtResource::statusLabel($d->status);
        $age = \App\Filament\Resources\DriverDebtResource::ageLabel($d);
        $kind = \App\Filament\Resources\DriverDebtResource::kindLabel($d);
        $timeline = $this->getTimeline();
        $waived = (float) $timeline->where('action', 'waived')->sum('amount');
        // Miễn nợ hạ amount_due về phần đã thu: cộng lại để thấy số phải thu ban đầu.
        $original = max(1, (float) $d->amount_due + $waived);
        $pct = (int) min(100, round($d->amount_paid / $original * 100));
        $waivedPct = (int) min(100 - $pct, round($waived / $original * 100));
        $wallet = (float) ($driver?->wallet?->balance ?? 0);
        $blocked = $d->status === 'overdue' && $remaining > 0 && (int) $driver?->status === 1 && $driver?->debtApplies();
        $period = $d->week_start && $d->week_end
            ? \Carbon\Carbon::parse($d->week_start)->format('d/m').' – '.\Carbon\Carbon::parse($d->week_end)->format('d/m/Y')
            : \App\Filament\Resources\DriverDebtResource::dueDate($d)->format('d/m/Y');
        $initials = mb_strtoupper(collect(preg_split('/\s+/', trim((string) $driver?->name)))->filter()->slice(-2)->map(fn ($w) => mb_substr($w, 0, 1))->join('')) ?: '?';
        $tone = fn (string $a) => match (true) {
            str_starts_with($a, 'paid_') => ['#16a34a', 'heroicon-m-check'],
            $a === 'waived' => ['#8b5cf6', 'heroicon-m-hand-raised'],
            $a === 'overdue' => ['#dc2626', 'heroicon-m-exclamation-triangle'],
            $a === 'adjusted' => ['#d97706', 'heroicon-m-pencil'],
            $a === 'reminded' => ['#f97316', 'heroicon-m-bell'],
            $a === 'deleted' => ['#64748b', 'heroicon-m-trash'],
            default => ['#2563eb', 'heroicon-m-plus'],
        };
    @endphp

    <div class="fs-debt">
        @if ($blocked)
            <div class="fs-debt__alert" role="status">
                <x-heroicon-o-no-symbol />
                <span><b>Tài xế đang bị chặn nhận đơn</b> vì khoản nợ quá hạn này. Thu đủ là bật online lại được ngay.</span>
            </div>
        @endif

        <section class="fs-debt__card fs-debt__summary">
            <div class="fs-debt__summary-main">
                <div class="fs-debt__tags">
                    <span class="fs-debt__pill fs-debt__pill--{{ $statusColor }}">{{ $statusLabel }}</span>
                    <span>{{ $kind }} · {{ $period }}</span>
                    @if ($age)<span>· {{ $age }}</span>@endif
                </div>
                <div class="fs-debt__remaining">
                    <span>{{ $remaining > 0 ? 'Còn phải thu' : 'Đã hoàn tất' }}</span>
                    <strong @class(['is-alert' => $remaining > 0 && $d->status === 'overdue', 'is-done' => $remaining <= 0])>{{ $money($remaining) }}</strong>
                </div>
                <div class="fs-debt__progress">
                    <span class="fs-debt__meter fs-debt__meter--stack" role="img" aria-label="Đã thu {{ $pct }}%, miễn {{ $waivedPct }}%"><i style="width: {{ $pct }}%"></i>@if ($waivedPct > 0)<i class="is-waived" style="width: {{ $waivedPct }}%"></i>@endif</span>
                    <div class="fs-debt__legend">
                        <span><i style="background:#16a34a"></i>Đã thu <b>{{ $money($d->amount_paid) }}</b> ({{ $pct }}%)</span>
                        <span>Phải thu {{ $waived > 0 ? 'ban đầu' : '' }} <b>{{ $money($d->amount_due + $waived) }}</b></span>
                        @if ($waived > 0)<span><i style="background:#8b5cf6"></i>Đã miễn <b>{{ $money($waived) }}</b></span>@endif
                    </div>
                </div>
                @if ($d->note)
                    <div class="fs-debt__note"><span>Ghi chú</span>{{ $d->note }}</div>
                @endif
            </div>

            <aside class="fs-debt__who" aria-label="Tài xế">
                <div class="fs-debt__who-head">
                    <span class="fs-debt__avatar" aria-hidden="true">{{ $initials }}</span>
                    <div>
                        <b>{{ $driver?->name ?? 'Tài xế đã xóa' }}</b>
                        <small>{{ ($driver?->phone ?? '—').((int) $driver?->status === 2 ? ' · Đã khóa' : '') }}</small>
                    </div>
                </div>
                <dl class="fs-debt__facts">
                    <div><dt>Số dư ví</dt><dd>{{ $money($wallet) }}</dd></div>
                    @if ($remaining > 0)
                        <div><dt>Thu từ ví</dt>
                            <dd @class(['is-ok' => $wallet >= $remaining, 'is-alert' => $wallet <= 0])>{{ $wallet >= $remaining ? 'Đủ thu hết' : ($wallet > 0 ? 'Thu được '.$money($wallet) : 'Ví trống') }}</dd></div>
                    @endif
                    <div><dt>Khu vực phát sinh</dt><dd>{{ $d->city_id ? (\Modules\Core\Models\City::find($d->city_id)?->name ?? '#'.$d->city_id) : ($driver?->city?->name ?? '—') }}</dd></div>
                    <div><dt>Tạo lúc</dt><dd>{{ $d->created_at->format('H:i d/m/Y') }}</dd></div>
                    @if ($d->paid_at)
                        <div><dt>Hoàn tất</dt><dd>{{ $d->paid_at->format('d/m/Y') }} · {{ \App\Services\DriverDebtService::PAID_VIA[$d->paid_via] ?? 'Không rõ' }}</dd></div>
                    @endif
                </dl>
            </aside>
        </section>

        <section class="fs-debt__card">
            <header class="fs-debt__card-head">
                <div><h2>Nhật ký</h2><p>Mọi thao tác trên khoản nợ này, mới nhất ở trên cùng.</p></div>
            </header>
            @if ($timeline->isEmpty())
                <p class="fs-debt__empty">Chưa có thao tác nào. Khoản nợ được tạo tự động hoặc trước khi có nhật ký.</p>
            @else
                <ol class="fs-debt__timeline">
                    @foreach ($timeline as $t)
                        @php [$color, $icon] = $tone($t['action']); @endphp
                        <li style="--tone: {{ $color }}">
                            <span class="fs-debt__dot"><x-dynamic-component :component="$icon" /></span>
                            <b>{{ $t['label'] }}</b>
                            <span class="fs-debt__amt">@if ($t['amount'] != 0){{ str_starts_with($t['action'], 'paid_') ? '+' : '' }}{{ $money($t['amount']) }}@endif</span>
                            <small>{{ $t['when']->format('H:i · d/m/Y') }} · {{ $t['who'] }}</small>
                            @if ($t['note'])<q>{{ $t['note'] }}</q>@endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</x-filament-panels::page>
