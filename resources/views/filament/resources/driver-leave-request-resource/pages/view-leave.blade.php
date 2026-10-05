<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-leaves', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $R = \App\Filament\Resources\DriverLeaveRequestResource::class;
        $o = $this->getOverview();
        $l = $record;
    @endphp

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Ngày nghỉ</span><strong style="font-size: var(--fs-lg)">{{ $l->leave_date->format('d/m/Y') }}</strong><small>{{ $R::dateDescription($l->leave_date) }}</small></article>
        <article class="fs-rr-kpi" style="grid-column: span 2"><span class="fs-rr-kpi__label">Ca được miễn chấm</span><strong style="font-size: var(--fs-base)">{{ $o['shifts']->map(fn ($s) => $s->name.' '.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5))->implode(', ') ?: 'Chưa đăng ký ca' }}</strong><small>miễn chấm điểm cuối ca của cả ngày</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn trong ngày</span><strong>{{ $o['orders'] }}</strong><small>{{ $o['ordersBefore'] }} đơn xong trước khi ghi nhận</small></article>
    </div>

    @if ($o['flags'])
        <div class="fs-rr-legend">@foreach ($o['flags'] as $f)<span class="fs-order-pill fs-order-pill--{{ $f['level'] }}">{{ $f['label'] }}</span>@endforeach</div>
    @endif

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Phiếu nghỉ</h2></div></header>
            <div class="fs-dr-row"><span>Lý do</span><b>{{ $l->note ?: '—' }}</b></div>
            <div class="fs-dr-row"><span>Ghi nhận bởi</span><b>{{ $l->creator?->name ?? 'Không xác định' }}</b></div>
            <div class="fs-dr-row"><span>Ghi nhận lúc</span><b>{{ $l->created_at?->format('H:i d/m/Y') }}</b></div>

            <header class="fs-rr-card__head" style="margin-top:1.25rem"><div><h2>Nhật ký</h2></div></header>
            @forelse ($o['logs'] as $g)
                <div class="fs-cp-addr"><b>{{ $g['label'] }}</b><span>{{ $g['when']->format('H:i · d/m/Y') }} · {{ $g['who'] }}{{ $g['note'] ? ' · '.$g['note'] : '' }}</span></div>
            @empty
                <p class="fs-rr-note">Phiếu tạo trước khi có nhật ký.</p>
            @endforelse
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Lịch sử nghỉ 90 ngày</h2><p>{{ $o['history']->count() }} lượt của tài xế</p></div></header>
            @forelse ($o['history'] as $h)
                <a class="fs-dr-row" href="{{ $R::getUrl('view', ['record' => $h]) }}" wire:navigate>
                    <span>{{ $h->leave_date->format('d/m/Y') }}</span><small>{{ \Illuminate\Support\Str::limit($h->note ?: '', 30) }}</small>
                </a>
            @empty
                <p class="fs-rr-note">Chưa có lượt nghỉ nào.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
