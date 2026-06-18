<x-filament-widgets::widget>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">

        {{-- Header --}}
        <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #f3f4f6;">
            <span class="font-semibold text-sm text-gray-700 dark:text-gray-200">Tài xế đang online</span>
            <div style="display:flex; align-items:center; gap:6px; background:#f0fdf4; border-radius:9999px; padding:3px 10px;">
                <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 2s infinite;display:inline-block;"></span>
                <span class="text-xs font-semibold text-green-700">{{ $this->getTotalOnline() }} / {{ $this->getTotalDrivers() }}</span>
            </div>
        </div>

        @php $drivers = $this->getDrivers(); @endphp

        @if($drivers->isEmpty())
            <div class="py-10 text-center text-sm text-gray-400">Không có tài xế nào đang online</div>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; padding:16px;">
                @foreach($drivers as $driver)
                    @php
                        $score = $driver->driver_score ?? 80;
                        $scoreColor = $score >= 80 ? '#22c55e' : ($score >= 60 ? '#3b82f6' : ($score >= 40 ? '#f59e0b' : '#ef4444'));
                        $initial = mb_strtoupper(mb_substr($driver->name, 0, 1));
                        $onlineDuration = $driver->online_since
                            ? \Carbon\Carbon::parse($driver->online_since)->diffForHumans(null, true)
                            : null;
                    @endphp
                    <div class="rounded-xl border border-gray-100 dark:border-gray-800 p-4" style="background:#fafafa;" >
                        {{-- Avatar + tên --}}
                        @php $photoUrl = $driver->profile_photo_path ? \Illuminate\Support\Facades\Storage::url($driver->profile_photo_path) : null; @endphp
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                            @if($photoUrl)
                                <img src="{{ $photoUrl }}" alt="{{ $driver->name }}"
                                     style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid {{ $scoreColor }};">
                            @else
                                <div style="width:38px;height:38px;border-radius:50%;background:{{ $scoreColor }};display:flex;align-items:center;justify-content:center;font-weight:700;color:white;font-size:15px;flex-shrink:0;line-height:1;">{{ $initial ?: '?' }}</div>
                            @endif
                            <div style="min-width:0;">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $driver->name }}</div>
                                <div class="text-xs text-gray-400">{{ $driver->phone ?? '—' }}</div>
                            </div>
                        </div>

                        {{-- Khu vực + điểm --}}
                        <div style="display:flex; align-items:center; justify-content:space-between;">
                            @if($driver->city)
                                <span style="font-size:0.7rem; font-weight:500; padding:2px 8px; border-radius:9999px; background:#eff6ff; color:#3b82f6;">
                                    {{ $driver->city->name }}
                                </span>
                            @else
                                <span class="text-xs text-gray-300">—</span>
                            @endif
                            <div style="display:flex; align-items:center; gap:4px;">
                                <span style="font-size:0.75rem; font-weight:700; color:{{ $scoreColor }};">{{ $score }}</span>
                                <span style="font-size:0.65rem; color:#9ca3af;">điểm</span>
                            </div>
                        </div>

                        {{-- Online từ --}}
                        @if($onlineDuration)
                            <div style="margin-top:8px; font-size:0.7rem; color:#9ca3af; display:flex; align-items:center; gap:4px;">
                                <svg style="width:11px;height:11px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                {{ $onlineDuration }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
