<x-filament-widgets::widget>
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $wait = $s['oldestHours'] >= 48 ? intdiv($s['oldestHours'], 24).' ngày' : $s['oldestHours'].' giờ';
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ duyệt</span>
            <strong>{{ $money($s['pendingMoney']) }}</strong>
            <small>{{ $s['pendingCount'] ? $num($s['pendingCount']).' yêu cầu' : 'không có yêu cầu chờ' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chờ lâu nhất</span>
            <strong @class(['fs-dr-low' => $s['stale'] > 0])>{{ $s['pendingCount'] ? $wait : '—' }}</strong>
            <small>{{ $s['stale'] ? $num($s['stale']).' yêu cầu quá '.$staleHours.' giờ' : 'chưa có yêu cầu quá hạn' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đã chi 30 ngày</span>
            <strong>{{ $money($s['paidMoney']) }}</strong>
            <small>{{ $num($s['paidCount']) }} yêu cầu được duyệt</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Từ chối 30 ngày</span>
            <strong>{{ $num($s['rejectedCount']) }}</strong>
            <small>{{ $s['rejectRate'] }}% số yêu cầu đã xử lý</small>
        </article>
    </div>

    <p class="fs-rr-note" style="margin-top:.75rem">
        @if ($s['payos'])
            Chuyển tự động PayOS: <b>đã cấu hình</b>{{ $s['payosBalance'] !== null ? ' · số dư '.$money($s['payosBalance']) : '' }}.
        @else
            Chuyển tự động PayOS: <b>chưa cấu hình</b> — hãy chuyển khoản thủ công rồi chọn "Duyệt" và nhập mã giao dịch.
        @endif
    </p>
</x-filament-widgets::widget>
