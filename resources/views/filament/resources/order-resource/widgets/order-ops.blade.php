<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn hôm nay</span><strong>{{ $num($total) }}</strong><small>{{ $num($active) }} đang chạy · {{ $num($pending) }} chờ tài xế</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Chờ lâu nhất</span><strong @class(['fs-dr-low' => $pending && $oldest >= $timeout])>{{ $pending ? $oldest.' phút' : '—' }}</strong><small>{{ $pending ? ($oldest >= $timeout ? 'đã quá '.$timeout.' phút tìm tài xế' : 'giới hạn tìm tài xế '.$timeout.' phút') : 'không có đơn chờ' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tỷ lệ hủy hôm nay</span><strong @class(['fs-dr-low' => $cancelRate >= 25])>{{ $cancelRate }}%</strong><small>{{ $num($cancelled) }} đơn hủy · {{ $num($quick) }} hủy trong 3 phút</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn nghi trùng hôm nay</span><strong @class(['fs-dr-low' => $dupes > 0])>{{ $num($dupes) }}</strong><small>cùng SĐT và địa chỉ lấy, cách nhau dưới 10 phút</small></article>
    </div>
</x-filament-widgets::widget>
