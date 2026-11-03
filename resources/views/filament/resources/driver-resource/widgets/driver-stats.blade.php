<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/driver-page.css') }}?v={{ filemtime(public_path('css/driver-page.css')) }}">
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $pct = fn ($part) => $active > 0 ? min(100, round($part / $active * 100)) : 0;
        $docsPending = $cccdPending + $licensePending;
    @endphp

    <div class="fs-drv">
        <section class="fs-drv__kpis" aria-label="Tổng quan tài xế">
            <a class="fs-drv__kpi fs-drv__kpi--hero" href="{{ $tabUrl('active') }}" wire:navigate>
                <div class="fs-drv__kpi-head"><span>Tài xế đang hoạt động</span><x-heroicon-o-truck /></div>
                <strong>{{ $num($active) }}<em>/ {{ $num($total) }} tài xế</em></strong>
                <span class="fs-drv__meter" role="img" aria-label="{{ $num($online) }} đang online">
                    <i class="is-online" style="width: {{ $pct($online) }}%"></i>
                </span>
                <small>{{ $num($online) }} đang online · {{ $num($working) }} người có đơn trong 30 ngày</small>
            </a>
            <a class="fs-drv__kpi fs-drv__kpi--amber" href="{{ $tabUrl('pending') }}" wire:navigate>
                <div class="fs-drv__kpi-head"><span>Chờ duyệt tài khoản</span><x-heroicon-o-clock /></div>
                <strong @class(['is-alert' => $stale > 0])>{{ $num($pending) }}</strong>
                <small>{{ $pending ? 'lâu nhất '.$num($oldestPending).' ngày'.($stale ? ' · '.$num($stale).' hồ sơ quá '.$staleDays.' ngày' : '') : 'không có hồ sơ chờ' }}</small>
            </a>
            <article class="fs-drv__kpi fs-drv__kpi--blue">
                <div class="fs-drv__kpi-head"><span>Giấy tờ chờ xét duyệt</span><x-heroicon-o-identification /></div>
                <strong>{{ $num($docsPending) }}</strong>
                <small>{{ $num($cccdPending) }} CCCD · {{ $num($licensePending) }} bằng lái (bản mới nhất)</small>
            </article>
            <a class="fs-drv__kpi fs-drv__kpi--red" href="{{ $tabUrl('locked') }}" wire:navigate>
                <div class="fs-drv__kpi-head"><span>Bị khóa</span><x-heroicon-o-lock-closed /></div>
                <strong>{{ $num($locked) }}</strong>
                <small>{{ $workingLocked ? $num($workingLocked).' người từng chạy đơn trong 30 ngày' : 'không ai trong số này chạy đơn 30 ngày qua' }}</small>
            </a>
        </section>

        @if ($stale > 0 || $docsPending > 0)
            <div class="fs-drv__notice" role="status">
                <x-heroicon-o-exclamation-triangle />
                <span>
                    @if ($stale > 0)<b>{{ $num($stale) }} hồ sơ chờ duyệt quá {{ $staleDays }} ngày.</b>@endif
                    {{ $docsPending > 0 ? 'Có '.$num($docsPending).' giấy tờ chờ xét duyệt.' : '' }}
                    Duyệt tài khoản được cả khi giấy tờ chưa đủ, nhưng tài xế chỉ bật online nhận đơn được khi CCCD đã được duyệt.
                </span>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
