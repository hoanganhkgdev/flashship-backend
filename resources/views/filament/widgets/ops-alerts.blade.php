<x-filament-widgets::widget>
    <section class="fs-widget">
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Cần xử lý ngay</h2>
                <p class="fs-widget-description">Những việc đang chờ người vận hành</p>
            </div>
            @if ($needsAttention === 0)
                <span class="fs-status-pill"><span class="fs-status-dot"></span>Mọi thứ ổn</span>
            @else
                <span class="fs-alert-total">{{ $needsAttention }} mục cần chú ý</span>
            @endif
        </header>

        <div class="fs-alert-grid">
            @foreach ($alerts as $alert)
                <a href="{{ $alert['url'] }}" wire:navigate class="fs-alert fs-alert--{{ $alert['level'] }}">
                    <span class="fs-alert__icon"><x-dynamic-component :component="$alert['icon']" class="h-5 w-5" /></span>
                    <span class="fs-alert__body">
                        <span class="fs-alert__label">{{ $alert['label'] }}</span>
                        <span class="fs-alert__detail">{{ $alert['detail'] }}</span>
                    </span>
                    <strong class="fs-alert__count">{{ number_format($alert['count'], 0, ',', '.') }}</strong>
                </a>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
