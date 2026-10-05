<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tài khoản quản trị</span><strong>{{ $num($s['total']) }}</strong><small>quản trị, quản lý khu vực, tổng đài</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Quản trị viên đầy đủ</span><strong>{{ $num($s['admins']) }}</strong><small>toàn quyền, đang hoạt động</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đã khóa</span><strong>{{ $num($s['locked']) }}</strong><small>không vào được trang quản trị</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Chưa ghi nhận đăng nhập</span><strong>{{ $num($s['neverLogin']) }}</strong><small>tính từ khi bật ghi nhận đăng nhập</small></article>
    </div>

    <div class="fs-cp-grid fs-sc-panels">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Ai được làm gì</h2><p>Phạm vi của từng vai trò</p></div></header>
            @foreach ($roles as $key => $desc)
                <div class="fs-dr-row"><span><b>{{ $roleNames[$key] }}</b></span><span style="text-align:right;max-width:34rem;font-size:var(--fs-sm)">{{ $desc }}</span></div>
            @endforeach
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Thay đổi gần đây</h2><p>Tạo, sửa, khóa, đặt lại mật khẩu, xóa</p></div></header>
            @forelse ($logs as $l)
                <div class="fs-cp-addr"><b>{{ $l['label'] }} · {{ $l['target'] }}</b><span>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}{{ $l['detail'] ? ' · '.$l['detail'] : '' }}</span></div>
            @empty
                <p class="fs-rr-note">Chưa có thay đổi nào được ghi lại.</p>
            @endforelse
        </section>
    </div>
</x-filament-widgets::widget>
