<x-filament-panels::page>

<div wire:poll.15s>

@php
    $stats  = $this->getTodayStats();
    $supply = $this->getDriverSupply();
    $activeOrders = $this->getActiveOrders();
@endphp

{{-- Thống kê phát đơn hôm nay --}}
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
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
<div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <span class="font-semibold text-sm">Lực lượng tài xế lúc này</span>
    </div>
    <div class="grid grid-cols-3 md:grid-cols-6 gap-3 text-center">
        <div>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $supply['online'] }}</p>
            <p class="text-xs text-gray-500">Online</p>
        </div>
        <div>
            <p class="text-2xl font-bold" style="color:#16a34a">{{ $supply['ready'] }}</p>
            <p class="text-xs text-gray-500">Sẵn sàng nhận</p>
        </div>
        <div>
            <p class="text-2xl font-bold" style="color:#0ea5e9">{{ $supply['busy1'] }}</p>
            <p class="text-xs text-gray-500">Đang chạy 1 đơn</p>
        </div>
        <div>
            <p class="text-2xl font-bold" style="color:#f59e0b">{{ $supply['busy2'] }}</p>
            <p class="text-xs text-gray-500">Full 2 đơn</p>
        </div>
        <div>
            <p class="text-2xl font-bold" style="color:#8b5cf6">{{ $supply['holding'] }}</p>
            <p class="text-xs text-gray-500">Đang cầm offer</p>
        </div>
        <div>
            <p class="text-2xl font-bold" style="color:#ef4444">{{ $supply['dead'] }}</p>
            <p class="text-xs text-gray-500">Mất kết nối (app chết)</p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Đơn đang phát --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <span class="font-semibold text-sm">Đơn đang chờ tài xế nhận</span>
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
                <div class="dispatch-card rounded-xl border overflow-hidden {{ $bgClass }}"
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
                    <svg class="w-10 h-10 mx-auto text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-gray-400 text-sm">Không có đơn nào đang phát</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Lịch sử offer gần đây --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">
            Lịch sử offer gần đây
        </div>
        <div class="p-3 space-y-2 max-h-[600px] overflow-y-auto">
            @forelse($this->getRecentOffers() as $r)
                @php
                    $resultColor = match($r['result']) {
                        'accepted'     => '#22c55e',
                        'declined'     => '#ef4444',
                        'expired'      => '#9ca3af',
                        'skipped_busy' => '#f97316',
                        default        => '#f59e0b',
                    };
                    $resultLabel = match($r['result']) {
                        'accepted'     => 'Nhận',
                        'declined'     => 'Từ chối',
                        'expired'      => 'Hết hạn',
                        'skipped_busy' => 'Bận',
                        default        => 'Đang chờ',
                    };
                @endphp
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
                     style="border-left: 3px solid {{ $resultColor }};">

                    <div class="flex items-center gap-1.5 px-3 py-2 text-sm flex-wrap">
                        <span class="font-bold text-gray-900 dark:text-white">#{{ $r['order_id'] }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $r['driver_name'] }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="text-gray-400 text-xs">{{ $r['offered_at'] }}</span>
                    </div>

                    <div class="mx-3 border-t border-gray-100 dark:border-gray-800"></div>

                    <div class="flex items-center gap-1.5 px-3 py-2 text-xs">
                        <span class="px-2 py-0.5 rounded-full font-medium" style="background:{{ $resultColor }}22;color:{{ $resultColor }}">{{ $resultLabel }}</span>
                        @if($r['response_sec'] !== null)
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="text-gray-500 dark:text-gray-400">phản hồi sau {{ $r['response_sec'] }}s</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-10 text-center">
                    <svg class="w-10 h-10 mx-auto text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
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
