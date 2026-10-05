<x-filament-widgets::widget>
    @php
        $orders = $this->getOrders();
        $total = $this->getTotal();
        $statusLabels = ['assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng'];
    @endphp

    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Đơn đang chạy</h2>
                <p class="fs-widget-description">Các đơn đã có tài xế, chưa giao xong</p>
            </div>
            <span class="fs-status-pill"><span class="fs-status-dot"></span>{{ $total }} đang chạy</span>
        </header>

        @if ($orders->isEmpty())
            <div class="fs-empty"><div>Hiện không có đơn nào đang chạy</div></div>
        @else
            <ul class="fs-live-list">
                @foreach ($orders as $order)
                    <li>
                        <a class="fs-live-row" href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order->id]) }}" wire:navigate>
                            <span class="fs-live-row__main">
                                <span class="fs-live-row__code">{{ $order->code ?: '#'.$order->id }}</span>
                                <span class="fs-live-row__service">{{ $this->serviceLabel($order->service_type) }}</span>
                                <span class="fs-order-pill fs-order-pill--{{ $order->status === 'assigned' ? 'info' : 'primary' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span>
                            </span>
                            <span class="fs-live-row__route">
                                {{ \Illuminate\Support\Str::limit($order->pickup_address, 34) }}
                                <i>→</i>
                                {{ \Illuminate\Support\Str::limit($order->delivery_address, 34) }}
                            </span>
                            <span class="fs-live-row__meta">
                                <b>{{ $order->driver?->name ?? '—' }}</b>
                                <small>{{ $order->created_at?->diffForHumans(['short' => true, 'parts' => 1]) }} · {{ number_format((float) $order->shipping_fee, 0, ',', '.') }}₫</small>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if ($total > $this->getLimit())
                <a href="{{ \App\Filament\Resources\OrderResource::getUrl('index', ['activeTab' => 'processing']) }}" wire:navigate class="fs-driver-more">
                    +{{ $total - $this->getLimit() }} đơn khác · Xem tất cả
                </a>
            @endif
        @endif
    </section>
</x-filament-widgets::widget>
