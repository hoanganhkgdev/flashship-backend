<x-filament-panels::page>
    @php
        $stats = $this->stats;
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
    @endphp

    <div class="fs-cu-stats">
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Khách nhận được</span>
            <strong>{{ $num($stats['customers']) }}</strong>
            <small>khách đang hoạt động ở {{ filament()->getTenant()?->name }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Bật thông báo đẩy</span>
            <strong>{{ $num($stats['push']) }} <em>{{ $stats['pushRate'] }}%</em></strong>
            <small>còn lại chỉ thấy trong hộp thư app</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Chiến dịch 30 ngày</span>
            <strong>{{ $num($stats['campaigns']) }}</strong>
            <small>{{ $stats['scheduled'] ? $num($stats['scheduled']).' đang hẹn giờ' : 'không có chiến dịch hẹn giờ' }}</small>
        </article>
        <article class="fs-rr-kpi">
            <span class="fs-rr-kpi__label">Tỷ lệ mở trung bình</span>
            <strong>{{ $stats['openRate'] === null ? '—' : $stats['openRate'].'%' }}</strong>
            <small>trong hộp thư, 30 ngày qua</small>
        </article>
    </div>

    <form wire:submit.prevent>
        {{ $this->form }}

        <div class="fs-nt-actions">
            {{ $this->sendAction }}
            {{ $this->testAction }}
        </div>
    </form>

    <section class="fs-rr-card fs-nt-history">
        {{ $this->table }}
    </section>

    <x-filament-actions::modals />
</x-filament-panels::page>
