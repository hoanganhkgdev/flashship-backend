<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-cities', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $o = $this->getOverview();
        $h = $o['h'];
        $c = $record;
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
    @endphp

    @if ($h['flags'])
        <div class="fs-rr-legend">@foreach ($h['flags'] as $f)<span class="fs-order-pill fs-order-pill--{{ $f['level'] }}">{{ $f['label'] }}</span>@endforeach</div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn 30 ngày</span><strong>{{ $num($h['orders30']) }}</strong><small>{{ $num($h['ordersToday']) }} hôm nay · {{ $num($h['ordersTotal']) }} tổng</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tài xế hoạt động</span><strong>{{ $num($h['drivers']) }}</strong><small>{{ $num($h['online']) }} đang online</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Khách / cửa hàng</span><strong>{{ $num($h['customers']) }} / {{ $num($h['shops']) }}</strong><small>tài khoản thuộc khu vực</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Phí tuần</span><strong>{{ $num($c->weekly_fee) }}₫</strong><small>công nợ tạo mỗi sáng thứ Hai</small></article>
    </div>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Giá theo dịch vụ</h2><p>{{ $h['priced'] }}/{{ $h['services'] }} dịch vụ đang hiển thị có bảng giá hoạt động</p></div></header>
            @foreach ($o['pricing'] as $p)
                <div class="fs-dr-row"><span>{{ $p['label'] }}</span><span class="fs-order-pill fs-order-pill--{{ $p['ok'] ? 'success' : 'danger' }}">{{ $p['ok'] ? 'Có giá' : 'Chưa có giá' }}</span></div>
            @endforeach

            <header class="fs-rr-card__head" style="margin-top:1.25rem"><div><h2>Ca làm việc</h2><p>{{ $h['activeShifts'] }}/{{ $h['shifts'] }} ca đang bật · <a href="{{ $o['shiftsUrl'] }}" wire:navigate>Mở trang ca</a></p></div></header>
            @forelse ($o['shifts'] as $s)
                <div class="fs-dr-row"><span>{{ $s->name }} <small>{{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }}</small></span><span class="fs-order-pill fs-order-pill--{{ $s->is_active ? 'success' : 'gray' }}">{{ $s->is_active ? 'Đang bật' : 'Đã tắt' }}</span></div>
            @empty
                <p class="fs-rr-note">Khu vực chưa có ca nào.</p>
            @endforelse
        </section>

        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Thông tin</h2></div></header>
                <div class="fs-dr-row"><span>Slug</span><b>{{ $c->slug ?: '—' }}</b></div>
                <div class="fs-dr-row"><span>Tọa độ</span><b>{{ $c->lat !== null ? $c->lat.', '.$c->lng : 'Chưa có' }}</b></div>
                <div class="fs-dr-row"><span>Hiển thị trong app</span><b>{{ $c->is_active && ! $c->is_test ? 'Có' : 'Không' }}</b></div>
                <div class="fs-dr-row"><span>Chế độ mưa</span><b>{{ $c->is_rain_mode ? 'Đang bật' : 'Tắt' }}</b></div>
            </section>

            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Nhật ký</h2><p>Tạo, sửa, bật/tắt khu vực</p></div></header>
                @forelse ($o['logs'] as $l)
                    <div class="fs-cp-addr"><b>{{ $l['label'] }}</b><span>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}{{ $l['detail'] ? ' · '.$l['detail'] : '' }}</span></div>
                @empty
                    <p class="fs-rr-note">Chưa có thay đổi nào được ghi lại.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-filament-panels::page>
