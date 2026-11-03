<x-filament-widgets::widget>
    {{-- Widget thường của Filament không tự làm mới: số liệu vận hành phải tự gọi lại. --}}
    <div wire:poll.15s>
    <div class="fs-ops-head">
        <div class="fs-ops-head__title">
            <span class="fs-dashboard-mark"><x-heroicon-o-squares-2x2 /></span>
            <div>
                <span class="fs-dashboard-eyebrow">TRUNG TÂM ĐIỀU HÀNH</span>
                <h1>Tổng quan vận hành</h1>
                <p>{{ $cityName }} <span>·</span> {{ now()->translatedFormat('l, d/m/Y') }}</p>
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
            @if ($mapUrl)
                <a class="fs-action" href="{{ $mapUrl }}" wire:navigate>
                    <x-heroicon-o-map-pin class="h-4 w-4" />Bản đồ
                </a>
            @endif
        </div>
    </div>

    <div class="fs-dashboard-section-label">
        <h2>Hiệu quả hôm nay</h2>
        <span><i></i>Cập nhật {{ now()->format('H:i:s') }}</span>
    </div>
    <div class="fs-ops-kpis">
        <a class="fs-ops-kpi tone-orange" href="{{ \App\Filament\Resources\OrderResource::getUrl() }}" wire:navigate>
            <span class="fs-ops-kpi__icon"><x-heroicon-o-archive-box /></span>
            <span class="fs-ops-kpi__label">Đơn hôm nay</span>
            <strong>{{ number_format($totalOrders, 0, ',', '.') }}</strong>
            <span class="fs-ops-kpi__meta"><b class="fs-delta is-{{ $trends['total']['dir'] }}">{{ $trends['total']['text'] }}</b> so cùng giờ hôm qua</span>
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
            <span class="fs-ops-kpi__meta">{{ $completionRate }}% đơn tạo hôm nay đã xong · {{ $cancelledOrders }} huỷ</span>
        </a>

        @php $revenueTag = $revenueUrl ? 'a' : 'article'; @endphp
        <{{ $revenueTag }} class="fs-ops-kpi tone-violet" @if ($revenueUrl) href="{{ $revenueUrl }}" wire:navigate @endif title="Tiền khách trả cho đơn hoàn thành hôm nay, cùng cách tính với trang Doanh thu">
            <span class="fs-ops-kpi__icon"><x-heroicon-o-banknotes /></span>
            <span class="fs-ops-kpi__label">Doanh thu hôm nay</span>
            <strong>{{ $revenue }}</strong>
            <span class="fs-ops-kpi__meta"><b class="fs-delta is-{{ $trends['revenue']['dir'] }}">{{ $trends['revenue']['text'] }}</b> so cùng giờ hôm qua</span>
        </{{ $revenueTag }}>
    </div>
    <div class="fs-dashboard-pulse">
        <span><x-heroicon-o-signal /> Nhịp vận hành</span>
        <span><i class="is-ready"></i><b>{{ number_format($driversReady, 0, ',', '.') }}</b> tài xế sẵn sàng</span>
        <span><i class="is-busy"></i><b>{{ number_format($driversBusy, 0, ',', '.') }}</b> tài xế đang chạy</span>
        <span><b>{{ number_format($pendingOrders, 0, ',', '.') }}</b> đơn hoạt động</span>
    </div>
    </div>
</x-filament-widgets::widget>
