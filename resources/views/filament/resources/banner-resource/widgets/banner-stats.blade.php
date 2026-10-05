<x-filament-widgets::widget>
    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đang hiển thị</span>
            <strong>{{ $live }} <em>/ {{ $total }}</em></strong>
            <small>{{ $live === 0 ? 'App đang dùng ảnh mặc định' : 'banner khách đang thấy' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Hẹn giờ</span>
            <strong>{{ $scheduled }}</strong>
            <small>chờ tới giờ bắt đầu</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Hết hạn</span>
            <strong>{{ $expired }}</strong>
            <small>đã qua ngày kết thúc</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Đã ẩn</span>
            <strong>{{ $inactive }}</strong>
            <small>đang tắt thủ công</small>
        </article>
    </div>

    @if ($missing > 0)
        <div class="fs-rr-notice fs-cu-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>{{ $missing }} banner bị mất file ảnh</b> ({{ implode(', ', $missingTitles) }}). App sẽ hiện ảnh mặc định thay cho banner này. Hãy tải ảnh lên lại.</span>
        </div>
    @endif
</x-filament-widgets::widget>
