<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Ngân hàng</span><strong>{{ $num($active) }}/{{ $num($total) }}</strong><small>đang hiển thị trong app</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tài khoản đã lưu</span><strong>{{ $num($accounts) }}</strong><small>tài khoản nhận tiền của tài xế</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Cần cập nhật</span><strong @class(['fs-dr-low' => $legacy->count() > 0])>{{ $num($legacy->count()) }}</strong><small>tài khoản lưu mã cũ, chuyển khoản tự động sẽ lỗi</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Khớp được tự động</span><strong>{{ $num($legacy->filter(fn ($l) => $l['match'])->count()) }}</strong><small>sửa bằng lệnh <code>banks:fix-legacy-codes</code></small></article>
    </div>

    @if ($legacy->isNotEmpty())
        <section class="fs-rr-card" style="margin-top:1.25rem">
            <header class="fs-rr-card__head"><div><h2>Tài khoản cần cập nhật</h2><p>Mã ngân hàng lưu dạng chữ thay vì số BIN. Chạy <code>php artisan banks:fix-legacy-codes</code> để xem trước, thêm <code>--apply</code> để sửa.</p></div></header>
            @foreach ($legacy as $l)
                <a class="fs-dr-row" href="{{ $l['url'] }}" wire:navigate>
                    <span>{{ $l['driver'] }} <small>{{ $l['phone'] }}</small></span>
                    <span class="fs-sc-settle">
                        <b class="fs-sc-down">{{ $l['bank_code'] ?: '(trống)' }}</b>
                        <b class="{{ $l['match'] ? 'fs-sc-up' : '' }}">{{ $l['match'] ? '→ '.$l['match'] : 'không khớp, tài xế cần tự cập nhật' }}</b>
                    </span>
                </a>
            @endforeach
        </section>
    @endif
</x-filament-widgets::widget>
