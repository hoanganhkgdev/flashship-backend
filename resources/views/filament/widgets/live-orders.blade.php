<x-filament-widgets::widget>
    @php
        $orders = $this->getOrders();
        $total = $this->getTotal();
        $statusLabels = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã nhận', 'processing' => 'Đang giao'];
    @endphp

    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Đơn cần theo dõi</h2>
                <p class="fs-widget-description">Ưu tiên đơn chờ lâu, sau đó đến các đơn đang thực hiện</p>
            </div>
            <span class="fs-count-chip">{{ $total }} đơn hoạt động</span>
        </header>

        @if ($orders->isEmpty())
            <div class="fs-empty"><div>Không có đơn nào cần theo dõi</div></div>
        @else
            <div class="fs-ops-orders">
                @foreach ($orders as $order)
                    @php
                        $waiting = $order->status === 'pending' ? (int) $order->created_at->diffInMinutes(now()) : 0;
                        $urgent = $order->status === 'pending' && $waiting >= 5;
                    @endphp
                    <a class="fs-ops-order {{ $urgent ? 'is-urgent' : '' }}" href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order->id]) }}" wire:navigate>
                        <span class="fs-ops-order__code">#{{ $order->code ?: $order->id }}</span>
                        <span class="fs-ops-order__route">
                            <b>{{ \Illuminate\Support\Str::limit($order->pickup_address, 30) }}</b>
                            <i>→</i>
                            <span>{{ \Illuminate\Support\Str::limit($order->delivery_address ?: 'Chưa có điểm giao', 30) }}</span>
                        </span>
                        <span class="fs-ops-order__driver">{{ $order->driver?->name ?? 'Chưa có tài xế' }}</span>
                        <span class="fs-ops-order__status is-{{ $order->status }}">{{ $statusLabels[$order->status] ?? $order->status }}@if ($order->status === 'pending') · {{ $waiting }}p @endif</span>
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
