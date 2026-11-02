<x-filament-panels::page class="fi-resource-list-records-page fi-resource-orders fs-orders">
    <link rel="stylesheet" href="{{ asset('css/order-list.css') }}?v={{ filemtime(public_path('css/order-list.css')) }}">

    <div class="fs-orders-list">
        <div class="fs-orders-list__intro">
            <div class="fs-orders-list__title">
                <span class="fs-orders-list__title-icon"><x-heroicon-o-rectangle-stack /></span>
                <span>
                    <b>Danh sách đơn hàng</b>
                    <small>Bấm vào một đơn để xem nhanh hành trình, thanh toán và lịch sử xử lý.</small>
                </span>
            </div>
            <div class="fs-orders-list__legend" aria-label="Chú thích trạng thái">
                <span><i class="is-priority"></i>Cần ưu tiên</span>
                <span><i class="is-active"></i>Đang xử lý</span>
                <span><i class="is-done"></i>Hoàn thành</span>
            </div>
        </div>

        {{-- Tab trạng thái và nhịp làm mới chung một hàng: bảng phải thấy được ngay trên màn laptop. --}}
        <div class="fs-orders-list__bar">
            <x-filament-panels::resources.tabs />
            @if (in_array($this->activeTab, ['new', 'processing'], true))
                <span class="fs-orders-live"><i aria-hidden="true"></i>Cập nhật mỗi 15 giây</span>
            @else
                <span class="fs-orders-history">{{ $this->activeTab === 'all' ? 'Tất cả trạng thái' : 'Lịch sử đơn hàng' }}</span>
            @endif
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
