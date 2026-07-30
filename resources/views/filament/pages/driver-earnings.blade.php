<x-filament-panels::page>

@php
    $drivers = $this->drivers;
    $totals  = $this->totals;
@endphp

{{-- Filters --}}
<div class="flex flex-wrap items-end gap-4 mb-4">
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Ngày</label>
        <input type="date" wire:model.live="date" class="rounded-lg border-gray-300 text-sm shadow-sm" />
    </div>
</div>

{{-- Summary cards --}}
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Tài xế</p>
        <p class="text-2xl font-bold text-gray-900">{{ $totals['drivers'] }}</p>
        <p class="text-xs text-green-600">{{ $totals['online'] }} online</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Đơn hôm nay</p>
        <p class="text-2xl font-bold text-gray-900">{{ $totals['today_orders'] }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Thu nhập hôm nay</p>
        <p class="text-2xl font-bold text-orange-600">{{ number_format($totals['today_earnings']) }}đ</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Đơn tuần này</p>
        <p class="text-2xl font-bold text-gray-900">{{ $totals['week_orders'] }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Thu nhập tuần</p>
        <p class="text-2xl font-bold text-indigo-600">{{ number_format($totals['week_earnings']) }}đ</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">TB/tài xế (tuần)</p>
        <p class="text-2xl font-bold text-gray-900">{{ $totals['drivers'] > 0 ? number_format($totals['week_earnings'] / $totals['drivers']) : 0 }}đ</p>
    </div>
</div>

{{-- Table --}}
<div class="rounded-xl bg-white shadow-sm border overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b">
                <th class="text-left px-4 py-3 font-semibold text-gray-600">#</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600">Tài xế</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600">SĐT</th>
                <th class="text-center px-4 py-3 font-semibold text-gray-600">Online</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600">Giờ online</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600">Đơn ngày</th>
                <th class="text-right px-4 py-3 font-semibold text-orange-600">Thu nhập ngày</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600">Đơn tuần</th>
                <th class="text-right px-4 py-3 font-semibold text-indigo-600">Thu nhập tuần</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($drivers as $i => $d)
            <tr class="border-b hover:bg-gray-50 transition-colors">
                <td class="px-4 py-2.5 text-gray-400">{{ $i + 1 }}</td>
                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $d['name'] }}</td>
                <td class="px-4 py-2.5 text-gray-500">{{ $d['phone'] }}</td>
                <td class="px-4 py-2.5 text-center">
                    @if ($d['is_online'])
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500"></span>
                    @else
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-300"></span>
                    @endif
                </td>
                <td class="px-4 py-2.5 text-right text-gray-600">{{ $d['online_hours'] }}h</td>
                <td class="px-4 py-2.5 text-right font-medium">{{ $d['today_orders'] }}</td>
                <td class="px-4 py-2.5 text-right font-bold text-orange-600">{{ number_format($d['today_earnings']) }}đ</td>
                <td class="px-4 py-2.5 text-right font-medium">{{ $d['week_orders'] }}</td>
                <td class="px-4 py-2.5 text-right font-bold text-indigo-600">{{ number_format($d['week_earnings']) }}đ</td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-4 py-8 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

</x-filament-panels::page>
