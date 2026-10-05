<x-filament-widgets::widget>
    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">{{ $title }}</h2>
                <p class="fs-widget-description">{{ $description }}</p>
            </div>
            <span class="fs-count-chip">{{ number_format($total, 0, ',', '.') }} đơn</span>
        </header>

        @if (empty($rows))
            <div class="fs-empty"><div>Chưa có đơn nào hôm nay</div></div>
        @else
            <ul class="fs-bars">
                @foreach ($rows as $row)
                    <li class="fs-bar">
                        <div class="fs-bar__top">
                            <span class="fs-bar__label">{{ $row['label'] }}</span>
                            <span class="fs-bar__value">
                                <strong>{{ number_format($row['count'], 0, ',', '.') }}</strong>
                                <small>{{ $row['percent'] }}%</small>
                            </span>
                        </div>
                        <div class="fs-bar__track"><i style="width: {{ max($row['percent'], 2) }}%"></i></div>
                        @isset($row['note'])
                            <span class="fs-bar__note">{{ $row['note'] }}</span>
                        @endisset
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
