<x-filament-panels::page>

@php
    $summary   = $this->getSummary();
    $rate      = $summary['total_orders'] > 0 ? round($summary['completed_orders'] / $summary['total_orders'] * 100) : 0;
    $services  = $this->getByService();
    $cities    = $this->getByCity();
    $days      = $this->getByDay();

    $statCards = [
        ['label' => 'Tổng đơn',      'value' => number_format($summary['total_orders']),                    'icon' => 'heroicon-o-clipboard-document-list', 'color' => '#6366f1', 'sub' => null],
        ['label' => 'Hoàn thành',     'value' => number_format($summary['completed_orders']),                'icon' => 'heroicon-o-check-circle',            'color' => '#22c55e', 'sub' => $rate . '%'],
        ['label' => 'Đã huỷ',        'value' => number_format($summary['cancelled_orders']),                'icon' => 'heroicon-o-x-circle',                'color' => '#ef4444', 'sub' => null],
        ['label' => 'Doanh thu',      'value' => number_format($summary['total_revenue'], 0, ',', '.') . 'đ', 'icon' => 'heroicon-o-banknotes',             'color' => '#f97316', 'sub' => null],
        ['label' => 'TB / đơn',      'value' => number_format($summary['avg_fee'], 0, ',', '.') . 'đ',      'icon' => 'heroicon-o-calculator',              'color' => '#3b82f6', 'sub' => null],
    ];
@endphp

{{-- ══ BỘ LỌC ══════════════════════════════════════════════════════════════ --}}
<div class="mb-6 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[140px] max-w-[180px]">
        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-gray-400">Từ ngày</label>
        <input type="date" wire:model.live="from"
            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900">
    </div>
    <div class="flex-1 min-w-[140px] max-w-[180px]">
        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-gray-400">Đến ngày</label>
        <input type="date" wire:model.live="to"
            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900">
    </div>
    <div class="flex-1 min-w-[160px] max-w-[200px]">
        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-gray-400">Khu vực</label>
        <select wire:model.live="city_id"
            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900">
            <option value="">Tất cả khu vực</option>
            @foreach($this->getCities() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
</div>

{{-- ══ TỔNG QUAN ════════════════════════════════════════════════════════════ --}}
<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    @foreach($statCards as $card)
    <div class="relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-xl font-extrabold" style="color:{{ $card['color'] }}">{{ $card['value'] }}</p>
                @if($card['sub'])
                    <p class="mt-0.5 text-xs font-semibold text-gray-400">{{ $card['sub'] }}</p>
                @endif
            </div>
            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl" style="background:{{ $card['color'] }}12">
                <x-dynamic-component :component="$card['icon']" class="h-5 w-5" style="color:{{ $card['color'] }}" />
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ══ BẢNG PHÂN TÍCH ══════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

    {{-- Theo dịch vụ --}}
    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <x-heroicon-o-squares-2x2 class="h-4 w-4 text-gray-400" />
            <span class="text-sm font-bold text-gray-700 dark:text-gray-200">Theo dịch vụ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-50 bg-gray-50/50 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:border-gray-800 dark:bg-gray-800/50">
                        <th class="px-5 py-2.5 text-left">Dịch vụ</th>
                        <th class="px-3 py-2.5 text-center">Đơn</th>
                        <th class="px-3 py-2.5 text-center">OK</th>
                        <th class="px-3 py-2.5 text-center">Tỉ lệ</th>
                        <th class="px-5 py-2.5 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @forelse($services as $row)
                    <tr class="transition hover:bg-gray-50/60 dark:hover:bg-gray-800/40">
                        <td class="px-5 py-2.5 font-semibold text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                        <td class="px-3 py-2.5 text-center text-gray-500">{{ $row['total'] }}</td>
                        <td class="px-3 py-2.5 text-center font-semibold text-green-600">{{ $row['completed'] }}</td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold
                                {{ $row['rate'] >= 80 ? 'bg-green-50 text-green-600' : ($row['rate'] >= 50 ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-600') }}">
                                {{ $row['rate'] }}%
                            </span>
                        </td>
                        <td class="px-5 py-2.5 text-right font-bold text-gray-800 dark:text-gray-100">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-300">Không có dữ liệu</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Theo khu vực --}}
    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <x-heroicon-o-map class="h-4 w-4 text-gray-400" />
            <span class="text-sm font-bold text-gray-700 dark:text-gray-200">Theo khu vực</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-50 bg-gray-50/50 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:border-gray-800 dark:bg-gray-800/50">
                        <th class="px-5 py-2.5 text-left">Khu vực</th>
                        <th class="px-3 py-2.5 text-center">Đơn</th>
                        <th class="px-3 py-2.5 text-center">OK</th>
                        <th class="px-3 py-2.5 text-center">Tỉ lệ</th>
                        <th class="px-5 py-2.5 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @forelse($cities as $row)
                    <tr class="transition hover:bg-gray-50/60 dark:hover:bg-gray-800/40">
                        <td class="px-5 py-2.5 font-semibold text-gray-700 dark:text-gray-200">{{ $row['city'] }}</td>
                        <td class="px-3 py-2.5 text-center text-gray-500">{{ $row['total'] }}</td>
                        <td class="px-3 py-2.5 text-center font-semibold text-green-600">{{ $row['completed'] }}</td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold
                                {{ $row['rate'] >= 80 ? 'bg-green-50 text-green-600' : ($row['rate'] >= 50 ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-600') }}">
                                {{ $row['rate'] }}%
                            </span>
                        </td>
                        <td class="px-5 py-2.5 text-right font-bold text-gray-800 dark:text-gray-100">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-300">Không có dữ liệu</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Theo ngày --}}
    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm lg:col-span-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <x-heroicon-o-calendar-days class="h-4 w-4 text-gray-400" />
            <span class="text-sm font-bold text-gray-700 dark:text-gray-200">Theo ngày</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-50 bg-gray-50/50 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:border-gray-800 dark:bg-gray-800/50">
                        <th class="px-5 py-2.5 text-left">Ngày</th>
                        <th class="px-3 py-2.5 text-center">Tổng đơn</th>
                        <th class="px-5 py-2.5 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @forelse($days as $row)
                    <tr class="transition hover:bg-gray-50/60 dark:hover:bg-gray-800/40">
                        <td class="px-5 py-2.5 font-semibold text-gray-700 dark:text-gray-200">{{ $row['day'] }}</td>
                        <td class="px-3 py-2.5 text-center text-gray-500">{{ $row['total'] }}</td>
                        <td class="px-5 py-2.5 text-right font-bold text-gray-800 dark:text-gray-100">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-5 py-10 text-center text-gray-300">Không có dữ liệu</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

</x-filament-panels::page>
