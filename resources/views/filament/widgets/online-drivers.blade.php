<x-filament-widgets::widget>
    @php
        $ready = (int) $supply['ready'];
        $demand = max(1, (int) $pending);
        $ratio = round($ready / $demand, 1);
        $pressure = $pending === 0 ? 'Ổn định' : ($ready >= $pending ? 'Đủ lực lượng' : 'Đang thiếu xe');
        $pressureClass = $pending > $ready ? 'danger' : 'ok';
    @endphp
    <section class="fs-widget fs-supply">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Cân bằng đơn – tài xế</h2>
                <p class="fs-widget-description">Nguồn lực trực tuyến tại {{ \Filament\Facades\Filament::getTenant()?->name ?? 'tất cả khu vực' }}</p>
            </div>
            <span class="fs-supply__state is-{{ $pressureClass }}">{{ $pressure }}</span>
        </header>

        <div class="fs-supply__hero">
            <div><span>Tài xế sẵn sàng</span><strong>{{ $ready }}</strong><small>/ {{ $supply['online'] }} online</small></div>
            <i></i>
            <div><span>Đơn đang chờ</span><strong>{{ $pending }}</strong><small>Tỷ lệ cung/cầu {{ $pending ? $ratio : '—' }}</small></div>
        </div>

        <div class="fs-supply__grid">
            <div><i class="green"></i><span>Rảnh</span><b>{{ $ready }}</b></div>
            <div><i class="blue"></i><span>Đang chạy</span><b>{{ $supply['busy1'] }}</b></div>
            <div><i class="violet"></i><span>Đủ đơn</span><b>{{ $supply['busy2'] }}</b></div>
            <div><i class="red"></i><span>Mất kết nối</span><b>{{ $supply['dead'] }}</b></div>
        </div>

        <a href="{{ \App\Filament\Pages\DriverMapPage::getUrl() }}" wire:navigate class="fs-widget-link">
            Mở bản đồ điều phối <x-heroicon-o-arrow-right />
        </a>
    </section>
</x-filament-widgets::widget>
