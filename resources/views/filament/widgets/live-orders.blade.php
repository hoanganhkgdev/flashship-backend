<x-filament-widgets::widget>
    @php
        $orders = $this->getOrders();
        $total = $this->getTotal();
        $statusLabels = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã nhận', 'processing' => 'Đang giao'];
    @endphp

    <section class="fs-widget" wire:poll.15s>
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Đơn cần theo dõi</h2>
                <p class="fs-widget-description">Ưu tiên đơn chờ lâu, sau đó đến các đơn đang thực hiện</p>
            </div>
            <span class="fs-count-chip">{{ $total }} đơn hoạt động</span>
        </header>

        @if ($orders->isEmpty())
            <div class="fs-empty fs-dashboard-empty"><x-heroicon-o-check-circle /><strong>Không có đơn nào cần theo dõi</strong><span>Các đơn đang chờ hoặc đang giao sẽ xuất hiện tại đây.</span></div>
        @else
            <div class="fs-dashboard-table-head" aria-hidden="true">
                <span>Mã đơn</span><span>Hành trình</span><span>Tài xế</span><span>Trạng thái</span><span></span>
            </div>
            <div class="fs-ops-orders">
                @foreach ($orders as $order)
                    @php
                        $waiting = $order->status === 'pending' ? (int) $order->created_at->diffInMinutes(now()) : 0;
                        $urgent = $order->status === 'pending' && $waiting >= $this->urgentMinutes();
                    @endphp
                    <a class="fs-ops-order {{ $urgent ? 'is-urgent' : '' }}" href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order->id]) }}" wire:navigate>
                        <span class="fs-ops-order__code">#{{ $order->code ?: $order->id }}</span>
                        <span class="fs-ops-order__route fs-dash-route">
                            <b title="{{ $order->pickup_address }}">{{ \Illuminate\Support\Str::limit($order->pickup_address, 48) }}</b>
                            <span title="{{ $order->delivery_address }}"><i>→</i> {{ \Illuminate\Support\Str::limit($order->delivery_address ?: 'Chưa có điểm giao', 48) }}</span>
                        </span>
                        <span class="fs-ops-order__driver">{{ $order->driver?->name ?? 'Chưa có tài xế' }}</span>
                        <span class="fs-ops-order__status is-{{ $order->status }}">{{ $statusLabels[$order->status] ?? $order->status }}{{ $order->status === 'pending' ? ' · '.\App\Filament\Resources\OrderResource::minutesLabel($waiting) : '' }}</span>
                        <x-heroicon-o-chevron-right class="fs-ops-order__arrow" />
                    </a>
                @endforeach
            </div>
            @if ($total > $this->getLimit())
                <a href="{{ \App\Filament\Resources\OrderResource::getUrl() }}" wire:navigate class="fs-widget-link">
                    Xem thêm {{ $total - $this->getLimit() }} đơn <x-heroicon-o-arrow-right />
                </a>
            @endif
        @endif
    </section>
</x-filament-widgets::widget>
