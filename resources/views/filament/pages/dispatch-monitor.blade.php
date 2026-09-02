<x-filament-panels::page>
    @php
        $stats = $this->getTodayStats();
        $supply = $this->getDriverSupply();
        $activeOrders = $this->getActiveOrders();
        $recentOffers = $this->getRecentOffers();
        $supplyTotal = max(1, $supply['online']);
        $segments = [
            ['label' => 'Sẵn sàng', 'key' => 'ready', 'color' => '#16a34a'],
            ['label' => 'Đang chạy 1 đơn', 'key' => 'busy1', 'color' => '#0ea5e9'],
            ['label' => 'Đủ 2 đơn', 'key' => 'busy2', 'color' => '#f59e0b'],
            ['label' => 'Đang nhận offer', 'key' => 'holding', 'color' => '#8b5cf6'],
            ['label' => 'Mất kết nối', 'key' => 'dead', 'color' => '#ef4444'],
        ];
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Trung tâm vận hành</p>
            <h1 class="fs-page-header__title">Theo dõi phát đơn</h1>
            <p class="fs-page-header__description">{{ filament()->getTenant()?->name }} · Dữ liệu tự cập nhật mỗi 15 giây.</p>
        </div>
        <div class="fs-dispatch-live"><i></i> Đang trực tuyến</div>
    </header>

    <div wire:poll.15s.visible class="fs-dispatch-layout">
        <div class="fs-dispatch-kpis">
            @foreach ([
                ['Đơn phát hôm nay', $stats['total'], 'Toàn bộ lượt phát', 'gray'],
                ['Đã có tài xế', $stats['accepted'], $stats['accept_rate'] . '% thành công', 'green'],
                ['Chờ trung bình', $stats['avg_wait_secs'] . 's', $stats['avg_attempts'] . ' lượt hỏi', 'orange'],
                ['Không tìm được', $stats['no_driver'], 'Cần kiểm tra thủ công', 'red'],
            ] as $index => $card)
                <article class="fs-dispatch-kpi fs-dispatch-kpi--{{ $card[3] }}">
                    <span>{{ $card[0] }}</span><strong>{{ $card[1] }}</strong>
                    <small>{{ is_string($card[2]) ? $card[2] : '' }}</small>
                </article>
            @endforeach
        </div>

        <section class="fs-dispatch-panel">
            <div class="fs-dispatch-panel__header">
                <div><h2>Lực lượng tài xế</h2><p>{{ $supply['online'] }} tài xế đang online</p></div>
                <span>{{ $supply['ready'] }} sẵn sàng</span>
            </div>
            <div class="fs-supply-body">
                <div class="fs-supply-bar">
                    @foreach($segments as $segment)
                        @if($supply[$segment['key']] > 0)
                            <i style="width: {{ $supply[$segment['key']] / $supplyTotal * 100 }}%; background: {{ $segment['color'] }}" title="{{ $segment['label'] }}: {{ $supply[$segment['key']] }}"></i>
                        @endif
                    @endforeach
                </div>
                <div class="fs-supply-stats">
                    @foreach($segments as $segment)
                        <div><i style="background:{{ $segment['color'] }}"></i><span>{{ $segment['label'] }}</span><strong>{{ $supply[$segment['key']] }}</strong></div>
                    @endforeach
                </div>
            </div>
        </section>

        <div class="fs-dispatch-columns">
            <section class="fs-dispatch-panel">
                <div class="fs-dispatch-panel__header">
                    <div><h2>Đơn đang phát</h2><p>Đang chờ tài xế phản hồi</p></div>
                    <span>{{ count($activeOrders) }} đơn</span>
                </div>
                <div class="fs-dispatch-list">
                    @forelse($activeOrders as $order)
                        @php
                            $level = $order['elapsed'] > 300 ? 'danger' : ($order['elapsed'] > 120 ? 'warning' : 'success');
                            $minutes = floor($order['elapsed'] / 60);
                            $elapsed = $minutes ? $minutes . 'p' . str_pad($order['elapsed'] % 60, 2, '0', STR_PAD_LEFT) . 's' : $order['elapsed'] . 's';
                        @endphp
                        <article wire:key="active-order-{{ $order['id'] }}" class="fs-dispatch-order fs-dispatch-order--{{ $level }} dispatch-card" data-started="{{ \Carbon\Carbon::parse($order['started_at'])->timestamp }}">
                            <div class="fs-dispatch-order__top">
                                <a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order['id']]) }}">#{{ $order['code'] }}</a>
                                <span>{{ $order['service_type'] }}</span>
                                <strong class="dispatch-timer">{{ $elapsed }}</strong>
                            </div>
                            <div class="fs-dispatch-route"><span>↑ {{ $order['pickup_address'] ?: '—' }}</span><span>↓ {{ $order['delivery_address'] ?: '—' }}</span></div>
                            <div class="fs-dispatch-order__meta"><span>Lượt hỏi {{ $order['attempts'] }}</span><span>{{ $order['status'] }}</span></div>
                        </article>
                    @empty
                        <div class="fs-empty">Không có đơn nào đang phát.</div>
                    @endforelse
                </div>
            </section>

            <section class="fs-dispatch-panel">
                <div class="fs-dispatch-panel__header"><div><h2>Offer gần đây</h2><p>20 lượt gửi mới nhất</p></div></div>
                <div class="fs-dispatch-list">
                    @forelse($recentOffers as $offer)
                        @php $config = \App\Filament\Pages\DispatchMonitorPage::offerResultConfig($offer['result']); @endphp
                        <article wire:key="offer-{{ $offer['id'] }}" class="fs-offer-row">
                            <div><a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $offer['order_id']]) }}">#{{ $offer['order_code'] }}</a><strong>{{ $offer['driver_name'] }}</strong><small>{{ $offer['offered_at'] }}</small></div>
                            <div><span style="color:{{ $config['color'] }}">{{ $config['label'] }}</span>@if($offer['response_sec'] !== null)<small>{{ $offer['response_sec'] }}s</small>@endif</div>
                        </article>
                    @empty
                        <div class="fs-empty">Chưa có lịch sử offer.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <script>
        (() => {
            const tick = () => document.querySelectorAll('.dispatch-card').forEach((card) => {
                const seconds = Math.max(0, Math.floor(Date.now() / 1000) - Number(card.dataset.started));
                const minutes = Math.floor(seconds / 60);
                const timer = card.querySelector('.dispatch-timer');
                if (timer) timer.textContent = minutes ? `${minutes}p${String(seconds % 60).padStart(2, '0')}s` : `${seconds}s`;
            });
            if (window.flashshipDispatchTimer) clearInterval(window.flashshipDispatchTimer);
            window.flashshipDispatchTimer = setInterval(tick, 1000);
            tick();
        })();
    </script>
</x-filament-panels::page>
