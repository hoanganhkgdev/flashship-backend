<x-filament-widgets::widget>
    <div class="fs-dashboard-hero">
        <div>
            <p class="fs-eyebrow">Trung tâm điều hành</p>
            <h1 class="fs-dashboard-title">Chào buổi {{ now()->hour < 12 ? 'sáng' : (now()->hour < 18 ? 'chiều' : 'tối') }}!</h1>
            <p class="fs-dashboard-subtitle">
                {{ $cityName }} · {{ now()->translatedFormat('l, d/m/Y') }} · Dữ liệu tự động cập nhật mỗi 15 giây
            </p>
        </div>

        <div class="fs-quick-actions">
            <a class="fs-action fs-action--primary" href="{{ \App\Filament\Resources\OrderResource::getUrl() }}" wire:navigate>
                <x-heroicon-o-shopping-bag class="h-4 w-4" />
                Xem đơn hàng
            </a>
            <a class="fs-action" href="{{ \App\Filament\Pages\DispatchMonitorPage::getUrl() }}" wire:navigate>
                <x-heroicon-o-signal class="h-4 w-4" />
                Theo dõi phát đơn
            </a>
            <a class="fs-action" href="{{ \App\Filament\Pages\DriverMapPage::getUrl() }}" wire:navigate>
                <x-heroicon-o-map-pin class="h-4 w-4" />
                Bản đồ tài xế
            </a>
        </div>
    </div>

    <div class="fs-stats-grid">
        <article class="fs-stat-card">
            <div class="fs-stat-top">
                <div>
                    <p class="fs-stat-label">Tổng đơn hôm nay</p>
                    <p class="fs-stat-value">{{ number_format($totalOrders) }}</p>
                </div>
                <span class="fs-stat-icon bg-orange-50 text-orange-600 dark:bg-orange-950/40"><x-heroicon-o-archive-box class="h-5 w-5" /></span>
            </div>
            <p class="fs-stat-meta">{{ $pendingOrders }} đơn đang chờ hoặc xử lý</p>
        </article>

        <article class="fs-stat-card">
            <div class="fs-stat-top">
                <div>
                    <p class="fs-stat-label">Đơn hoàn thành</p>
                    <p class="fs-stat-value">{{ number_format($completedOrders) }}</p>
                </div>
                <span class="fs-stat-icon bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"><x-heroicon-o-check-circle class="h-5 w-5" /></span>
            </div>
            <p class="fs-stat-meta">Tỷ lệ hoàn thành {{ $completionRate }}% · {{ $cancelledOrders }} đơn đã huỷ</p>
        </article>

        <article class="fs-stat-card">
            <div class="fs-stat-top">
                <div>
                    <p class="fs-stat-label">Tài xế sẵn sàng</p>
                    <p class="fs-stat-value">{{ number_format($driversOnline) }}</p>
                </div>
                <span class="fs-status-pill"><span class="fs-status-dot"></span>Online</span>
            </div>
            <p class="fs-stat-meta">Tài xế online trong khu vực hiện tại</p>
        </article>

        <article class="fs-stat-card">
            <div class="fs-stat-top">
                <div>
                    <p class="fs-stat-label">Doanh thu hôm nay</p>
                    <p class="fs-stat-value">{{ $revenue }}</p>
                </div>
                <span class="fs-stat-icon bg-sky-50 text-sky-600 dark:bg-sky-950/40"><x-heroicon-o-banknotes class="h-5 w-5" /></span>
            </div>
            <p class="fs-stat-meta">Phí giao hàng từ các đơn hoàn thành</p>
        </article>
    </div>
</x-filament-widgets::widget>
