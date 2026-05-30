<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-3">
                <span>Tài xế đang online</span>
                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse"></span>
                    {{ $this->getTotalOnline() }} / {{ $this->getTotalDrivers() }}
                </span>
            </div>
        </x-slot>

        @php $drivers = $this->getDrivers(); @endphp

        @if($drivers->isEmpty())
            <div class="py-8 text-center text-sm text-gray-400">
                Hiện không có tài xế nào đang online
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800 text-xs text-gray-500">
                            <th class="pb-2 text-left font-medium">Tài xế</th>
                            <th class="pb-2 text-left font-medium">Số điện thoại</th>
                            <th class="pb-2 text-center font-medium">Khu vực</th>
                            <th class="pb-2 text-center font-medium">Điểm</th>
                            <th class="pb-2 text-right font-medium">Online từ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800/50">
                        @foreach($drivers as $driver)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30">
                            <td class="py-2 pr-4">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-green-400 flex-shrink-0"></span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $driver->name }}</span>
                                </div>
                            </td>
                            <td class="py-2 pr-4 text-gray-500">{{ $driver->phone ?? '—' }}</td>
                            <td class="py-2 pr-4 text-center">
                                @if($driver->city)
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                                        {{ $driver->city->name }}
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-center">
                                @php $score = $driver->driver_score ?? 80; @endphp
                                <span class="font-semibold {{ $score >= 80 ? 'text-green-600' : ($score >= 60 ? 'text-blue-600' : ($score >= 40 ? 'text-yellow-600' : 'text-red-500')) }}">
                                    {{ $score }}
                                </span>
                            </td>
                            <td class="py-2 text-right text-gray-400 text-xs">
                                @if($driver->online_since)
                                    {{ \Carbon\Carbon::parse($driver->online_since)->diffForHumans(null, true) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
