<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-debts', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $d = $record;
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $remaining = \App\Services\DriverDebtService::remaining($d);
        $pct = (int) min(100, round($d->amount_paid / max(1, (float) $d->amount_due) * 100));
        [$statusLabel, $statusColor] = \App\Filament\Resources\DriverDebtResource::statusLabel($d->status);
        $age = \App\Filament\Resources\DriverDebtResource::ageLabel($d);
        $timeline = $this->getTimeline();
        $wallet = (float) ($d->driver?->wallet?->balance ?? 0);
        $blocked = $d->status === 'overdue' && (int) $d->driver?->status === 1;
    @endphp

    @if ($blocked)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Tài xế đang bị chặn nhận đơn</b> vì khoản nợ quá hạn này. Thu xong là bật lại được ngay.</span>
        </div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Số nợ</span><strong>{{ $money($d->amount_due) }}</strong><small><span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span> {{ $age }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đã thu</span><strong><span class="fs-sc-up">{{ $money($d->amount_paid) }}</span></strong><small><span class="fs-sc-meter" style="--bar:#16a34a;width:100%"><i style="width:{{ $pct }}%"></i></span>{{ $pct }}%</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Còn lại</span><strong @class(['fs-dr-low' => $remaining > 0])>{{ $money($remaining) }}</strong><small>{{ $d->status === 'paid' ? 'đã đóng' : 'chưa thu' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Ví tài xế</span><strong>{{ $money($wallet) }}</strong><small>{{ $remaining > 0 ? ($wallet >= $remaining ? 'đủ để thu hết' : ($wallet > 0 ? 'thu được một phần' : 'ví trống')) : '—' }}</small></article>
    </div>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Nhật ký</h2><p>Mọi thao tác trên khoản nợ này</p></div></header>
            @forelse ($timeline as $t)
                <div class="fs-cp-addr">
                    <b>{{ $t['label'] }}@if ($t['amount'] != 0) <span class="{{ in_array($t['action'], ['paid_wallet','paid_payos','paid_manual','waived']) ? 'fs-sc-up' : '' }}">{{ $t['amount'] > 0 ? '+' : '' }}{{ $money($t['amount']) }}</span>@endif</b>
                    <span>{{ $t['when']->format('H:i · d/m/Y') }} · {{ $t['who'] }}{{ $t['note'] ? ' · '.$t['note'] : '' }}</span>
                </div>
            @empty
                <p class="fs-rr-note">Chưa có thao tác nào. Khoản nợ được tạo tự động hoặc trước khi có nhật ký.</p>
            @endforelse
        </section>

        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Khoản nợ</h2></div></header>
                <div class="fs-dr-row"><span>Loại</span><b>{{ str_starts_with((string) $d->ref_id, 'score_penalty') ? 'Phạt điểm tuần' : \App\Filament\Resources\DriverDebtResource::debtTypeLabel($d->debt_type) }}</b></div>
                <div class="fs-dr-row"><span>Kỳ</span><b>{{ $d->week_start && $d->week_end ? \Carbon\Carbon::parse($d->week_start)->format('d/m').' – '.\Carbon\Carbon::parse($d->week_end)->format('d/m/Y') : \App\Filament\Resources\DriverDebtResource::dueDate($d)->format('d/m/Y') }}</b></div>
                <div class="fs-dr-row"><span>Tạo lúc</span><b>{{ $d->created_at->format('H:i d/m/Y') }}</b></div>
                <div class="fs-dr-row"><span>Thu bằng</span><b>{{ $d->paid_at ? ($d->paid_at->format('d/m/Y').' · '.(\App\Services\DriverDebtService::PAID_VIA[$d->paid_via] ?? 'Không rõ')) : '—' }}</b></div>
                @if ($d->note)<div class="fs-cp-addr"><b>Ghi chú</b><span>{{ $d->note }}</span></div>@endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
