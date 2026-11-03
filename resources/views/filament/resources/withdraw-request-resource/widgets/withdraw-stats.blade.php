<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/withdraw.css') }}?v={{ filemtime(public_path('css/withdraw.css')) }}">
    @php
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $wait = $s['oldestHours'] >= 48 ? intdiv($s['oldestHours'], 24).' ngày' : ($s['oldestHours'] >= 1 ? $s['oldestHours'].' giờ' : 'dưới 1 giờ');
        $methods = ['payos' => ['PayOS', '#2563eb'], 'manual' => ['Chuyển khoản tay', '#7c3aed'], 'cash' => ['Tiền mặt', '#16a34a'], 'other' => ['Khác', '#94a3b8']];
        $byMethod = collect($methods)->map(fn ($m, $k) => $m + [2 => $s['byMethod'][$k]['money'] ?? 0, 3 => $s['byMethod'][$k]['count'] ?? 0])->filter(fn ($m) => $m[3] > 0);
        $paid = max(1, $s['paidMoney']);
        $short = $s['payos'] && $s['payosBalance'] !== null && $s['payosBalance'] < $s['pendingMoney'];
    @endphp

    <div class="fs-wd">
        <section class="fs-wd__kpis" aria-label="Tổng quan rút tiền">
            <article class="fs-wd__kpi fs-wd__kpi--hero">
                <div class="fs-wd__kpi-head"><span>Đang chờ chuyển cho tài xế</span><x-heroicon-o-banknotes /></div>
                <strong>{{ $money($s['pendingMoney']) }}</strong>
                <small>{{ $s['pendingCount'] ? $num($s['pendingCount']).' yêu cầu · tiền đã được giữ khỏi ví tài xế' : 'không có yêu cầu chờ' }}</small>
            </article>
            <article class="fs-wd__kpi fs-wd__kpi--red">
                <div class="fs-wd__kpi-head"><span>Chờ lâu nhất</span><x-heroicon-o-clock /></div>
                <strong @class(['is-alert' => $s['stale'] > 0])>{{ $s['pendingCount'] ? $wait : '—' }}</strong>
                <small>{{ $s['stale'] ? $num($s['stale']).' yêu cầu quá '.$staleHours.' giờ' : 'chưa có yêu cầu quá '.$staleHours.' giờ' }}</small>
            </article>
            <article class="fs-wd__kpi fs-wd__kpi--green">
                <div class="fs-wd__kpi-head"><span>Đã chi 30 ngày</span><x-heroicon-o-check-badge /></div>
                <strong>{{ $money($s['paidMoney']) }}</strong>
                @if ($byMethod->isNotEmpty())
                    <span class="fs-wd__split" role="img" aria-label="Theo cách trả">
                        @foreach ($byMethod as [$label, $color, $m])<i style="width: {{ round($m / $paid * 100, 1) }}%; background: {{ $color }}" title="{{ $label }}"></i>@endforeach
                    </span>
                    <small class="fs-wd__legend">@foreach ($byMethod as [$label, $color, $m, $c])<span><i style="background: {{ $color }}"></i>{{ $label }} {{ $num($c) }}</span>@endforeach</small>
                @else
                    <small>chưa duyệt yêu cầu nào trong 30 ngày</small>
                @endif
            </article>
            <article class="fs-wd__kpi fs-wd__kpi--gray">
                <div class="fs-wd__kpi-head"><span>Từ chối 30 ngày</span><x-heroicon-o-x-circle /></div>
                <strong>{{ $num($s['rejectedCount']) }}</strong>
                <small>{{ $s['rejectRate'] }}% số yêu cầu đã xử lý · tiền được hoàn vào ví</small>
            </article>
        </section>

        <div class="fs-wd__bar" role="status">
            @if ($s['payos'])
                <span class="is-ok"><x-heroicon-o-bolt /> Chuyển tự động PayOS <b>đã bật</b></span>
                @if ($s['payosBalance'] !== null)
                    <span @class(['is-bad' => $short])>Số dư tài khoản chi <b>{{ $money($s['payosBalance']) }}</b>{{ $short ? ' · không đủ cho các yêu cầu đang chờ' : '' }}</span>
                @else
                    <span class="is-warn">Chưa đọc được số dư tài khoản chi</span>
                @endif
                @if ($s['inFlight'] > 0)
                    <span class="is-warn"><x-heroicon-o-arrow-path /> <b>{{ $num($s['inFlight']) }}</b> lệnh PayOS chưa có kết quả · tự kiểm tra mỗi 5 phút</span>
                @endif
            @else
                <span class="is-warn"><x-heroicon-o-bolt-slash /> Chuyển tự động PayOS <b>chưa cấu hình</b></span>
                <span>Chuyển khoản tay rồi chọn "Duyệt" và nhập mã giao dịch, hoặc chọn "Đã trả tiền mặt".</span>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
