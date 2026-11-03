<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/shift.css') }}?v={{ filemtime(public_path('css/shift.css')) }}">
    @php
        $num = fn ($v) => number_format((float) $v, 0, ',', '.');
        $dec = fn ($v) => number_format((float) $v, 1, ',', '.');
        $cov = $s['coverage'];
        $cur = $s['current'];
        $busy = $s['busiest'];
        $without = $s['noShift'] + $s['idle'];
        $pos = fn (int $m) => round($m / 14.4, 2);
    @endphp

    <div class="fs-shift">
        <section class="fs-shift__kpis" aria-label="Tổng quan ca làm việc">
            <article class="fs-shift__kpi fs-shift__kpi--hero">
                <div class="fs-shift__kpi-head"><span>Ca đang chạy</span><x-heroicon-o-clock /></div>
                @if ($cur->isNotEmpty())
                    <strong>{{ $cur->map(fn ($r) => $r['shift']->name)->join(' · ') }}</strong>
                    <small>{{ $num($cur->sum('online')) }} tài xế online / {{ $num($cur->sum('active')) }} đăng ký{{ $cur->count() > 1 ? ' (các ca chồng giờ)' : '' }}</small>
                @else
                    <strong>Ngoài giờ ca</strong>
                    <small>chưa có ca nào đang diễn ra</small>
                @endif
            </article>
            <article class="fs-shift__kpi fs-shift__kpi--blue">
                <div class="fs-shift__kpi-head"><span>Tài xế hoạt động</span><x-heroicon-o-users /></div>
                <strong>{{ $num($s['activeDrivers']) }}</strong>
                <small>
                    @if ($without)<b class="is-alert">{{ $num($without) }} người chưa có ca đang bật</b>@else tất cả đã có ca @endif
                </small>
            </article>
            <article class="fs-shift__kpi fs-shift__kpi--amber">
                <div class="fs-shift__kpi-head"><span>Ca tải cao nhất</span><x-heroicon-o-fire /></div>
                <strong @class(['is-alert' => $busy && $busy['overloaded']])>{{ $busy ? $dec($busy['perDriver']) : '—' }}<em>đơn/tài xế/ngày</em></strong>
                <small>{{ $busy ? $busy['shift']->name.($busy['overloaded'] ? ' · quá tải (từ '.$overload.')' : '') : 'chưa có dữ liệu' }}</small>
            </article>
            <article class="fs-shift__kpi fs-shift__kpi--violet">
                <div class="fs-shift__kpi-head"><span>Yêu cầu đổi ca</span><x-heroicon-o-arrows-right-left /></div>
                <strong @class(['is-alert' => $s['pendingChanges'] > 0])>{{ $num($s['pendingChanges']) }}</strong>
                <small><a href="{{ $changesUrl }}" wire:navigate>{{ $s['pendingChanges'] ? 'đang chờ duyệt, xem ngay' : 'không có yêu cầu chờ' }}</a></small>
            </article>
        </section>

        <section class="fs-shift__card" aria-label="Phủ ca trong ngày">
            <header class="fs-shift__card-head">
                <div>
                    <h2>Phủ ca trong ngày</h2>
                    <p>Đơn tính theo giờ đặt, trung bình {{ $cov['days'] }} ngày qua · tô đỏ khi từ {{ $overload }} đơn/tài xế/ngày</p>
                </div>
            </header>

            @if ($cov['rows']->isEmpty())
                <p class="fs-shift__empty">Khu vực chưa có ca nào.</p>
            @else
                <div class="fs-shift__tl">
                    <div class="fs-shift__tl-axis" aria-hidden="true">
                        <span></span>
                        <div class="fs-shift__tl-scale">
                            @foreach ([0, 3, 6, 9, 12, 15, 18, 21, 24] as $h)<em style="left: {{ $h / 24 * 100 }}%">{{ sprintf('%02d', $h) }}</em>@endforeach
                        </div>
                        <span></span>
                    </div>

                    @foreach ($cov['rows'] as $r)
                        @php $sh = $r['shift']; @endphp
                        <a class="fs-shift__tl-row{{ $sh->is_active ? '' : ' is-off' }}" href="{{ \App\Filament\Resources\ShiftResource::getUrl('view', ['record' => $sh]) }}" wire:navigate>
                            <span class="fs-shift__tl-name">
                                <b>{{ $sh->name }}</b>
                                <small>{{ substr($sh->start_time, 0, 5) }}–{{ substr($sh->end_time, 0, 5) }}{{ $sh->is_active ? '' : ' · đã tắt' }}{{ $r['now'] ? ' · đang chạy' : '' }}</small>
                            </span>
                            <span class="fs-shift__tl-track">
                                @foreach (\App\Services\ShiftService::segments($sh->start_time, $sh->end_time) as [$a, $b])
                                    <i @class(['is-now' => $r['now'], 'is-hot' => $r['overloaded']]) style="left: {{ $pos($a) }}%; width: {{ $pos($b - $a) }}%"></i>
                                @endforeach
                                <u style="left: {{ $pos($nowMinute) }}%"></u>
                            </span>
                            <span class="fs-shift__tl-stat">
                                <b>{{ $num($r['active']) }} tài xế{{ $r['locked'] ? ' (+'.$num($r['locked']).' khóa)' : '' }}</b>
                                <small @class(['is-alert' => $r['overloaded']])>{{ $dec($r['perDay']) }} đơn/ngày{{ $r['perDriver'] !== null ? ' · '.$dec($r['perDriver']).'/tài xế' : '' }}</small>
                            </span>
                        </a>
                    @endforeach

                    @if ($cov['gaps']->isNotEmpty())
                        <div class="fs-shift__tl-row is-gap">
                            <span class="fs-shift__tl-name"><b>Không có ca</b><small>khung giờ chưa ai phục vụ</small></span>
                            <span class="fs-shift__tl-track">
                                @foreach ($cov['gaps'] as $g)
                                    @php [$gh, $gm] = array_map('intval', explode(':', $g['from'])); [$th, $tm] = array_map('intval', explode(':', $g['to'])); $a = $gh * 60 + $gm; $b = $th * 60 + $tm; $b = $b <= $a ? 1440 : $b; @endphp
                                    <i style="left: {{ $pos($a) }}%; width: {{ $pos($b - $a) }}%"></i>
                                @endforeach
                                <u style="left: {{ $pos($nowMinute) }}%"></u>
                            </span>
                            <span class="fs-shift__tl-stat">
                                <b>{{ $num($cov['gaps']->sum('orders')) }} đơn</b>
                                <small>{{ $cov['gaps']->map(fn ($g) => $g['from'].'–'.$g['to'])->join(', ') }}</small>
                            </span>
                        </div>
                    @endif
                </div>
            @endif
        </section>

        @if ($unassigned->isNotEmpty())
            <section class="fs-shift__card" aria-label="Tài xế chưa có ca đang bật">
                <header class="fs-shift__card-head">
                    <div>
                        <h2>Tài xế chưa có ca đang bật <span class="fs-shift__count">{{ $num($without) }}</span></h2>
                        <p>Không bị tính Offline và không bị phạt, nhưng cũng không nằm trong lịch phục vụ. Xếp ca cho họ ở hồ sơ tài xế{{ $without > $unassigned->count() ? ' · đang hiện '.$unassigned->count().' người' : '' }}.</p>
                    </div>
                </header>
                <div class="fs-shift__people">
                    @foreach ($unassigned as $d)
                        <a href="{{ $d['url'] }}" wire:navigate>
                            <b>{{ $d['name'] }}</b>
                            <small>{{ $d['phone'] }} · {{ $d['had_shift'] ? 'ca cũ đã tắt' : 'chưa đăng ký ca' }}</small>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
