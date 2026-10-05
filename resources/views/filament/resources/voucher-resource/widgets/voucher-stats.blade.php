<x-filament-widgets::widget>
    @php
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Mã đang chạy</span>
            <strong>{{ $num($active) }} <em>/ {{ $num($total) }}</em></strong>
            <small>còn hiệu lực, chưa hết lượt</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Lượt dùng 30 ngày</span>
            <strong>{{ $num($usage) }}</strong>
            <small>{{ $orderShare }}% đơn hoàn thành có dùng mã</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chi phí giảm giá 30 ngày</span>
            <strong>{{ $money($cost) }}</strong>
            <small>trên {{ $num($voucherOrders) }} đơn hoàn thành</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Cần chú ý</span>
            <strong>{{ $num($attention->count()) }}</strong>
            <small>hết hạn trong {{ $expiringDays }} ngày hoặc đã dùng ≥ {{ $nearPct }}% lượt</small>
        </article>
    </div>

    @if ($attention->isNotEmpty())
        <div class="fs-rr-notice fs-cu-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                @foreach ($attention as $v)
                    <b>{{ $v->code }}</b>
                    ({{ $v->usage_limit && $v->used_count >= $v->usage_limit * 0.8 ? 'đã dùng '.$num($v->used_count).'/'.$num($v->usage_limit).' lượt' : 'hết hạn '.$v->expires_at->format('d/m/Y') }}){{ ! $loop->last ? ' · ' : '' }}
                @endforeach
            </span>
        </div>
    @endif
</x-filament-widgets::widget>
