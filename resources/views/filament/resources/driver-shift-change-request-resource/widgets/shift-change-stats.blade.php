<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/shift-change.css') }}?v={{ filemtime(public_path('css/shift-change.css')) }}">
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $wait = $s['oldestHours'] >= 48 ? intdiv($s['oldestHours'], 24).' ngày' : ($s['oldestHours'] >= 1 ? $s['oldestHours'].' giờ' : 'dưới 1 giờ');
    @endphp

    <div class="fs-chg">
        <section class="fs-chg__kpis" aria-label="Tổng quan yêu cầu đổi ca">
            <article class="fs-chg__kpi fs-chg__kpi--hero">
                <div class="fs-chg__kpi-head"><span>Đang chờ duyệt</span><x-heroicon-o-arrow-path-rounded-square /></div>
                <strong>{{ $num($s['pending']) }}<em>yêu cầu</em></strong>
                <small>{{ $s['pending'] ? $num($s['approvableNow']).' duyệt được ngay · '.$num($s['invalid']).' không còn hợp lệ' : 'không có yêu cầu chờ' }}</small>
            </article>
            <article class="fs-chg__kpi fs-chg__kpi--red">
                <div class="fs-chg__kpi-head"><span>Chờ lâu nhất</span><x-heroicon-o-clock /></div>
                <strong @class(['is-alert' => $s['stale'] > 0])>{{ $s['pending'] ? $wait : '—' }}</strong>
                <small>{{ $s['stale'] ? $num($s['stale']).' yêu cầu quá '.$staleHours.' giờ' : 'chưa có yêu cầu quá '.$staleHours.' giờ' }}</small>
            </article>
            <article class="fs-chg__kpi fs-chg__kpi--green">
                <div class="fs-chg__kpi-head"><span>Duyệt được ngay</span><x-heroicon-o-check-circle /></div>
                <strong>{{ $num($s['approvableNow']) }}</strong>
                <small>{{ $s['invalid'] ? $num($s['invalid']).' yêu cầu không còn hợp lệ, có thể từ chối nhanh' : 'các yêu cầu đang chờ đều hợp lệ' }}</small>
            </article>
            <article class="fs-chg__kpi fs-chg__kpi--blue">
                <div class="fs-chg__kpi-head"><span>Tỷ lệ duyệt 30 ngày</span><x-heroicon-o-chart-pie /></div>
                <strong>{{ $s['approveRate'] !== null ? $s['approveRate'] : '—' }}@if ($s['approveRate'] !== null)<em>%</em>@endif</strong>
                <small>{{ $num($s['processed']) }} yêu cầu đã xử lý trong 30 ngày</small>
            </article>
        </section>
    </div>
</x-filament-widgets::widget>
