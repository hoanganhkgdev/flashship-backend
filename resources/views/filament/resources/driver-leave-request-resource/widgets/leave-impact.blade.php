<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Nghỉ hôm nay</span><strong>{{ $num($s['today']) }}</strong><small>tài xế không nhận đơn hôm nay</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Nghỉ ngày mai</span><strong>{{ $num($s['tomorrow']) }}</strong><small>đã báo trước</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Nghỉ 7 ngày tới</span><strong>{{ $num($s['week']) }}</strong><small>lượt nghỉ, gồm cả hôm nay</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Nghỉ nhiều</span><strong @class(['fs-dr-low' => $s['frequent'] > 0])>{{ $num($s['frequent']) }}</strong><small>tài xế nghỉ từ {{ $frequentAt }} lần trong 30 ngày</small></article>
    </div>

    <section class="fs-rr-card" style="margin-top:1.25rem">
        <header class="fs-rr-card__head"><div><h2>Lịch nghỉ 7 ngày tới</h2><p>Số tài xế hoạt động còn lại / đã đăng ký của từng ca · tô đỏ khi từ {{ $overload }} đơn/tài xế/ngày</p></div></header>
        @if ($impact['shifts']->isEmpty())
            <div class="fs-empty"><div>Khu vực chưa có ca nào đang kích hoạt.</div></div>
        @else
            @foreach ($impact['rows'] as $r)
                <div class="fs-dr-row">
                    <span><b>{{ $r['date']->isToday() ? 'Hôm nay' : ($r['date']->isTomorrow() ? 'Ngày mai' : $r['date']->format('d/m')) }}</b> <small>{{ $r['onLeave'] }} người nghỉ</small></span>
                    <span class="fs-sc-settle">
                        @foreach ($r['cells'] as $c)
                            <b class="{{ $c['bad'] ? 'fs-sc-down' : '' }}">{{ $c['shift']->name }} {{ $c['remaining'] }}/{{ $c['registered'] }}</b>
                        @endforeach
                    </span>
                </div>
            @endforeach
        @endif
    </section>
</x-filament-widgets::widget>
