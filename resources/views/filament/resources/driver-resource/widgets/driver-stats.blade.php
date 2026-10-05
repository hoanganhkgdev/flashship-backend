<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tổng tài xế</span>
            <strong>{{ $num($total) }}</strong>
            <small>{{ $num($active) }} hoạt động · {{ $num($locked) }} bị khóa</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Có đơn trong 30 ngày</span>
            <strong>{{ $num($working) }}</strong>
            <small>{{ $workingLocked ? $num($workingLocked).' người trong số đó đang bị khóa' : 'đang chạy đơn thực tế' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ duyệt</span>
            <strong>{{ $num($pending) }}</strong>
            <small>{{ $pending ? 'lâu nhất '.$num($oldestPending).' ngày' : 'không có hồ sơ chờ' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Điểm thấp</span>
            <strong>{{ $num($lowScore) }}</strong>
            <small>đang hoạt động, dưới {{ $lowThreshold }} điểm</small>
        </article>
    </div>

    @if ($stale > 0 || $cccdPending > 0 || $licensePending > 0)
        <div class="fs-rr-notice fs-cu-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                @if ($stale > 0)<b>{{ $num($stale) }} tài xế chờ duyệt trên {{ $staleDays }} ngày.</b> @endif
                @if ($cccdPending > 0 || $licensePending > 0)
                    Giấy tờ đang chờ xét duyệt: {{ $num($cccdPending) }} CCCD, {{ $num($licensePending) }} bằng lái.
                @endif
                Tài xế chưa có CCCD được duyệt sẽ không duyệt được tài khoản.
            </span>
        </div>
    @endif
</x-filament-widgets::widget>
