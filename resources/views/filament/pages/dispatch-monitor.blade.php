<x-filament-panels::page>
    @php
        $stats = $this->getStats();
        $t = $stats['today'];
        $y = $stats['yesterday'];
        $timeoutSecs = $this->getTimeoutSeconds();
        $supply = $this->getDriverSupply();
        $orders = $this->getOrders();
        $attention = $orders['attention'];
        $active = $orders['active'];
        $recentOffers = $this->getRecentOffers();
        $decliners = $this->getTopDecliners();
        $hourly = $this->getHourly();
        $hourMax = max(1, collect($hourly)->max('total'));
        $nowHour = (int) now()->format('G');
        $supplyTotal = max(1, $supply['online']);
        $segments = [
            ['label' => 'Sẵn sàng', 'key' => 'ready', 'color' => '#16a34a'],
            ['label' => 'Đang chạy 1 đơn', 'key' => 'busy1', 'color' => '#0ea5e9'],
            ['label' => 'Đủ 2 đơn', 'key' => 'busy2', 'color' => '#f59e0b'],
            ['label' => 'Đang nhận offer', 'key' => 'holding', 'color' => '#8b5cf6'],
            ['label' => 'Mất kết nối', 'key' => 'dead', 'color' => '#ef4444'],
        ];
        $dur = fn (int $s): string => $s >= 60 ? intdiv($s, 60) . 'p' . str_pad($s % 60, 2, '0', STR_PAD_LEFT) . 's' : $s . 's';
        $ago = function (?int $s): string {
            if ($s === null) { return 'Chưa có vị trí'; }
            if ($s < 60) { return $s . ' giây trước'; }
            if ($s < 3600) { return intdiv($s, 60) . ' phút trước'; }
            return intdiv($s, 3600) . ' giờ trước';
        };
        $longestNow = collect($orders['attention'])->merge($orders['active'])->max('elapsed') ?? 0;
        $longest = max($t['max_wait_secs'], $longestNow);
        $driverUrl = fn (int $id): string => \App\Filament\Resources\DriverResource::getUrl('view', ['record' => $id]);
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Trung tâm vận hành</p>
            <h1 class="fs-page-header__title">Theo dõi phát đơn</h1>
            <p class="fs-page-header__description">{{ filament()->getTenant()?->name }} · Tự cập nhật mỗi 15 giây · so sánh với hôm qua cùng khung giờ.</p>
        </div>
        <div class="fs-dispatch-live"><i></i> Đang trực tuyến · {{ now()->format('H:i:s') }}</div>
    </header>

    <div wire:poll.15s.visible class="fs-dispatch-layout">
        {{-- Thanh số liệu --}}
        <div class="fs-dispatch-kpis fs-dispatch-kpis--5">
            @foreach ([
                ['Đơn phát hôm nay', $t['total'], 'Cùng giờ hôm qua: ' . $y['total'], 'gray', 'heroicon-o-paper-airplane'],
                ['Tỷ lệ có tài xế', $t['accept_rate'] . '%', $t['accepted'] . '/' . $t['total'] . ' đơn · hôm qua ' . $y['accept_rate'] . '%', 'green', 'heroicon-o-check-circle'],
                ['Chờ trung bình', $dur($t['avg_wait_secs']), $t['avg_attempts'] . ' lượt hỏi · hôm qua ' . $dur($y['avg_wait_secs']), 'orange', 'heroicon-o-clock'],
                ['Chờ lâu nhất', $dur($longest), ($longestNow > 0 ? 'Đang chờ ' . $dur($longestNow) . ' · ' : '') . 'hôm qua ' . $dur($y['max_wait_secs']), $longest > $timeoutSecs * 0.5 ? 'red' : 'orange', 'heroicon-o-bolt'],
                ['Không tìm được', $t['no_driver'], count($attention) . ' đơn đang chờ xử lý · hôm qua ' . $y['no_driver'], 'red', 'heroicon-o-exclamation-triangle'],
            ] as $card)
                <article class="fs-dispatch-kpi fs-dispatch-kpi--{{ $card[3] }}">
                    <div class="fs-dispatch-kpi__top">
                        <span>{{ $card[0] }}</span>
                        <span class="fs-dispatch-kpi__icon"><x-dynamic-component :component="$card[4]" class="h-4 w-4" /></span>
                    </div>
                    <strong>{{ $card[1] }}</strong>
                    <small>{{ $card[2] }}</small>
                </article>
            @endforeach
        </div>

        {{-- Cần xử lý ngay --}}
        @if(count($attention))
            <section class="fs-dispatch-panel fs-attn-panel">
                <div class="fs-dispatch-panel__header">
                    <div><h2>Cần xử lý ngay</h2><p>Đơn đã dừng tìm tự động hoặc chờ quá {{ round($timeoutSecs / 60) }} phút — gán tài xế hoặc liên hệ khách</p></div>
                    <span class="fs-attn-count">{{ count($attention) }} đơn</span>
                </div>
                <div class="fs-dispatch-list fs-attn-list">
                    @foreach($attention as $o)
                        <article wire:key="attn-{{ $o['id'] }}" class="fs-attn-row dispatch-card" data-started="{{ $o['started_at'] }}">
                            <div class="fs-attn-main">
                                <div class="fs-attn-top">
                                    <a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $o['id']]) }}">#{{ $o['code'] }}</a>
                                    <span>{{ $o['service'] }}</span>
                                    <em class="fs-attn-tag fs-attn-tag--{{ $o['kind'] }}">{{ $o['kind'] === 'no_driver' ? 'Hệ thống đã dừng tìm' : 'Quá giới hạn' }}</em>
                                </div>
                                <div class="fs-dispatch-route">
                                    <span title="{{ $o['pickup'] }}">↑ {{ $o['pickup'] }}</span>
                                    <span title="{{ $o['delivery'] }}">↓ {{ $o['delivery'] }}</span>
                                </div>
                                @if($o['note'] !== '')
                                    <div class="fs-attn-note" title="{{ $o['note'] }}"><b>Ghi chú</b> {{ $o['note'] }}</div>
                                @endif
                                <div class="fs-attn-meta">
                                    <span>{{ $o['customer'] }}</span>
                                    @if($o['phone'])<a href="tel:{{ preg_replace('/\D+/', '', $o['phone']) }}">{{ \App\Support\OrderListPresenter::phone($o['phone']) }}</a>@else<span class="fs-dim">Chưa có SĐT</span>@endif
                                    <span>Phí {{ number_format($o['fee'], 0, ',', '.') }}đ</span>
                                    <span>{{ $o['attempts'] }} lượt hỏi</span>
                                </div>
                            </div>
                            <div class="fs-attn-side">
                                <strong class="dispatch-timer">{{ $dur($o['elapsed']) }}</strong>
                                <div class="fs-attn-actions">
                                    <button type="button" wire:click="mountAction('assign', { order: {{ $o['id'] }} })" class="fs-order-pill fs-order-pill--info">Gán tài xế</button>
                                    @if($o['phone'])<a href="tel:{{ preg_replace('/\D+/', '', $o['phone']) }}" class="fs-order-pill fs-order-pill--success">Gọi khách</a>@endif
                                    <button type="button" wire:click="mountAction('cancel', { order: {{ $o['id'] }} })" class="fs-order-pill fs-order-pill--danger">Hủy đơn</button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Lực lượng tài xế --}}
        <section class="fs-dispatch-panel">
            <div class="fs-dispatch-panel__header">
                <div><h2>Lực lượng tài xế</h2><p>{{ $supply['online'] }} tài xế đang online · bấm vào từng nhóm để xem là ai</p></div>
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
                        <button type="button" wire:click="toggleSegment('{{ $segment['key'] }}')" class="fs-supply-item {{ $this->segment === $segment['key'] ? 'is-open' : '' }}" @disabled($supply[$segment['key']] === 0)>
                            <i style="background:{{ $segment['color'] }}"></i><span>{{ $segment['label'] }}</span><strong>{{ $supply[$segment['key']] }}</strong>
                        </button>
                    @endforeach
                </div>

                @if($this->segment && ! empty($supply['drivers'][$this->segment]))
                    <div class="fs-supply-list">
                        @if($this->segment === 'dead')
                            <p class="fs-supply-hint">Đang bật online nhưng không có vị trí mới trong {{ \Modules\Driver\Services\DriverLocationService::POS_MAX_AGE_SECS / 60 }} phút — gọi hỏi hoặc nhắc mở lại app.</p>
                        @endif
                        @foreach(collect($supply['drivers'][$this->segment])->sortBy('seen_ago')->values() as $d)
                            <div class="fs-supply-row" wire:key="sup-{{ $this->segment }}-{{ $d['id'] }}">
                                <a href="{{ $driverUrl($d['id']) }}">{{ $d['name'] }}</a>
                                @if($d['phone'])<a href="tel:{{ preg_replace('/\D+/', '', $d['phone']) }}" class="fs-supply-phone">{{ \App\Support\OrderListPresenter::phone($d['phone']) }}</a>@else<span class="fs-dim">—</span>@endif
                                <span class="fs-dim">{{ $d['active'] }} đơn đang chạy</span>
                                <span class="fs-dim">{{ $ago($d['seen_ago']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <div class="fs-dispatch-columns">
            {{-- Đơn đang phát --}}
            <section class="fs-dispatch-panel">
                <div class="fs-dispatch-panel__header">
                    <div><h2>Đơn đang phát</h2><p>Chờ lâu nhất lên trước · vàng khi quá {{ round($timeoutSecs * 0.2 / 60, 1) }} phút, đỏ khi quá {{ round($timeoutSecs * 0.5 / 60, 1) }} phút (giới hạn {{ round($timeoutSecs / 60) }} phút)</p></div>
                    <span>{{ count($active) }} đơn</span>
                </div>
                <div class="fs-dispatch-list">
                    @forelse($active as $o)
                        @php $level = $o['elapsed'] > $timeoutSecs * 0.5 ? 'danger' : ($o['elapsed'] > $timeoutSecs * 0.2 ? 'warning' : 'success'); @endphp
                        <article wire:key="active-order-{{ $o['id'] }}" class="fs-dispatch-order fs-dispatch-order--{{ $level }} dispatch-card" data-started="{{ $o['started_at'] }}">
                            <div class="fs-dispatch-order__top">
                                <a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $o['id']]) }}">#{{ $o['code'] }}</a>
                                <span>{{ $o['service'] }}</span>
                                <strong class="dispatch-timer">{{ $dur($o['elapsed']) }}</strong>
                            </div>
                            <div class="fs-dispatch-route"><span title="{{ $o['pickup'] }}">↑ {{ $o['pickup'] }}</span><span title="{{ $o['delivery'] }}">↓ {{ $o['delivery'] }}</span></div>
                            <div class="fs-dispatch-order__meta">
                                <span>
                                    @if($o['offering_to'])Đang hỏi <a href="{{ $driverUrl($o['offering_id']) }}" class="fs-inline-link">{{ $o['offering_to'] }}</a>@else Đang quét toàn thành phố…@endif
                                </span>
                                <span>Lượt hỏi {{ $o['attempts'] }} · {{ number_format($o['fee'], 0, ',', '.') }}đ</span>
                            </div>
                            <div class="fs-dispatch-order__meta fs-dispatch-order__actions">
                                <button type="button" wire:click="mountAction('assign', { order: {{ $o['id'] }} })" class="fs-order-pill fs-order-pill--info">Gán tài xế</button>
                                <button type="button" wire:click="mountAction('cancel', { order: {{ $o['id'] }} })" class="fs-order-pill fs-order-pill--danger">Hủy đơn</button>
                            </div>
                        </article>
                    @empty
                        <div class="fs-empty">Không có đơn nào đang phát.</div>
                    @endforelse
                </div>
            </section>

            {{-- Offer + tài xế hay từ chối --}}
            <div class="fs-dispatch-stack">
                <section class="fs-dispatch-panel">
                    <div class="fs-dispatch-panel__header"><div><h2>Tài xế hay từ chối</h2><p>Hôm nay · ít nhất {{ \App\Services\DispatchMonitorReport::MIN_OFFERS_FOR_RANKING }} lượt hỏi</p></div></div>
                    <div class="fs-dispatch-list">
                        @forelse($decliners as $d)
                            <div class="fs-decl-row" wire:key="decl-{{ $d['driver_id'] }}">
                                <a href="{{ $driverUrl($d['driver_id']) }}">{{ $d['name'] }}</a>
                                <small>{{ $d['declined'] }} từ chối · {{ $d['expired'] }} hết hạn · {{ $d['accepted'] }} nhận / {{ $d['offers'] }} lượt</small>
                                <span class="fs-offer-badge fs-offer-badge--declined">{{ $d['decline_rate'] }}%</span>
                            </div>
                        @empty
                            <div class="fs-empty">Chưa có tài xế nào từ chối nhiều hôm nay.</div>
                        @endforelse
                    </div>
                </section>

                <section class="fs-dispatch-panel">
                    <div class="fs-dispatch-panel__header">
                        <div><h2>Offer gần đây</h2><p>30 lượt gửi mới nhất</p></div>
                    </div>
                    <div class="fs-offer-filters">
                        @foreach(\App\Filament\Pages\DispatchMonitorPage::OFFER_FILTERS as $key => $label)
                            <button type="button" wire:click="setOfferFilter('{{ $key }}')" class="{{ $this->offerFilter === $key ? 'is-active' : '' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="fs-dispatch-list">
                        @forelse($recentOffers as $offer)
                            @php $config = \App\Filament\Pages\DispatchMonitorPage::offerResultConfig($offer['result']); @endphp
                            <article wire:key="offer-{{ $offer['id'] }}" class="fs-offer-row fs-offer-row--{{ $offer['result'] }}">
                                <div><a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $offer['order_id']]) }}">#{{ $offer['order_code'] }}</a><a href="{{ $driverUrl($offer['driver_id']) }}" class="fs-offer-driver">{{ $offer['driver_name'] }}</a><small>{{ $offer['offered_at'] }}</small></div>
                                <div><span class="fs-offer-badge fs-offer-badge--{{ $offer['result'] }}">{{ $config['label'] }}</span>@if($offer['response_sec'] !== null)<small>{{ $offer['response_sec'] }}s</small>@endif</div>
                            </article>
                        @empty
                            <div class="fs-empty">Không có lượt offer nào.</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        {{-- Theo giờ --}}
        <section class="fs-dispatch-panel">
            <div class="fs-dispatch-panel__header"><div><h2>Đơn phát theo giờ</h2><p>Hôm nay · phần xanh là đơn đã có tài xế · rê chuột để xem số liệu</p></div></div>
            <div class="fs-hour">
                @foreach($hourly as $h)
                    @php
                        $rate = $h['total'] > 0 ? round($h['accepted'] / $h['total'] * 100) : 0;
                        $tip = sprintf('%02dh: %d đơn phát, %d có tài xế (%d%%)', $h['hour'], $h['total'], $h['accepted'], $rate);
                    @endphp
                    <div class="fs-hour__col {{ $h['hour'] === $nowHour ? 'is-now' : '' }}" title="{{ $tip }}">
                        <em>{{ $h['total'] ?: '' }}</em>
                        <div class="fs-hour__bar"><i class="fs-hour__total" style="height: {{ $h['total'] / $hourMax * 100 }}%"><b style="height: {{ $h['total'] > 0 ? $h['accepted'] / $h['total'] * 100 : 0 }}%"></b></i></div>
                        <span>{{ $h['hour'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
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
