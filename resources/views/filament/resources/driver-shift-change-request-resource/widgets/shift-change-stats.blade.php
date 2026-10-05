<x-filament-widgets::widget>
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $wait = $s['oldestHours'] >= 48 ? intdiv($s['oldestHours'], 24).' ngày' : $s['oldestHours'].' giờ';
    @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ duyệt</span>
            <strong>{{ $num($s['pending']) }}</strong>
            <small>{{ $s['locked'] ? $num($s['locked']).' của tài xế đã khóa' : 'yêu cầu đang chờ' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ lâu nhất</span>
            <strong @class(['fs-dr-low' => $s['stale'] > 0])>{{ $s['pending'] ? $wait : '—' }}</strong>
            <small>{{ $s['stale'] ? $num($s['stale']).' yêu cầu quá '.$staleHours.' giờ' : 'chưa có yêu cầu quá hạn' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Duyệt được ngay</span>
            <strong>{{ $num($s['approvableNow']) }}</strong>
            <small>trên {{ $num($s['pending']) }} yêu cầu đang chờ</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tỷ lệ duyệt 30 ngày</span>
            <strong>{{ $s['approveRate'] !== null ? $s['approveRate'].'%' : '—' }}</strong>
            <small>{{ $num($s['processed']) }} yêu cầu đã xử lý</small>
        </article>
    </div>
</x-filament-widgets::widget>
