<x-filament-panels::page>
    @php
        $drivers = $this->drivers;
        $totals = $this->totals;
        $money = fn ($value) => number_format((float) $value, 0, ',', '.').' ₫';
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Hiệu suất tài xế</p>
            <h1 class="fs-page-header__title">Thu nhập tài xế</h1>
            <p class="fs-page-header__description">Đối soát số đơn, phí giao hàng và phụ phí theo ngày được chọn và tuần tương ứng.</p>
        </div>
    </header>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Tài xế có thu nhập</p>
            <p class="mt-1 text-2xl text-gray-950 dark:text-white">{{ number_format($totals['earning_drivers']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $totals['online'] }} online · {{ $totals['drivers'] }} đang hiển thị</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Đơn ngày {{ $this->dateLabel }}</p>
            <p class="mt-1 text-2xl text-gray-950 dark:text-white">{{ number_format($totals['today_orders']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Đơn đã hoàn thành</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Thu nhập ngày {{ $this->dateLabel }}</p>
            <p class="mt-1 text-2xl text-orange-600">{{ $money($totals['today_earnings']) }}</p>
            <p class="mt-1 text-sm text-gray-500">TB {{ $money($totals['today_orders'] ? $totals['today_earnings'] / $totals['today_orders'] : 0) }}/đơn</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Thu nhập tuần</p>
            <p class="mt-1 text-2xl text-primary-600">{{ $money($totals['week_earnings']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $totals['week_orders'] }} đơn · {{ $this->weekLabel }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div>
            <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Ngày đối soát</label>
            <input type="date" wire:model.live="date" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900" />
        </div>
        <div class="min-w-52 flex-1">
            <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Tìm tài xế</label>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tên hoặc số điện thoại" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900" />
        </div>
        <div>
            <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Trạng thái</label>
            <select wire:model.live="onlineStatus" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">
                <option value="all">Tất cả</option><option value="online">Đang online</option><option value="offline">Offline</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Hoạt động trong ngày</label>
            <select wire:model.live="activity" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">
                <option value="all">Tất cả</option><option value="has_orders">Có đơn hoàn thành</option><option value="no_orders">Chưa có đơn</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Sắp xếp</label>
            <select wire:model.live="sortBy" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">
                <option value="day_earnings">Thu nhập ngày</option><option value="week_earnings">Thu nhập tuần</option>
            </select>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[900px] text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">Tài xế</th>
                    <th class="px-4 py-3 text-left">Ngày {{ $this->dateLabel }}</th><th class="px-4 py-3 text-right">Thu nhập ngày</th>
                    <th class="px-4 py-3 text-left">Tuần {{ $this->weekLabel }}</th><th class="px-4 py-3 text-right">Thu nhập tuần</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($drivers as $index => $driver)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 text-gray-400">{{ $index + 1 }}</td>
                        <td class="px-4 py-3"><div class="text-gray-950 dark:text-white">{{ $driver['name'] }}</div><div class="mt-1 text-gray-500">{{ $driver['phone'] ?: 'Chưa có SĐT' }} · {{ $driver['is_online'] ? 'Đang online' : 'Offline' }}</div></td>
                        <td class="px-4 py-3"><div class="text-gray-950 dark:text-white">{{ $driver['today_orders'] }} đơn hoàn thành</div><div class="mt-1 text-gray-500">Phí giao {{ $money($driver['today_shipping']) }} · Phụ phí {{ $money($driver['today_bonus']) }}</div></td>
                        <td class="px-4 py-3 text-right text-orange-600">{{ $money($driver['today_earnings']) }}</td>
                        <td class="px-4 py-3"><div class="text-gray-950 dark:text-white">{{ $driver['week_orders'] }} đơn hoàn thành</div><div class="mt-1 text-gray-500">Phí giao {{ $money($driver['week_shipping']) }} · Phụ phí {{ $money($driver['week_bonus']) }}</div></td>
                        <td class="px-4 py-3 text-right text-primary-600">{{ $money($driver['week_earnings']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">Không có tài xế phù hợp với bộ lọc.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
