<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/leave.css') }}?v={{ filemtime(public_path('css/leave.css')) }}">
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $R = \App\Filament\Resources\DriverLeaveRequestResource::class;
        $days = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    @endphp

    <div class="fs-lv">
        <section class="fs-lv__kpis" aria-label="Tổng quan nghỉ phép">
            <a class="fs-lv__kpi fs-lv__kpi--hero" href="{{ $R::getUrl('index', ['activeTab' => 'today']) }}" wire:navigate>
                <div class="fs-lv__kpi-head"><span>Nghỉ hôm nay</span><x-heroicon-o-calendar-days /></div>
                <strong>{{ $num($s['today']) }}<em>tài xế</em></strong>
                <small>không bật online, không nhận đơn, không bị tính phạt Offline · ngày mai {{ $num($s['tomorrow']) }} người</small>
            </a>
            <a class="fs-lv__kpi fs-lv__kpi--amber" href="{{ $R::getUrl('index', ['activeTab' => 'pending']) }}" wire:navigate>
                <div class="fs-lv__kpi-head"><span>Chờ duyệt</span><x-heroicon-o-clock /></div>
                <strong @class(['is-alert' => $s['pending'] > 0])>{{ $num($s['pending']) }}</strong>
                <small>{{ $s['pending'] ? 'yêu cầu tài xế gửi từ app, chưa có hiệu lực' : 'không có yêu cầu chờ' }}</small>
            </a>
            <article class="fs-lv__kpi fs-lv__kpi--blue">
                <div class="fs-lv__kpi-head"><span>Nghỉ 7 ngày tới</span><x-heroicon-o-calendar /></div>
                <strong>{{ $num($s['week']) }}<em>lượt</em></strong>
                <small>gồm cả hôm nay, đã duyệt</small>
            </article>
            <article class="fs-lv__kpi fs-lv__kpi--red">
                <div class="fs-lv__kpi-head"><span>Nghỉ nhiều</span><x-heroicon-o-exclamation-triangle /></div>
                <strong>{{ $num($s['frequent']) }}<em>tài xế</em></strong>
                <small>nghỉ từ {{ $frequentAt }} ngày trong 30 ngày qua</small>
            </article>
        </section>

        @if ($s['expired'] > 0)
            <div class="fs-lv__notice is-gray" role="status">
                <x-heroicon-o-archive-box-x-mark />
                <span><b>{{ $num($s['expired']) }} yêu cầu đã qua ngày mà chưa được duyệt.</b> Chúng không có hiệu lực; vào <a href="{{ $R::getUrl('index', ['activeTab' => 'past']) }}" wire:navigate>Đã kết thúc</a> để từ chối cho gọn.</span>
            </div>
        @endif

        <section class="fs-lv__card" aria-label="Lịch nghỉ 7 ngày tới">
            <header class="fs-lv__card-head"><div><h2>Lịch nghỉ 7 ngày tới</h2><p>Mỗi ô: tài xế còn đi làm / đã đăng ký ca · tô đỏ khi ca thiếu người (từ {{ $overload }} đơn/tài xế/ngày)</p></div></header>
            @if ($impact['shifts']->isEmpty())
                <p class="fs-lv__empty">Khu vực chưa có ca nào đang kích hoạt.</p>
            @else
                <div class="fs-lv__cal">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Ca</th>
                                @foreach ($impact['rows'] as $r)
                                    <th scope="col" @class(['is-today' => $r['date']->isToday()])>
                                        <b>{{ $r['date']->isToday() ? 'Hôm nay' : $days[$r['date']->dayOfWeek].' '.$r['date']->format('d/m') }}</b>
                                        <small @class(['has-leave' => $r['onLeave'] > 0])>{{ $r['onLeave'] ? $r['onLeave'].' người nghỉ' : 'đủ người' }}</small>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($impact['shifts'] as $idx => $shift)
                                <tr>
                                    <td>{{ $shift->name }}<small>{{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }}</small></td>
                                    @foreach ($impact['rows'] as $r)
                                        @php $c = $r['cells'][$idx]; @endphp
                                        <td @class(['is-bad' => $c['bad'], 'is-away' => ! $c['bad'] && $c['away'] > 0]) title="{{ $c['away'] }} người nghỉ{{ $c['perDriver'] !== null ? ' · '.$c['perDriver'].' đơn/tài xế/ngày' : '' }}">
                                            {{ $c['remaining'] }}/{{ $c['registered'] }}
                                            @if ($c['away'] > 0)<small>−{{ $c['away'] }} nghỉ</small>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="fs-lv__legend">
                    <span><i style="background: color-mix(in srgb, #0d9488 20%, transparent)"></i>Có người nghỉ</span>
                    <span><i style="background: color-mix(in srgb, #dc2626 20%, transparent)"></i>Ca thiếu người</span>
                </div>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>
