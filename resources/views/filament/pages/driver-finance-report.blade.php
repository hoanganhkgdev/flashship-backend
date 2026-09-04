<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $totals = $this->totals;
        $money = fn ($value) => number_format((float) $value, 0, ',', '.').' ₫';
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo tài chính</p>
            <h1 class="fs-page-header__title">Thu chi tài xế</h1>
            <p class="fs-page-header__description">Đối soát khoản phải thu, số đã thu, tiền admin chi và tiền tài xế đã rút theo kỳ.</p>
        </div>
    </header>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500">Tổng phải thu</p><p class="mt-1 text-2xl text-gray-950 dark:text-white">{{ $money($totals['total_due']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Phí tuần {{ $money($totals['fee_due']) }} · Phạt {{ $money($totals['penalty']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500">Đã thu</p><p class="mt-1 text-2xl text-green-600">{{ $money($totals['total_paid']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Từ {{ $totals['drivers'] }} tài xế có phát sinh</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500">Còn phải thu</p><p class="mt-1 text-2xl text-orange-600">{{ $money($totals['remaining']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Công nợ chưa hoàn tất trong kỳ</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500">Admin đã chi</p><p class="mt-1 text-2xl text-purple-600">{{ $money($totals['admin_paid']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Thưởng {{ $money($totals['bonus']) }} · Bù mã {{ $money($totals['voucher']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500">Tài xế đã rút</p><p class="mt-1 text-2xl text-primary-600">{{ $money($totals['withdrawn']) }}</p>
            <p class="mt-1 text-sm text-gray-500">Yêu cầu đã duyệt trong kỳ</p>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div><label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Kỳ báo cáo</label><select wire:model.live="mode" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"><option value="week">Theo tuần</option><option value="month">Theo tháng</option></select></div>
        <div><label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">{{ $this->mode === 'month' ? 'Chọn tháng' : 'Chọn tuần' }}</label><select wire:model.live="date" class="min-w-56 rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">@foreach ($this->periods as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="min-w-52 flex-1"><label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Tìm tài xế</label><input type="search" wire:model.live.debounce.300ms="search" placeholder="Tên hoặc số điện thoại" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900" /></div>
        <div><label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Công nợ</label><select wire:model.live="debtStatus" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"><option value="all">Tất cả</option><option value="outstanding">Còn nợ</option><option value="settled">Đã hoàn tất</option></select></div>
        <div><label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Sắp xếp</label><select wire:model.live="sortBy" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"><option value="remaining">Còn phải thu</option><option value="paid">Đã thu</option><option value="admin_paid">Admin đã chi</option><option value="withdrawn">Đã rút</option></select></div>
        <x-filament::button wire:click="export" icon="heroicon-o-arrow-down-tray" color="success">Xuất Excel</x-filament::button>
    </div>

    <p class="text-sm text-gray-500">Đang xem: {{ $this->rangeLabel }}</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[1000px] text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"><tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">Tài xế</th><th class="px-4 py-3 text-left">Khoản phải thu</th><th class="px-4 py-3 text-left">Đã thu / Còn nợ</th><th class="px-4 py-3 text-left">Admin đã chi</th><th class="px-4 py-3 text-right">Tài xế đã rút</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $index => $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 text-gray-400">{{ $index + 1 }}</td>
                        <td class="px-4 py-3"><div class="text-gray-950 dark:text-white">{{ $row['name'] }}</div><div class="mt-1 text-gray-500">{{ $row['phone'] ?: 'Chưa có SĐT' }} · TX #{{ $row['id'] }}</div></td>
                        <td class="px-4 py-3"><div class="text-gray-950 dark:text-white">{{ $money($row['total_due']) }}</div><div class="mt-1 text-gray-500">Phí tuần {{ $money($row['fee_due']) }} · Phạt {{ $money($row['penalty']) }}</div></td>
                        <td class="px-4 py-3"><div class="text-green-600">Đã thu: {{ $money($row['total_paid']) }}</div><div class="mt-1 {{ $row['remaining'] > 0 ? 'text-orange-600' : 'text-gray-500' }}">Còn nợ: {{ $money($row['remaining']) }}</div></td>
                        <td class="px-4 py-3"><div class="text-purple-600">{{ $money($row['admin_paid']) }}</div><div class="mt-1 text-gray-500">Thưởng {{ $money($row['bonus']) }} · Bù mã {{ $money($row['voucher']) }}</div></td>
                        <td class="px-4 py-3 text-right text-primary-600">{{ $money($row['withdrawn']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">Không có dữ liệu phù hợp trong kỳ này.</td></tr>
                @endforelse
            </tbody>
            @if (count($rows))
                <tfoot class="border-t border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5"><tr><td colspan="2" class="px-4 py-3">Tổng cộng</td><td class="px-4 py-3">{{ $money($totals['total_due']) }}</td><td class="px-4 py-3"><span class="text-green-600">{{ $money($totals['total_paid']) }}</span> · <span class="text-orange-600">còn {{ $money($totals['remaining']) }}</span></td><td class="px-4 py-3 text-purple-600">{{ $money($totals['admin_paid']) }}</td><td class="px-4 py-3 text-right text-primary-600">{{ $money($totals['withdrawn']) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
