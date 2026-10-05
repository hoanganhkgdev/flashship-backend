<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Dịch vụ hiển thị</span><strong>{{ $num($active) }}/{{ $num($total) }}</strong><small>đang hiện trong app</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn 30 ngày</span><strong>{{ $num($orders['total']) }}</strong><small>{{ $top ? $top->label.' nhiều nhất ('.($orders['total'] ? round(($orders['by'][$top->key] ?? 0) / $orders['total'] * 100) : 0).'%)' : '—' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Thiếu giá</span><strong @class(['fs-dr-low' => $gaps > 0])>{{ $num($gaps) }}</strong><small>{{ $gaps ? 'ô dịch vụ × khu vực chưa có giá' : 'đủ giá mọi khu vực' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Thiếu icon</span><strong @class(['fs-dr-low' => $noIcon > 0])>{{ $num($noIcon) }}</strong><small>dịch vụ chưa có hình ảnh</small></article>
    </div>

    <section class="fs-rr-card" style="margin-top:1.25rem">
        <header class="fs-rr-card__head"><div><h2>Độ phủ giá</h2><p>Dịch vụ × khu vực đang phục vụ khách thật (không tính khu vực thử)</p></div></header>
        @foreach ($services as $s)
            <div class="fs-dr-row" style="{{ $s->is_active ? '' : 'opacity:.55' }}">
                <span>{{ $s->label }}{{ $s->is_active ? '' : ' · đã ẩn' }}</span>
                <span class="fs-sc-settle">
                    @foreach ($cities as $c)
                        @php $ok = $matrix[$c->id][$s->key] ?? false; @endphp
                        <b class="{{ $ok ? 'fs-sc-up' : 'fs-sc-down' }}">{{ $c->name }} {{ $ok ? '✓' : '✗' }}</b>
                    @endforeach
                </span>
            </div>
        @endforeach
    </section>
</x-filament-widgets::widget>
