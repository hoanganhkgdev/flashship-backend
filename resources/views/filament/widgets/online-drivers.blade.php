<x-filament-widgets::widget>
    @php $drivers = $this->getDrivers(); @endphp

    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Tài xế đang online</h2>
                <p class="fs-widget-description">Nhấn vào tài xế để xem chi tiết điểm hoạt động</p>
            </div>
            <span class="fs-status-pill">
                <span class="fs-status-dot"></span>
                {{ $this->getTotalOnline() }} / {{ $this->getTotalDrivers() }} sẵn sàng
            </span>
        </header>

        @if ($drivers->isEmpty())
            <div class="fs-empty">
                <div>
                    <x-heroicon-o-truck class="mx-auto mb-2 h-7 w-7" />
                    Chưa có tài xế online trong khu vực này
                </div>
            </div>
        @else
            <div class="fs-driver-grid">
                @foreach ($drivers as $driver)
                    @php
                        $score = $driver->driver_score ?? 80;
                        $scoreColor = $score >= 80 ? '#22c55e' : ($score >= 60 ? '#3b82f6' : ($score >= 40 ? '#f59e0b' : '#ef4444'));
                        $initial = mb_strtoupper(mb_substr($driver->name, 0, 1));
                        $photoUrl = $driver->profile_photo_path ? \Illuminate\Support\Facades\Storage::url($driver->profile_photo_path) : null;
                        $scoreUrl = \App\Filament\Resources\DriverScoreResource::getUrl('view', ['record' => $driver->id]);
                    @endphp

                    <a
                        href="{{ $scoreUrl }}"
                        wire:navigate
                        class="fs-driver-card"
                        style="--driver-color: {{ $scoreColor }}"
                    >
                        <div class="fs-driver-main">
                            @if ($photoUrl)
                                <img class="fs-driver-avatar" src="{{ $photoUrl }}" alt="{{ $driver->name }}">
                            @else
                                <span class="fs-driver-avatar">{{ $initial ?: '?' }}</span>
                            @endif

                            <div class="min-w-0">
                                <p class="fs-driver-name">{{ $driver->name }}</p>
                                <p class="fs-driver-phone">{{ $driver->phone ?? '—' }}</p>
                            </div>
                        </div>

                        <div class="fs-driver-meta">
                            @if ($driver->city)
                                <span class="fs-city-badge">{{ $driver->city->name }}</span>
                            @else
                                <span class="text-xs text-gray-300">—</span>
                            @endif
                            <span class="fs-score">{{ $score }} <span class="font-normal text-gray-400">điểm</span></span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
