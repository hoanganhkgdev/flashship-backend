<x-filament-widgets::widget>
    @php
        $num = fn ($v) => number_format((float) $v, 0, ',', '.');
        $dec = fn ($v) => number_format((float) $v, 1, ',', '.');
        $cov = $s['coverage'];
        $cur = $s['current'];
        $busy = $s['busiest'];
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Ca đang chạy</span>
            <strong style="font-size: var(--fs-lg)">{{ $cur ? $cur['shift']->name : 'Ngoài giờ ca' }}</strong>
            <small>{{ $cur ? $num($cur['online']).' tài xế online / '.$num($cur['active']).' đăng ký' : 'chưa có ca nào đang diễn ra' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tài xế hoạt động</span>
            <strong>{{ $num($s['activeDrivers']) }}</strong>
            <small>{{ $s['noShift'] ? $num($s['noShift']).' người chưa đăng ký ca' : 'tất cả đã đăng ký ca' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Ca tải cao nhất</span>
            <strong @class(['fs-dr-low' => $busy && $busy['overloaded']])>{{ $busy ? $dec($busy['perDriver']) : '—' }}</strong>
            <small>{{ $busy ? 'đơn/tài xế/ngày · '.$busy['shift']->name : 'chưa có dữ liệu' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Yêu cầu đổi ca</span>
            <strong>{{ $num($s['pendingChanges']) }}</strong>
            <small><a href="{{ $changesUrl }}" wire:navigate>{{ $s['pendingChanges'] ? 'đang chờ duyệt, xem ngay' : 'không có yêu cầu chờ' }}</a></small>
        </article>
    </div>

    <section class="fs-rr-card" style="margin-top:1.25rem">
        <header class="fs-rr-card__head"><div><h2>Phủ ca và tải đơn</h2><p>{{ $cov['days'] }} ngày qua · tô đỏ khi từ {{ $overload }} đơn/tài xế/ngày</p></div></header>
        @forelse ($cov['rows'] as $r)
            @php $sh = $r['shift']; @endphp
            <a class="fs-dr-row" href="{{ \App\Filament\Resources\ShiftResource::getUrl('view', ['record' => $sh]) }}" wire:navigate style="{{ $sh->is_active ? '' : 'opacity:.55' }}">
                <span>
                    <b>{{ $sh->name }}</b>
                    <small>{{ substr($sh->start_time, 0, 5) }}–{{ substr($sh->end_time, 0, 5) }}{{ $sh->is_active ? '' : ' · đã tắt' }}{{ $r['now'] ? ' · đang chạy' : '' }}</small>
                </span>
                <span class="fs-sc-settle">
                    <b>{{ $num($r['active']) }} tài xế{{ $r['locked'] ? ' (+'.$num($r['locked']).' khóa)' : '' }}</b>
                    <b>{{ $dec($r['perDay']) }} đơn/ngày</b>
                    <b class="{{ $r['overloaded'] ? 'fs-sc-down' : '' }}">{{ $r['perDriver'] !== null ? $dec($r['perDriver']).' đơn/tài xế' : '—' }}</b>
                    <b class="{{ ($r['critical'] ?? 0) >= 40 ? 'fs-sc-down' : '' }}">{{ $r['critical'] !== null ? $r['critical'].'% dưới 50% online' : 'chưa có điểm' }}</b>
                </span>
            </a>
        @empty
            <div class="fs-empty"><div>Khu vực chưa có ca nào.</div></div>
        @endforelse

        @foreach ($cov['gaps'] as $g)
            <div class="fs-dr-row">
                <span>Không có ca <small>{{ $g['from'] }}–{{ $g['to'] }}</small></span>
                <b>{{ $num($g['orders']) }} đơn trong {{ $cov['days'] }} ngày</b>
            </div>
        @endforeach
    </section>
</x-filament-widgets::widget>
