<x-filament-panels::page class="fi-resource-list-records-page fi-resource-orders fs-orders">
    <link rel="stylesheet" href="{{ asset('css/order-list.css') }}?v={{ filemtime(public_path('css/order-list.css')) }}">

    <div class="fs-orders-list">
        <header class="fs-orders-list__heading">
            <div><h2>Danh sách đơn hàng</h2><p>Chọn trạng thái để theo dõi và xử lý đơn trong khu vực.</p></div>
            @if (in_array($this->activeTab, ['new', 'processing'], true))
                <span class="fs-orders-live"><i aria-hidden="true"></i>Cập nhật mỗi 15 giây</span>
            @else
                <span class="fs-orders-history">{{ $this->activeTab === 'all' ? 'Tất cả trạng thái' : 'Lịch sử đơn hàng' }}</span>
            @endif
        </header>

        <x-filament-panels::resources.tabs />

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
