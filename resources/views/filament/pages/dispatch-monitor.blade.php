<x-filament-panels::page>

    <div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Đơn đang phát --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <span class="font-semibold text-sm">Đơn đang chờ tài xế nhận</span>
                @php $activeOrders = $this->getActiveOrders(); @endphp
                @if(count($activeOrders))
                    <span class="text-xs font-semibold bg-orange-100 text-orange-600 rounded-full px-2 py-0.5">{{ count($activeOrders) }} đơn</span>
                @endif
            </div>
            <div class="p-3 space-y-2 max-h-[600px] overflow-y-auto">
                @forelse($activeOrders as $o)
                    @php
                        $urgency = $o['elapsed'] > 300 ? 'red' : ($o['elapsed'] > 120 ? 'yellow' : 'green');
                        $accentColor  = $urgency === 'red' ? '#ef4444' : ($urgency === 'yellow' ? '#f59e0b' : '#22c55e');
                        $bgClass      = $urgency === 'red' ? 'bg-red-50 dark:bg-red-950/30' : ($urgency === 'yellow' ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-green-50 dark:bg-green-950/20');
                        $mins = floor($o['elapsed'] / 60);
                        $secs = $o['elapsed'] % 60;
                        $elapsed = $mins > 0 ? $mins . 'p' . str_pad($secs, 2, '0', STR_PAD_LEFT) . 's' : $secs . 's';
                    @endphp
                    <div class="dispatch-card rounded-xl border overflow-hidden {{ $bgClass }}"
                         data-started="{{ \Carbon\Carbon::parse($o['started_at'])->timestamp }}"
                         style="border-color: {{ $accentColor }}20; border-left: 3px solid {{ $accentColor }};">

                        {{-- Header: #id · service · city · elapsed --}}
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

                        {{-- Divider --}}
                        <div class="mx-3 border-t border-gray-200 dark:border-gray-700"></div>

                        {{-- Footer: Lần N · Xkm · Tên tài xế --}}
                        <div class="flex items-center gap-1.5 px-3 py-3 text-xs text-gray-500 dark:text-gray-400 flex-wrap">
                            <span>Lần <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $o['attempts'] }}</span></span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $o['radius'] }}km</span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            @if($o['offering_to'])
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $o['offering_to'] }}</span>
                            @else
                                <span class="italic">Đang tìm tài xế...</span>
                            @endif
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
                            'accepted' => '#22c55e',
                            'declined' => '#ef4444',
                            'expired'  => '#9ca3af',
                            default    => '#f59e0b',
                        };
                        $resultLabel = match($r['result']) {
                            'accepted' => 'Nhận',
                            'declined' => 'Từ chối',
                            'expired'  => 'Hết hạn',
                            default    => 'Đang chờ',
                        };
                        $resultBg = match($r['result']) {
                            'accepted' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                            'declined' => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
                            'expired'  => 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400',
                            default    => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
                        };
                    @endphp
                    <div class="rounded-xl border overflow-hidden"
                         style="border-color: #e5e7eb; border-left: 3px solid {{ $resultColor }};">

                        {{-- #id · tài xế · giờ --}}
                        <div class="flex items-center gap-1.5 px-3 py-2 text-sm flex-wrap">
                            <span class="font-bold text-gray-900 dark:text-white">#{{ $r['order_id'] }}</span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $r['driver_name'] }}</span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span class="text-gray-400 text-xs">{{ $r['offered_at'] }}</span>
                        </div>

                        <div class="mx-3 border-t border-gray-100 dark:border-gray-800"></div>

                        {{-- kết quả · phản hồi --}}
                        <div class="flex items-center gap-1.5 px-3 py-2 text-xs">
                            <span class="px-2 py-0.5 rounded-full font-medium {{ $resultBg }}">{{ $resultLabel }}</span>
                            @if($r['response_sec'] !== null)
                                <span class="text-gray-300 dark:text-gray-600">·</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $r['response_sec'] }}s</span>
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
