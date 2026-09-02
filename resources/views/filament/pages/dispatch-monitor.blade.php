<x-filament-panels::page>

<header class="fs-page-header">
    <div>
        <p class="fs-page-header__eyebrow">Trung tâm vận hành</p>
        <h1 class="fs-page-header__title">Theo dõi phát đơn</h1>
        <p class="fs-page-header__description">Giám sát offer, thời gian chờ và nguồn cung tài xế; dữ liệu tự cập nhật mỗi 15 giây.</p>
    </div>
</header>

<div wire:poll.15s.visible style="padding-bottom:32px;">

@php
    $stats  = $this->getTodayStats();
    $supply = $this->getDriverSupply();
    $activeOrders = $this->getActiveOrders();
    $supplyTotal = max(1, $supply['online']); // tránh chia 0 khi không ai online
    $supplySegments = [
        ['key' => 'ready',   'label' => 'Sẵn sàng nhận',        'color' => '#16a34a', 'value' => $supply['ready']],
        ['key' => 'busy1',   'label' => 'Đang chạy 1 đơn',      'color' => '#0ea5e9', 'value' => $supply['busy1']],
        ['key' => 'busy2',   'label' => 'Full 2 đơn',           'color' => '#f59e0b', 'value' => $supply['busy2']],
        ['key' => 'holding', 'label' => 'Đang cầm offer',       'color' => '#8b5cf6', 'value' => $supply['holding']],
        ['key' => 'dead',    'label' => 'Mất kết nối (app chết)', 'color' => '#ef4444', 'value' => $supply['dead']],
    ];
@endphp

{{-- Thống kê phát đơn hôm nay --}}
<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
    <x-heroicon-o-chart-bar class="w-4 h-4 text-gray-400" />
    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Thống kê phát đơn hôm nay</span>
</div>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3" style="margin-bottom:32px;">
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Đơn phát hôm nay</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
    </div>
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Đã có tài xế</p>
        <p class="text-2xl font-bold" style="color:#16a34a">{{ $stats['accepted'] }} <span class="text-sm font-semibold">({{ $stats['accept_rate'] }}%)</span></p>
    </div>
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Nhận ngay lần hỏi đầu</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['first_try_rate'] }}%</p>
    </div>
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Số lần hỏi TB</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['avg_attempts'] }}</p>
    </div>
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Chờ tài xế TB</p>
        <p class="text-2xl font-bold" style="color:{{ $stats['avg_wait_secs'] > 120 ? '#ef4444' : '#111827' }}">{{ $stats['avg_wait_secs'] }}s</p>
    </div>
    <div class="rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-500">Không tìm được TX</p>
        <p class="text-2xl font-bold" style="color:{{ $stats['no_driver'] > 0 ? '#ef4444' : '#16a34a' }}">{{ $stats['no_driver'] }}</p>
    </div>
</div>

