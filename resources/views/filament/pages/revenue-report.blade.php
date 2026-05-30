<x-filament-panels::page>

    {{-- Bộ lọc --}}
    <div class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Từ ngày</label>
            <input type="date" wire:model.live="from"
                class="rounded-lg border border-gray-300 text-sm px-3 py-2 focus:ring-2 focus:ring-primary-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Đến ngày</label>
            <input type="date" wire:model.live="to"
                class="rounded-lg border border-gray-300 text-sm px-3 py-2 focus:ring-2 focus:ring-primary-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Khu vực</label>
            <select wire:model.live="city_id"
                class="rounded-lg border border-gray-300 text-sm px-3 py-2 focus:ring-2 focus:ring-primary-500">
                <option value="">Tất cả khu vực</option>
                @foreach($this->getCities() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @php $summary = $this->getSummary(); @endphp

    {{-- Tổng quan --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Tổng đơn</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($summary['total_orders']) }}</div>
        </div>
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Hoàn thành</div>
            <div class="text-2xl font-bold text-green-600">{{ number_format($summary['completed_orders']) }}</div>
            <div class="text-xs text-gray-400">
                {{ $summary['total_orders'] > 0 ? round($summary['completed_orders'] / $summary['total_orders'] * 100) : 0 }}%
            </div>
        </div>
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Đã huỷ</div>
            <div class="text-2xl font-bold text-red-500">{{ number_format($summary['cancelled_orders']) }}</div>
        </div>
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Doanh thu</div>
            <div class="text-2xl font-bold text-orange-500">{{ number_format($summary['total_revenue'], 0, ',', '.') }}đ</div>
        </div>
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <div class="text-xs text-gray-500 mb-1">Phí trung bình</div>
            <div class="text-2xl font-bold text-blue-500">{{ number_format($summary['avg_fee'], 0, ',', '.') }}đ</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Theo dịch vụ --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">Theo dịch vụ</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500">
                    <tr>
                        <th class="px-4 py-2 text-left">Dịch vụ</th>
                        <th class="px-4 py-2 text-center">Tổng</th>
                        <th class="px-4 py-2 text-center">Hoàn thành</th>
                        <th class="px-4 py-2 text-center">Tỉ lệ</th>
                        <th class="px-4 py-2 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->getByService() as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-2 font-medium">{{ $row['label'] }}</td>
                        <td class="px-4 py-2 text-center">{{ $row['total'] }}</td>
                        <td class="px-4 py-2 text-center text-green-600">{{ $row['completed'] }}</td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $row['rate'] >= 80 ? 'bg-green-100 text-green-700' : ($row['rate'] >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                {{ $row['rate'] }}%
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @endforeach
                    @if(empty($this->getByService()))
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Không có dữ liệu</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Theo khu vực --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">Theo khu vực</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500">
                    <tr>
                        <th class="px-4 py-2 text-left">Khu vực</th>
                        <th class="px-4 py-2 text-center">Tổng</th>
                        <th class="px-4 py-2 text-center">Hoàn thành</th>
                        <th class="px-4 py-2 text-center">Tỉ lệ</th>
                        <th class="px-4 py-2 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->getByCity() as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-2 font-medium">{{ $row['city'] }}</td>
                        <td class="px-4 py-2 text-center">{{ $row['total'] }}</td>
                        <td class="px-4 py-2 text-center text-green-600">{{ $row['completed'] }}</td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $row['rate'] >= 80 ? 'bg-green-100 text-green-700' : ($row['rate'] >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                {{ $row['rate'] }}%
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @endforeach
                    @if(empty($this->getByCity()))
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Không có dữ liệu</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Theo ngày --}}
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-hidden lg:col-span-2">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 font-semibold text-sm">Theo ngày</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500">
                    <tr>
                        <th class="px-4 py-2 text-left">Ngày</th>
                        <th class="px-4 py-2 text-center">Tổng đơn</th>
                        <th class="px-4 py-2 text-right">Doanh thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->getByDay() as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-2 font-medium">{{ $row['day'] }}</td>
                        <td class="px-4 py-2 text-center">{{ $row['total'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $row['revenue'] }}đ</td>
                    </tr>
                    @endforeach
                    @if(empty($this->getByDay()))
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">Không có dữ liệu</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

    </div>

</x-filament-panels::page>
