<x-filament-widgets::widget>
    <div class="fs-ops-head">
        <div class="fs-ops-head__title">
            <span class="fs-ops-head__live"><i></i>Đang hoạt động</span>
            <div>
                <h1>Tổng quan vận hành</h1>
                <p>{{ $cityName }} · {{ now()->translatedFormat('l, d/m/Y') }} · Cập nhật {{ now()->format('H:i:s') }}</p>
            </div>
        </div>
        <div class="fs-quick-actions">
            @if (\App\Filament\Pages\CallCenterPage::canAccess())
                <a class="fs-action fs-action--primary" href="{{ \App\Filament\Pages\CallCenterPage::getUrl() }}" wire:navigate>
                    <x-heroicon-o-plus class="h-4 w-4" />Tạo đơn
                </a>
            @endif
            <a class="fs-action" href="{{ \App\Filament\Resources\OrderResource::getUrl() }}" wire:navigate>
                <x-heroicon-o-rectangle-stack class="h-4 w-4" />Danh sách đơn
            </a>
            <a class="fs-action" href="{{ \App\Filament\Pages\DriverMapPage::getUrl() }}" wire:navigate>
                <x-heroicon-o-map-pin class="h-4 w-4" />Bản đồ
            </a>
        </div>
    </div>

    <div class="fs-ops-kpis">
        <a class="fs-ops-kpi tone-orange" href="{{ \App\Filament\Resources\OrderResource::getUrl() }}" wire:navigate>
            <span class="fs-ops-kpi__icon"><x-heroicon-o-archive-box /></span>
            <span class="fs-ops-kpi__label">Đơn hôm nay</span>
            <strong>{{ number_format($totalOrders, 0, ',', '.') }}</strong>
            <span class="fs-ops-kpi__meta">{{ $trends['total']['text'] }} so với cùng giờ hôm qua</span>
        </a>

        <a class="fs-ops-kpi {{ $waitingOrders > 0 ? 'tone-amber' : 'tone-green' }}" href="{{ \App\Filament\Resources\OrderResource::getUrl('index', ['activeTab' => 'new']) }}" wire:navigate>
            <span class="fs-ops-kpi__icon"><x-heroicon-o-clock /></span>
            <span class="fs-ops-kpi__label">Đang chờ tài xế</span>
            <strong>{{ number_format($waitingOrders, 0, ',', '.') }}</strong>
            <span class="fs-ops-kpi__meta">Trong {{ number_format($pendingOrders, 0, ',', '.') }} đơn đang hoạt động</span>
        </a>

        <a class="fs-ops-kpi tone-blue" href="{{ \App\Filament\Resources\OrderResource::getUrl('index', ['activeTab' => 'processing']) }}" wire:navigate>
            <span class="fs-ops-kpi__icon"><x-heroicon-o-truck /></span>
            <span class="fs-ops-kpi__label">Đang thực hiện</span>
            <strong>{{ number_format($assignedOrders + $processingOrders, 0, ',', '.') }}</strong>
            <span class="fs-ops-kpi__meta">{{ $assignedOrders }} đã nhận · {{ $processingOrders }} đang giao</span>
        </a>

        <a class="fs-ops-kpi tone-green" href="{{ \App\Filament\Resources\OrderResource::getUrl('index', ['activeTab' => 'completed']) }}" wire:navigate>
            <span class="fs-ops-kpi__icon"><x-heroicon-o-check-circle /></span>
            <span class="fs-ops-kpi__label">Hoàn thành hôm nay</span>
            <strong>{{ number_format($completedOrders, 0, ',', '.') }}</strong>
            <span class="fs-ops-kpi__meta">Tỷ lệ {{ $completionRate }}% · {{ $cancelledOrders }} đơn huỷ</span>
        </a>

        <article class="fs-ops-kpi tone-violet">
            <span class="fs-ops-kpi__icon"><x-heroicon-o-banknotes /></span>
            <span class="fs-ops-kpi__label">Doanh thu hôm nay</span>
            <strong>{{ $revenue }}</strong>
            <span class="fs-ops-kpi__meta">{{ $trends['revenue']['text'] }} so với cùng giờ hôm qua</span>
        </article>
    </div>
</x-filament-widgets::widget>
