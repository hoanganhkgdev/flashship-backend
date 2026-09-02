<x-filament-panels::page>
    @php
        $report = $this->getReportData();
        $summary = $report['summary'];
        $options = $this->getFilterOptions();
        $maxRevenue = max(1, collect($report['trend'])->max('revenue'));
        $totalBreakdown = fn (array $rows) => max(1, collect($rows)->sum('revenue'));
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . 'đ';
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo kinh doanh</p>
            <h1 class="fs-page-header__title">Báo cáo doanh thu</h1>
            <p class="fs-page-header__description">
                Khu vực {{ filament()->getTenant()?->name }} · Doanh thu ghi nhận theo thời điểm đơn hoàn thành.
            </p>
        </div>
        <x-filament::button wire:click="exportCsv" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">
            Xuất CSV
        </x-filament::button>
    </header>

    <x-filament::section class="fs-report-filter">
        <div class="fs-filter-presets">
            @foreach (['today' => 'Hôm nay', 'last_7_days' => '7 ngày', 'this_month' => 'Tháng này', 'last_month' => 'Tháng trước'] as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['fs-filter-chip', 'fs-filter-chip--active' => $period === $key])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="fs-filter-grid">
            <label><span>Từ ngày</span><input type="date" wire:model.live="from"></label>
            <label><span>Đến ngày</span><input type="date" wire:model.live="to"></label>
            <label><span>Dịch vụ</span><select wire:model.live="serviceType"><option value="">Tất cả dịch vụ</option>@foreach($options['services'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label><span>Nguồn đơn</span><select wire:model.live="platform"><option value="">Tất cả nguồn đơn</option>@foreach($options['platforms'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label><span>Thanh toán</span><select wire:model.live="paymentMethod"><option value="">Tất cả hình thức</option>@foreach($options['payments'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        </div>
    </x-filament::section>

    <div wire:loading.class="opacity-50" class="space-y-6 transition-opacity">
        <div class="fs-report-kpis">
            @foreach ([
                ['Tổng doanh thu', $money($summary['total_revenue']), $summary['revenue_change'], 'heroicon-o-banknotes', 'orange', true],
                ['Đơn hoàn thành', number_format($summary['completed_orders']), $summary['orders_change'], 'heroicon-o-check-circle', 'green', true],
                ['Trung bình / đơn', $money($summary['avg_fee']), 'Trên mỗi đơn hoàn thành', 'heroicon-o-calculator', 'blue', false],
                ['Tỷ lệ hoàn thành', $summary['completion_rate'] . '%', number_format($summary['total_orders']) . ' đơn được tạo trong kỳ', 'heroicon-o-chart-pie', 'violet', false],
            ] as [$label, $value, $trendOrText, $icon, $tone, $hasTrend])
                <article class="fs-report-kpi fs-report-kpi--{{ $tone }}">
                    <div class="fs-report-kpi__top">
                        <span>{{ $label }}</span>
                        <span class="fs-report-kpi__icon"><x-dynamic-component :component="$icon" class="h-4 w-4" /></span>
                    </div>
                    <strong>{{ $value }}</strong>
                    @if ($hasTrend)
                        <small @class(['fs-trend', 'fs-trend--up' => $trendOrText > 0, 'fs-trend--down' => $trendOrText < 0, 'fs-trend--flat' => ! $trendOrText])>
                            @if ($trendOrText === null)
                                Kỳ trước chưa có dữ liệu
                            @else
                                @if ($trendOrText > 0)
                                    <x-heroicon-m-arrow-trending-up class="h-3.5 w-3.5" />
                                @elseif ($trendOrText < 0)
                                    <x-heroicon-m-arrow-trending-down class="h-3.5 w-3.5" />
                                @endif
                                {{ ($trendOrText >= 0 ? '+' : '') . $trendOrText . '% so với kỳ trước' }}
                            @endif
                        </small>
                    @else
                        <small>{{ $trendOrText }}</small>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="fs-report-secondary">
            <div><span>Phí vận chuyển</span><strong>{{ $money($summary['shipping_revenue']) }}</strong></div>
            <div><span>Phụ phí</span><strong>{{ $money($summary['surcharge_revenue']) }}</strong></div>
            <div><span>Giảm giá voucher</span><strong>{{ $money($summary['total_discount']) }}</strong></div>
            <div><span>Đơn đang xử lý</span><strong>{{ number_format($summary['active_orders']) }}</strong></div>
            <div><span>Đơn đã huỷ</span><strong>{{ number_format($summary['cancelled_orders']) }}</strong></div>
        </div>

        <section class="fs-report-panel">
            <div class="fs-report-panel__header"><div><h2>Xu hướng doanh thu</h2><p>{{ $report['from']->format('d/m/Y') }} – {{ $report['to']->format('d/m/Y') }}</p></div><span>Doanh thu theo ngày</span></div>
            <div class="fs-revenue-chart">
                @foreach($report['trend'] as $row)
                    <div class="fs-revenue-bar" title="{{ $row['label'] }}: {{ $money($row['revenue']) }}">
                        <span class="fs-revenue-bar__value">{{ $row['revenue'] ? number_format($row['revenue'] / 1000, 0) . 'k' : '' }}</span>
                        <i style="height: {{ max(2, round($row['revenue'] / $maxRevenue * 100)) }}%"></i>
                        <small>{{ $row['label'] }}</small>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="fs-report-breakdowns">
            @foreach ([['Dịch vụ', $report['services'], 'orange'], ['Nguồn đơn', $report['platforms'], 'blue'], ['Thanh toán', $report['payments'], 'violet']] as [$title, $rows, $tone])
                <section class="fs-report-panel fs-report-panel--{{ $tone }}">
                    <div class="fs-report-panel__header"><div><h2>{{ $title }}</h2><p>Cơ cấu doanh thu</p></div></div>
                    <div class="fs-breakdown-list">
                        @forelse($rows as $row)
                            @php $share = round($row['revenue'] / $totalBreakdown($rows) * 100, 1); @endphp
                            <div class="fs-breakdown-row">
                                <div><strong>{{ $row['label'] }}</strong><span>{{ number_format($row['total']) }} đơn</span></div>
                                <div class="fs-breakdown-row__amount"><strong>{{ $money($row['revenue']) }}</strong><span>{{ $share }}%</span></div>
                                <div class="fs-breakdown-progress"><i style="width: {{ $share }}%"></i></div>
                            </div>
                        @empty
                            <div class="fs-empty">Không có dữ liệu trong khoảng thời gian này.</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <section class="fs-report-panel">
            <div class="fs-report-panel__header"><div><h2>Chi tiết theo ngày</h2><p>Đơn hàng và doanh thu đã ghi nhận</p></div></div>
            <div class="overflow-x-auto">
                <table class="fs-report-table">
                    <thead><tr><th>Ngày</th><th>Tổng đơn</th><th>Hoàn thành</th><th>Giảm giá</th><th>Doanh thu</th></tr></thead>
                    <tbody>
                        @forelse(array_reverse($report['trend']) as $row)
                            <tr><td>{{ $row['label'] }}</td><td>{{ number_format($row['total']) }}</td><td>{{ number_format($row['completed']) }}</td><td>{{ $money($row['discount']) }}</td><td>{{ $money($row['revenue']) }}</td></tr>
                        @empty
                            <tr><td colspan="5">Không có dữ liệu</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
