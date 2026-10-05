<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tổng khách hàng</span>
            <strong>{{ $num($total) }}</strong>
            <small>{{ $num($locked) }} tài khoản bị khóa</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Khách mới</span>
            <strong>{{ $num($newRecent) }}</strong>
            <small>trong {{ $newDays }} ngày · {{ $num($newMonth) }} trong 30 ngày</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đã đặt đơn</span>
            <strong>{{ $num($ordered) }} <em>{{ $orderedRate }}%</em></strong>
            <small>{{ $num($total - $ordered) }} người chưa đặt đơn nào</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Khách quay lại</span>
            <strong>{{ $num($returning) }} <em>{{ $returningRate }}%</em></strong>
            <small>từ 2 đơn hoàn thành · {{ $num($loyal) }} khách quen (từ {{ $loyalMin }} đơn)</small>
        </article>
    </div>

    @if ($callCenterNoPhoneRate >= 50)
        <div class="fs-rr-notice fs-cu-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                <b>{{ $callCenterNoPhoneRate }}% đơn tổng đài (30 ngày qua) không ghi số điện thoại khách</b>
                ({{ $num($callCenterNoPhone) }}/{{ $num($callCenterTotal) }} đơn) nên không gắn được với khách hàng nào và không có trong danh sách này.
                Muốn theo dõi khách quen của tổng đài, cần bắt buộc nhập số điện thoại khi tạo đơn.
            </span>
        </div>
    @endif
</x-filament-widgets::widget>