{{-- Lực lượng tài xế lúc này --}}
<div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-700" style="padding:16px; margin-bottom:32px;">
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
        <x-heroicon-o-truck class="w-4 h-4 text-gray-400" />
        <span class="font-semibold text-sm text-gray-700 dark:text-gray-200">Lực lượng tài xế lúc này</span>
        <span class="text-xs text-gray-400">— {{ $supply['online'] }} đang online</span>
    </div>

    {{-- Thanh tỉ lệ — nhìn 1 cái biết ngay cơ cấu lực lượng, không phải cộng nhẩm 5 con số --}}
    <div style="display:flex; width:100%; height:10px; border-radius:999px; overflow:hidden; background:#f3f4f6; margin-bottom:20px;">
        @foreach ($supplySegments as $seg)
            @if ($seg['value'] > 0)
                <div style="display:block; height:100%; width:{{ $seg['value'] / $supplyTotal * 100 }}%; background:{{ $seg['color'] }};"
                     title="{{ $seg['label'] }}: {{ $seg['value'] }}"></div>
            @endif
        @endforeach
    </div>

    <div style="display:flex; flex-wrap:wrap; gap:10px;">
        <div style="flex:1 1 120px; min-width:110px; border-radius:12px; padding:12px 14px; background:#f9fafb; border:1px solid #e5e7eb;">
            <p class="text-2xl font-bold text-gray-900 dark:text-white" style="margin:0;">{{ $supply['online'] }}</p>
            <p class="text-xs text-gray-500" style="margin:2px 0 0;">Online</p>
        </div>
        @foreach ($supplySegments as $seg)
            <div style="flex:1 1 120px; min-width:110px; border-radius:12px; padding:12px 14px; background:{{ $seg['color'] }}0d; border:1px solid {{ $seg['color'] }}33;">
                <p class="text-2xl font-bold" style="margin:0; color:{{ $seg['color'] }}">{{ $seg['value'] }}</p>
                <p class="text-xs text-gray-500" style="margin:2px 0 0;">{{ $seg['label'] }}</p>
            </div>
        @endforeach
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Đơn đang phát --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <span class="font-semibold text-sm flex items-center gap-2">
                <x-heroicon-o-signal class="w-4 h-4 text-gray-400" />
                Đơn đang chờ tài xế nhận
            </span>
            @if(count($activeOrders))
                <span class="text-xs font-semibold rounded-full px-2 py-0.5" style="background:#ffedd5;color:#ea580c">{{ count($activeOrders) }} đơn</span>
            @endif
        </div>
        <div class="p-3 space-y-2 max-h-[600px] overflow-y-auto">
            @forelse($activeOrders as $o)
                @php
                    $urgency = $o['elapsed'] > 300 ? 'red' : ($o['elapsed'] > 120 ? 'yellow' : 'green');
                    $accentColor = $urgency === 'red' ? '#ef4444' : ($urgency === 'yellow' ? '#f59e0b' : '#22c55e');
                    $bgClass = $urgency === 'red' ? 'bg-red-50 dark:bg-red-950/30' : ($urgency === 'yellow' ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-green-50 dark:bg-green-950/20');
                    $mins = floor($o['elapsed'] / 60);
                    $secs = $o['elapsed'] % 60;
                    $elapsed = $mins > 0 ? $mins . 'p' . str_pad($secs, 2, '0', STR_PAD_LEFT) . 's' : $secs . 's';
                @endphp
                <div wire:key="active-order-{{ $o['id'] }}"
                     class="dispatch-card rounded-xl border overflow-hidden {{ $bgClass }}"
                     data-started="{{ \Carbon\Carbon::parse($o['started_at'])->timestamp }}"
                     style="border-color: {{ $accentColor }}20; border-left: 3px solid {{ $accentColor }};">

                    <div class="flex items-center gap-1.5 px-3 py-3 text-sm flex-wrap">
                        <span class="font-bold text-gray-900 dark:text-white">#{{ $o['id'] }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $o['service_type'] }}</span>
                        @if($o['city'])
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $o['city'] }}</span>
                        @endif
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="dispatch-timer font-bold" style="color:{{ $accentColor }}">{{ $elapsed }}</span>
                    </div>

                    <div class="mx-3 border-t border-gray-200 dark:border-gray-700"></div>

                    <div class="flex items-center gap-1.5 px-3 py-3 text-xs flex-wrap">
                        <span>Lần hỏi <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $o['attempts'] }}</span></span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="italic text-gray-500 dark:text-gray-400">{{ $o['status'] }}</span>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center">
                    <x-heroicon-o-check-circle class="w-10 h-10 mx-auto text-gray-300 mb-2" />
                    <p class="text-gray-400 text-sm">Không có đơn nào đang phát</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Lịch sử offer gần đây --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm flex items-center gap-2">
            <x-heroicon-o-clock class="w-4 h-4 text-gray-400" />
            Lịch sử offer gần đây
        </div>
        <div class="p-3 space-y-2 max-h-[600px] overflow-y-auto">
            @forelse($this->getRecentOffers() as $r)
                @php $cfg = \App\Filament\Pages\DispatchMonitorPage::offerResultConfig($r['result']); @endphp
                <div wire:key="offer-{{ $r['id'] }}"
                     class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
                     style="border-left: 3px solid {{ $cfg['color'] }};">

                    <div class="flex items-center gap-1.5 px-3 py-2 text-sm flex-wrap">
                        <span class="font-bold text-gray-900 dark:text-white">#{{ $r['order_id'] }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $r['driver_name'] }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="text-gray-400 text-xs">{{ $r['offered_at'] }}</span>
                    </div>

                    <div class="mx-3 border-t border-gray-100 dark:border-gray-800"></div>

                    <div class="flex items-center gap-1.5 px-3 py-2 text-xs">
                        <span class="px-2 py-0.5 rounded-full font-medium" style="background:{{ $cfg['color'] }}22;color:{{ $cfg['color'] }}">{{ $cfg['label'] }}</span>
                        @if($r['response_sec'] !== null)
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="text-gray-500 dark:text-gray-400">phản hồi sau {{ $r['response_sec'] }}s</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-10 text-center">
                    <x-heroicon-o-document-text class="w-10 h-10 mx-auto text-gray-300 mb-2" />
                    <p class="text-gray-400 text-sm">Chưa có dữ liệu</p>
                </div>
            @endforelse
        </div>
    </div>

</div>

</div>

<script>
(function () {
    function formatElapsed(secs) {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return m > 0 ? m + 'p' + String(s).padStart(2, '0') + 's' : s + 's';
    }
    function urgencyColor(secs) {
        if (secs > 300) return '#ef4444';
        if (secs > 120) return '#f59e0b';
        return '#22c55e';
    }
    function tick() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.dispatch-card').forEach(function (card) {
            const started = parseInt(card.dataset.started);
            if (!started) return;
            const elapsed = Math.max(0, now - started);
            const color = urgencyColor(elapsed);
            const timer = card.querySelector('.dispatch-timer');
            if (timer) {
                timer.textContent = formatElapsed(elapsed);
                timer.style.color = color;
            }
        });
    }
    if (window._dispatchTimerInterval) clearInterval(window._dispatchTimerInterval);
    window._dispatchTimerInterval = setInterval(tick, 1000);
    tick();
})();
</script>

</x-filament-panels::page>
