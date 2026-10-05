<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-shift-changes', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $R = \App\Filament\Resources\DriverShiftChangeRequestResource::class;
        $o = $this->getOverview();
        $r = $record;
        [$statusLabel, $statusColor] = $R::statusLabel($r->status);
        $fmt = fn ($shifts) => $shifts->map(fn ($s) => $s->name.' '.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5))->implode(', ') ?: 'Chưa có ca';
    @endphp

    @if ($o['can'] && ! $o['can']['ok'])
        <div class="fs-rr-notice" role="status"><x-heroicon-o-clock class="h-5 w-5" /><span><b>Chưa duyệt được:</b> {{ $o['can']['reason'] }}.</span></div>
    @elseif ($o['can'])
        <div class="fs-rr-notice" role="status"><x-heroicon-o-check-circle class="h-5 w-5" /><span><b>{{ $o['can']['reason'] }}.</b> {{ $R::waitLabel($r) }}.</span></div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Trạng thái</span><strong style="font-size: var(--fs-lg)"><span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span></strong><small>{{ $r->status === 'pending' ? $R::waitLabel($r) : ($r->processed_at?->format('H:i d/m/Y')) }}</small></article>
        <article class="fs-rr-kpi" style="grid-column: span 2"><span class="fs-rr-kpi__label">Ca hiện tại → Ca đề nghị</span><strong style="font-size: var(--fs-base)">{{ $fmt($o['current']) }} → {{ $fmt($o['requested']) }}</strong><small>{{ $r->driver?->is_online ? 'tài xế đang online' : 'tài xế offline' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Gửi lúc</span><strong style="font-size: var(--fs-lg)">{{ $r->created_at->format('H:i d/m') }}</strong><small>{{ $r->created_at->format('Y') }}</small></article>
    </div>

    @if ($o['flags'])
        <div class="fs-rr-legend">@foreach ($o['flags'] as $f)<span class="fs-order-pill fs-order-pill--{{ $f['level'] }}">{{ $f['label'] }}</span>@endforeach</div>
    @endif

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Tác động lên từng ca</h2><p>{{ $r->status === 'pending' ? 'Số tài xế hoạt động và tải đơn, trước → sau khi duyệt' : 'Chỉ hiển thị với yêu cầu đang chờ' }}</p></div></header>
            @forelse ($o['impact'] as $i)
                <div class="fs-dr-row">
                    <span>{{ $i['shift']->name }} <small>{{ substr($i['shift']->start_time, 0, 5) }}–{{ substr($i['shift']->end_time, 0, 5) }}</small></span>
                    <span class="fs-sc-settle">
                        <b>{{ $i['before'] }} → {{ $i['after'] }} tài xế</b>
                        <b class="{{ $i['warn'] ? 'fs-sc-down' : '' }}">{{ $i['perBefore'] ?? '—' }} → {{ $i['perAfter'] ?? '—' }} đơn/tài xế/ngày</b>
                    </span>
                </div>
                @if ($i['warn'])<p class="fs-rr-note fs-sc-down">⚠ {{ $i['warn'] }}</p>@endif
            @empty
                <p class="fs-rr-note">Không có dữ liệu tác động.</p>
            @endforelse

            <header class="fs-rr-card__head" style="margin-top:1.25rem"><div><h2>Kết quả xử lý</h2></div></header>
            @if ($r->status === 'pending')
                <p class="fs-rr-note">Chưa xử lý.</p>
            @else
                <div class="fs-cp-addr"><b>{{ $statusLabel }}</b><span>{{ $r->processed_at?->format('H:i · d/m/Y') }} · {{ $r->processor?->name ?? 'Quản trị viên' }}{{ $r->admin_note ? ' · '.$r->admin_note : '' }}</span></div>
            @endif
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Các yêu cầu trước</h2><p>8 yêu cầu gần nhất của tài xế</p></div></header>
            @forelse ($o['history'] as $h)
                @php [$hl, $hc] = $R::statusLabel($h->status); @endphp
                <a class="fs-dr-row" href="{{ $R::getUrl('view', ['record' => $h]) }}" wire:navigate>
                    <span>{{ $h->created_at->format('d/m/Y') }} · <span class="fs-order-pill fs-order-pill--{{ $hc }}">{{ $hl }}</span></span>
                    <small>{{ \Illuminate\Support\Str::limit($h->admin_note ?: '', 28) }}</small>
                </a>
            @empty
                <p class="fs-rr-note">Đây là yêu cầu đầu tiên.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
