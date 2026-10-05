<x-filament-widgets::widget>
    @php $drivers = $this->getDrivers(); @endphp
    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Top tài xế hôm nay</h2>
                <p class="fs-widget-description">Theo số đơn đã hoàn thành</p>
            </div>
        </header>

        @if ($drivers->isEmpty())
            <div class="fs-empty"><div>Chưa có đơn hoàn thành hôm nay</div></div>
        @else
            <ol class="fs-rank">
                @foreach ($drivers as $i => $d)
                    <li>
                        <a href="{{ $d['url'] }}" wire:navigate class="fs-rank__row">
                            <span class="fs-rank__no fs-rank__no--{{ $i + 1 }}">{{ $i + 1 }}</span>
                            <span class="fs-rank__name">{{ $d['name'] }}</span>
                            <span class="fs-rank__stat"><b>{{ $d['orders'] }} đơn</b><small>{{ $d['fee'] }}</small></span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</x-filament-widgets::widget>
