<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-shifts', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $o = $this->getOverview();
        $r = $o['row'];
        $num = fn ($v) => number_format((float) $v, 0, ',', '.');
        $dec = fn ($v) => number_format((float) $v, 1, ',', '.');
        $maxH = max(1, ...array_values($o['hours'] ?: [1]));
        $roster = $o['roster'];
        $statusMap = [1 => ['Hoạt động', 'success'], 2 => ['Đã khóa', 'danger'], 0 => ['Chờ duyệt', 'warning']];
    @endphp

    @if (! $record->is_active)
        <div class="fs-rr-notice" role="status"><x-heroicon-o-information-circle class="h-5 w-5" /><span>Ca đang <b>tắt</b>: không chấm điểm cuối ca và không cho đăng ký mới.</span></div>
    @elseif (\App\Services\ShiftService::inProgress($record))
        <div class="fs-rr-notice" role="status"><x-heroicon-o-clock class="h-5 w-5" /><span>Ca <b>đang diễn ra</b> nên chưa đổi giờ được.</span></div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tài xế hoạt động</span><strong>{{ $num($r['active']) }}</strong><small>{{ $num($r['online']) }} online{{ $r['locked'] ? ' · '.$num($r['locked']).' đã khóa' : '' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn/ngày</span><strong>{{ $dec($r['perDay']) }}</strong><small>trung bình 14 ngày qua</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn/tài xế/ngày</span><strong @class(['fs-dr-low' => $r['overloaded']])>{{ $r['perDriver'] !== null ? $dec($r['perDriver']) : '—' }}</strong><small>{{ $r['overloaded'] ? 'quá tải (từ '.$o['overload'].')' : 'trong ngưỡng' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Dưới 50% online</span><strong @class(['fs-dr-low' => ($r['critical'] ?? 0) >= 40])>{{ $r['critical'] !== null ? $r['critical'].'%' : '—' }}</strong><small>{{ $num($r['scored']) }} lượt chấm điểm (tài xế hoạt động)</small></article>
    </div>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Đơn theo giờ</h2><p>Đơn hoàn thành trung bình mỗi ngày trong khung ca</p></div></header>
            @foreach ($o['hours'] as $h => $v)
                <div class="fs-sc-loss">
                    <div class="fs-sc-loss__top"><span>{{ sprintf('%02d:00', $h) }}</span><b class="fs-plain">{{ $dec($v) }}</b></div>
                    <div class="fs-sc-bar"><i style="width: {{ round($v / $maxH * 100) }}%"></i></div>
                </div>
            @endforeach
        </section>

        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Nhật ký</h2><p>Tạo, đổi giờ, bật/tắt ca</p></div></header>
                @forelse ($o['logs'] as $l)
                    <div class="fs-cp-addr"><b>{{ $l['label'] }}</b><span>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}{{ $l['detail'] ? ' · '.$l['detail'] : '' }}</span></div>
                @empty
                    <p class="fs-rr-note">Chưa có thay đổi nào được ghi lại.</p>
                @endforelse
            </section>
        </div>
    </div>

    <section class="fs-rr-card">
        <header class="fs-rr-card__head"><div><h2>Tài xế đăng ký ca</h2><p>{{ $num($roster->count()) }} người · tài xế hoạt động xếp trước</p></div></header>
        @forelse ($roster as $d)
            @php [$sl, $sc] = $statusMap[$d['status']] ?? ['Không rõ', 'gray']; @endphp
            <a class="fs-dr-row" href="{{ $d['url'] }}" wire:navigate>
                <span>{{ $d['name'] }} <small>{{ $d['phone'] }}</small></span>
                <span class="fs-sc-settle">
                    @if ($d['online']) <b class="fs-sc-up">Online</b> @endif
                    <span class="fs-order-pill fs-order-pill--{{ $sc }}">{{ $sl }}</span>
                </span>
            </a>
        @empty
            <p class="fs-rr-note">Chưa có tài xế nào đăng ký ca này.</p>
        @endforelse
    </section>
</x-filament-panels::page>
