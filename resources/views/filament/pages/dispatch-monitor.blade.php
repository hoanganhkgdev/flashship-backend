<x-filament-panels::page>

    <div wire:poll.5s>

    @php $stats = $this->getTodayStats(); @endphp

    {{-- Thống kê hôm nay --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Tổng đơn phát</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Đã nhận</div>
            <div class="text-2xl font-bold text-green-600">{{ number_format($stats['accepted']) }}</div>
            <div class="text-xs text-gray-400">{{ $stats['accept_rate'] }}%</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Nhận lần đầu</div>
            <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['first_try']) }}</div>
            <div class="text-xs text-gray-400">{{ $stats['first_try_rate'] }}% trong số đã nhận</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Số lần thử TB</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['avg_attempts'] }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">TG chờ TB tới khi nhận</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['avg_wait_secs'] }}s</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Không tìm được tài xế</div>
            <div class="text-2xl font-bold text-red-500">{{ number_format($stats['no_driver']) }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm flex items-center">
            <div class="text-xs text-gray-400">Tự động làm mới mỗi 5s</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Đơn đang phát --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">
                Đơn đang chờ tài xế nhận
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Đơn</th>
                            <th class="px-3 py-2 text-left">Dịch vụ</th>
                            <th class="px-3 py-2 text-left">Khu vực</th>
                            <th class="px-3 py-2 text-right">Đã chờ</th>
                            <th class="px-3 py-2 text-right">Lần thử</th>
                            <th class="px-3 py-2 text-right">Bán kính</th>
                            <th class="px-3 py-2 text-left">Đang gửi tới</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($this->getActiveOrders() as $o)
                            <tr class="{{ $o['elapsed'] > 300 ? 'bg-red-50 dark:bg-red-950/30' : ($o['elapsed'] > 120 ? 'bg-yellow-50 dark:bg-yellow-950/20' : '') }}">
                                <td class="px-3 py-2 font-medium">#{{ $o['id'] }}</td>
                                <td class="px-3 py-2">{{ $o['service_type'] }}</td>
                                <td class="px-3 py-2">{{ $o['city'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $o['elapsed'] }}s</td>
                                <td class="px-3 py-2 text-right">{{ $o['attempts'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $o['radius'] }}km</td>
                                <td class="px-3 py-2">{{ $o['offering_to'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-6 text-center text-gray-400">Không có đơn nào đang phát</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Lịch sử offer gần đây --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">
                Lịch sử offer gần đây
            </div>
            <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left">Đơn</th>
                            <th class="px-3 py-2 text-left">Tài xế</th>
                            <th class="px-3 py-2 text-left">Lúc</th>
                            <th class="px-3 py-2 text-left">Kết quả</th>
                            <th class="px-3 py-2 text-right">Phản hồi sau</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($this->getRecentOffers() as $r)
                            <tr>
                                <td class="px-3 py-2 font-medium">#{{ $r['order_id'] }}</td>
                                <td class="px-3 py-2">{{ $r['driver_name'] }}</td>
                                <td class="px-3 py-2 text-gray-400">{{ $r['offered_at'] }}</td>
                                <td class="px-3 py-2">
                                    @if($r['result'] === 'accepted')
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Nhận</span>
                                    @elseif($r['result'] === 'declined')
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700">Từ chối</span>
                                    @elseif($r['result'] === 'expired')
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Hết hạn</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-700">Đang chờ</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right text-gray-400">
                                    {{ $r['response_sec'] !== null ? $r['response_sec'].'s' : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-gray-400">Chưa có dữ liệu</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    </div>

</x-filament-panels::page>
