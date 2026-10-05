<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tổng cửa hàng</span>
            <strong>{{ $num($total) }}</strong>
            <small>{{ $num($locked) }} tài khoản bị khóa</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đang hoạt động</span>
            <strong>{{ $num($active) }} <em>{{ $activeRate }}%</em></strong>
            <small>có đơn trong {{ $activeDays }} ngày qua</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Ngủ đông / chưa đặt</span>
            <strong>{{ $num($dormant) }} <em>/ {{ $num($never) }}</em></strong>
            <small>từng đặt rồi im · chưa đặt đơn nào</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Mức tập trung</span>
            <strong>{{ $topShare }}%</strong>
            <small>đơn app đến từ 2 shop đầu ({{ $num($orders) }} đơn)</small>
        </article>
    </div>

    <div class="fs-rr-notice fs-cu-notice" role="status">
        <x-heroicon-o-information-circle class="h-5 w-5" />
        <span>Chỉ <b>{{ $shopAppShare }}%</b> đơn của khu vực trong 30 ngày qua đến từ app cửa hàng, còn lại chủ yếu qua tổng đài. Cột "qua tổng đài" ở bảng là các đơn tổng đài có ghi số điện thoại lấy hàng trùng số của shop.</span>
    </div>

    @if ($leads->isNotEmpty())
        <section class="fs-rr-card fs-sh-leads">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Số hay gọi tổng đài nhưng chưa đăng ký</h2>
                    <p>Từ {{ $leadMin }} đơn tổng đài trở lên có cùng số lấy hàng. Gợi ý mời vào app (có thể là cửa hàng, cũng có thể là khách quen, cần kiểm tra).</p>
                </div>
            </header>
            <div class="fs-sh-leads__list">
                @foreach ($leads as $lead)
                    <div class="fs-sh-lead">
                        <b>{{ $lead['phone'] }}</b>
                        <span>{{ $num($lead['orders']) }} đơn · gần nhất {{ $lead['last']->format('d/m/Y') }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-filament-widgets::widget>
