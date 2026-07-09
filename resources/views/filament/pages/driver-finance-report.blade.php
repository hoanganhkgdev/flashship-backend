<x-filament-panels::page>

@php
    $rows   = $this->rows;
    $totals = $this->totals;
    $cities = $this->cities;
@endphp

{{-- Filters --}}
<div class="flex flex-wrap items-end gap-4 mb-4">
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Khu vực</label>
        <select wire:model.live="city_id" class="rounded-lg border-gray-300 text-sm shadow-sm" style="min-width:150px">
            @foreach ($cities as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Kỳ báo cáo</label>
        <select wire:model.live="mode" class="rounded-lg border-gray-300 text-sm shadow-sm" style="min-width:120px">
            <option value="week">Theo tuần</option>
            <option value="month">Theo tháng</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Chọn ngày trong kỳ</label>
        <input type="date" wire:model.live="date" class="rounded-lg border-gray-300 text-sm shadow-sm" />
    </div>
    <div class="ml-auto">
        <button wire:click="export"
            class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            Xuất Excel
        </button>
    </div>
</div>

<p class="text-sm text-gray-500 mb-3">{{ $this->rangeLabel }}</p>

{{-- Summary cards --}}
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Tài xế có phát sinh</p>
        <p class="text-2xl font-bold text-gray-900">{{ $totals['drivers'] }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Tổng đơn</p>
        <p class="text-2xl font-bold text-gray-900">{{ number_format($totals['orders']) }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Tổng thu tài xế</p>
        <p class="text-2xl font-bold text-green-600">{{ number_format($totals['total_in']) }}đ</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Tổng chi (phí + phạt)</p>
        <p class="text-2xl font-bold text-red-600">{{ number_format($totals['total_out']) }}đ</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Còn nợ</p>
        <p class="text-2xl font-bold text-orange-600">{{ number_format($totals['remaining']) }}đ</p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border">
        <p class="text-xs text-gray-500">Thực nhận</p>
        <p class="text-2xl font-bold text-indigo-600">{{ number_format($totals['net']) }}đ</p>
    </div>
</div>

{{-- Table --}}
<div class="rounded-xl bg-white shadow-sm border overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead>
            <tr class="bg-gray-50 border-b">
                <th class="text-left px-3 py-3 font-semibold text-gray-600">#</th>
                <th class="text-left px-3 py-3 font-semibold text-gray-600">Tài xế</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Đơn</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Phí ship</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Thưởng</th>
                <th class="text-right px-3 py-3 font-semibold text-green-700">Tổng thu</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Phí tuần</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Phạt</th>
                <th class="text-right px-3 py-3 font-semibold text-red-700">Tổng chi</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Đã trả</th>
                <th class="text-right px-3 py-3 font-semibold text-orange-700">Còn nợ</th>
                <th class="text-right px-3 py-3 font-semibold text-indigo-700">Thực nhận</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="px-3 py-2.5 text-gray-400">{{ $i + 1 }}</td>
                <td class="px-3 py-2.5">
                    <p class="font-medium text-gray-900">{{ $r['name'] }}</p>
                    <p class="text-xs text-gray-400">{{ $r['phone'] }}</p>
                </td>
                <td class="px-3 py-2.5 text-right">{{ number_format($r['orders']) }}</td>
                <td class="px-3 py-2.5 text-right">{{ number_format($r['earnings']) }}</td>
                <td class="px-3 py-2.5 text-right">{{ $r['bonus'] ? number_format($r['bonus']) : '—' }}</td>
                <td class="px-3 py-2.5 text-right font-semibold text-green-700">{{ number_format($r['total_in']) }}</td>
                <td class="px-3 py-2.5 text-right">{{ $r['weekly_fee'] ? number_format($r['weekly_fee']) : '—' }}</td>
                <td class="px-3 py-2.5 text-right">{{ $r['penalty'] ? number_format($r['penalty']) : '—' }}</td>
                <td class="px-3 py-2.5 text-right font-semibold text-red-700">{{ $r['total_out'] ? number_format($r['total_out']) : '—' }}</td>
                <td class="px-3 py-2.5 text-right">{{ $r['paid'] ? number_format($r['paid']) : '—' }}</td>
                <td class="px-3 py-2.5 text-right {{ $r['remaining'] > 0 ? 'font-semibold text-orange-600' : 'text-gray-400' }}">
                    {{ $r['remaining'] ? number_format($r['remaining']) : '—' }}
                </td>
                <td class="px-3 py-2.5 text-right font-bold text-indigo-700">{{ number_format($r['net']) }}</td>
            </tr>
            @empty
            <tr><td colspan="12" class="px-4 py-8 text-center text-gray-400">Không có dữ liệu trong kỳ này.</td></tr>
            @endforelse
        </tbody>
        @if (count($rows))
        <tfoot>
            <tr class="bg-gray-50 border-t font-semibold">
                <td class="px-3 py-3" colspan="2">TỔNG CỘNG</td>
                <td class="px-3 py-3 text-right">{{ number_format($totals['orders']) }}</td>
                <td class="px-3 py-3 text-right" colspan="2"></td>
                <td class="px-3 py-3 text-right text-green-700">{{ number_format($totals['total_in']) }}</td>
                <td class="px-3 py-3 text-right" colspan="2"></td>
                <td class="px-3 py-3 text-right text-red-700">{{ number_format($totals['total_out']) }}</td>
                <td class="px-3 py-3 text-right">{{ number_format($totals['paid']) }}</td>
                <td class="px-3 py-3 text-right text-orange-700">{{ number_format($totals['remaining']) }}</td>
                <td class="px-3 py-3 text-right text-indigo-700">{{ number_format($totals['net']) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

</x-filament-panels::page>
