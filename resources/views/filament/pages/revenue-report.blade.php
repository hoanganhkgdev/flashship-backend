<x-filament-panels::page>
    @php
        $report = $this->getReportData();
        $summary = $report['summary'];
        $chart = $report['chart'];
        $options = $this->getFilterOptions();
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . '₫';
        $sameYear = $report['previous_from']->year === $report['previous_to']->year && $report['previous_to']->year === $report['to']->year;
        $dateFmt = $sameYear ? 'd/m' : 'd/m/Y';
        $compareLabel = $report['previous_from']->format($dateFmt) . '–' . $report['previous_to']->format($dateFmt);
        $filterChips = array_filter([
            'serviceType' => $this->serviceType ? ($options['services'][$this->serviceType] ?? $this->serviceType) : null,
            'platform' => $this->platform ? ($options['platforms'][$this->platform] ?? $this->platform) : null,
            'paymentMethod' => $this->paymentMethod ? ($options['payments'][$this->paymentMethod] ?? $this->paymentMethod) : null,
        ]);
        $sortArrow = fn (string $col) => $this->sortBy === $col ? ($this->sortDir === 'asc' ? '↑' : '↓') : '';
        $bestRow = collect($report['rows'])->sortByDesc('revenue')->first();
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo kinh doanh</p>
            <h1 class="fs-page-header__title">Doanh thu</h1>
            <p class="fs-page-header__description">
                Khu vực {{ filament()->getTenant()?->name }} · Doanh thu ghi nhận theo thời điểm đơn hoàn thành.
            </p>
        </div>
        <div class="fs-rr-head-actions">
            <div class="fs-rr-period-badge">
                <span>Kỳ báo cáo</span>
                <b>{{ $report['from']->format('d/m/Y') }} – {{ $report['to']->format('d/m/Y') }}</b>
            </div>
            <x-filament::button wire:click="exportCsv" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">
                Xuất CSV
            </x-filament::button>
        </div>
    </header>

    {{-- Thanh lọc --}}
    <section class="fs-rr-toolbar">
        <div class="fs-rr-presets">
            @foreach ($this->getPresets() as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['fs-filter-chip', 'fs-filter-chip--active' => $period === $key])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="fs-rr-controls">
            <div class="fs-rr-dates">
                <input type="date" wire:model.live.debounce.500ms="from" aria-label="Từ ngày">
                <span>→</span>
                <input type="date" wire:model.live.debounce.500ms="to" aria-label="Đến ngày">
            </div>

            <div class="fs-rr-filter" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" class="fs-rr-filter__btn" @click="open = !open" :aria-expanded="open">
                    <x-heroicon-o-funnel class="h-4 w-4" />
                    Bộ lọc
                    @if ($this->getActiveFilterCount() > 0)
                        <b>{{ $this->getActiveFilterCount() }}</b>
                    @endif
                </button>
                <div class="fs-rr-filter__panel" x-show="open" x-transition.opacity x-cloak>
                    <label><span>Dịch vụ</span><select wire:model.live="serviceType"><option value="">Tất cả dịch vụ</option>@foreach ($options['services'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                    <label><span>Nguồn đơn</span><select wire:model.live="platform"><option value="">Tất cả nguồn đơn</option>@foreach ($options['platforms'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                    <label><span>Thanh toán</span><select wire:model.live="paymentMethod"><option value="">Tất cả hình thức</option>@foreach ($options['payments'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                    @if ($this->getActiveFilterCount() > 0)
                        <button type="button" class="fs-rr-filter__clear" wire:click="clearFilters">Xóa bộ lọc</button>
                    @endif
                </div>
            </div>
        </div>

        @if ($filterChips)
            <div class="fs-rr-chips">
                @foreach ($filterChips as $prop => $label)
                    <button type="button" wire:click="$set('{{ $prop }}', '')" class="fs-rr-chip">{{ $label }} <span aria-hidden="true">✕</span></button>
                @endforeach
            </div>
        @endif
    </section>

    @if ($report['quality']['missing_count'] > 0)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                <b>{{ number_format($report['quality']['missing_count'], 0, ',', '.') }} đơn hoàn thành thiếu thời gian hoàn thành</b>
                (khoảng {{ $money($report['quality']['missing_fee']) }}) nên chưa được tính vào báo cáo này.
            </span>
        </div>
    @endif

    <div wire:loading.class="opacity-50" class="fs-rr-body">
        {{-- Bốn chỉ số cần đọc đầu tiên --}}
        <div class="fs-rr-kpi-grid">
            <article class="fs-rr-kpi fs-rr-kpi--revenue">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-banknotes /></span>
                <div><span class="fs-rr-kpi__label">Tổng doanh thu</span><strong>{{ $money($summary['total_revenue']) }}</strong></div>
                @include('filament.pages.partials.delta', ['change' => $summary['revenue_change'], 'label' => $compareLabel])
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--orders">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-check-circle /></span>
                <div><span class="fs-rr-kpi__label">Đơn hoàn thành</span><strong>{{ number_format($summary['completed_orders'], 0, ',', '.') }}</strong></div>
                @include('filament.pages.partials.delta', ['change' => $summary['orders_change'], 'label' => $compareLabel])
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--average">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-calculator /></span>
                <div><span class="fs-rr-kpi__label">Trung bình / đơn</span><strong>{{ $money($summary['avg_fee']) }}</strong></div>
                <small>Trên mỗi đơn hoàn thành</small>
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--rate">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-chart-pie /></span>
                <div><span class="fs-rr-kpi__label">Tỷ lệ hoàn thành</span><strong>{{ $summary['completion_rate'] }}%</strong></div>
                <small>{{ number_format($summary['cancelled_orders'], 0, ',', '.') }} huỷ · {{ number_format($summary['active_orders'], 0, ',', '.') }} đang xử lý</small>
            </article>
        </div>

        <div class="fs-rr-main-grid">
        {{-- Biểu đồ xu hướng --}}
        <section class="fs-rr-card fs-rr-chart-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Xu hướng doanh thu</h2>
                    <p>{{ $report['from']->format('d/m/Y') }} – {{ $report['to']->format('d/m/Y') }} · {{ $report['granularity_label'] }}</p>
                </div>
                <div class="fs-rr-legend">
                    <span><i class="fs-rr-legend__bar"></i>Doanh thu</span>
                    @if ($chart['has_previous'])<span><i class="fs-rr-legend__dash"></i>Kỳ trước</span>@endif
                    <span><i class="fs-rr-legend__line"></i>Đơn hoàn thành <small>(thang riêng, tối đa {{ $chart['max_orders'] }})</small></span>
                </div>
            </header>

            <div class="fs-rr-chart">
                <div class="fs-rr-yaxis">
                    @foreach ($chart['axis'] as $tick)<span style="top: {{ 100 - $tick['pct'] }}%">{{ $tick['label'] }}</span>@endforeach
                </div>
                <div class="fs-rr-plotwrap">
                    <div class="fs-rr-plot">
                        @foreach ($chart['axis'] as $tick)<div class="fs-rr-gridline" style="top: {{ 100 - $tick['pct'] }}%"></div>@endforeach
                        <div class="fs-rr-cols">
                            @foreach ($chart['bars'] as $bar)
                                <div class="fs-rr-col">
                                    <i class="fs-rr-bar" style="height: {{ $bar['height'] }}%"></i>
                                    <div class="fs-rr-tip">
                                        <b>{{ $bar['label'] }}</b>
                                        <span>Doanh thu <strong>{{ $money($bar['revenue']) }}</strong></span>
                                        <span>Hoàn thành <strong>{{ number_format($bar['completed'], 0, ',', '.') }} đơn</strong></span>
                                        @if ($bar['prev_revenue'] !== null)<span>Kỳ trước <strong>{{ $money($bar['prev_revenue']) }}</strong></span>@endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <svg class="fs-rr-lines" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            @if ($chart['has_previous'] && $chart['prev_points'])
                                <polyline points="{{ $chart['prev_points'] }}" fill="none" stroke="#94a3b8" stroke-width="1.6" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                            @endif
                            @if ($chart['orders_points'])
                                <polyline points="{{ $chart['orders_points'] }}" fill="none" stroke="#38bdf8" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>
                            @endif
                        </svg>
                    </div>
                    <div class="fs-rr-xaxis">
                        @foreach ($chart['bars'] as $bar)<span>{{ $bar['show_label'] ? $bar['label'] : '' }}</span>@endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- Doanh thu hình thành --}}
        <aside class="fs-rr-card fs-rr-composition">
            <header class="fs-rr-card__head"><div><h2>Cơ cấu khoản thu</h2><p>Giá trị ghi nhận trong kỳ</p></div></header>
            <div class="fs-rr-total-mini">
                <span>Doanh thu</span><strong>{{ $money($summary['total_revenue']) }}</strong>
            </div>
            <div class="fs-rr-composition__rows">
                <div><span><i class="is-shipping"></i>Phí vận chuyển</span><b>{{ $money($summary['shipping_revenue']) }}</b></div>
                <div><span><i class="is-extra"></i>Phụ phí</span><b>{{ $money($summary['surcharge_revenue']) }}</b></div>
            </div>
            <div class="fs-rr-composition__memo">
                <div><span>Giảm giá voucher</span><b>{{ $money($summary['total_discount']) }}</b><small>Chưa trừ khỏi doanh thu</small></div>
                <div><span>Thưởng mưa</span><b>{{ $money($summary['rain_bonus']) }}</b><small>Không tính vào doanh thu</small></div>
            </div>
            @if ($bestRow && $bestRow['revenue'] > 0)
                <div class="fs-rr-highlight"><x-heroicon-o-sparkles /><span>Cao nhất kỳ: <b>{{ $bestRow['label'] }}</b><strong>{{ $money($bestRow['revenue']) }}</strong></span></div>
            @endif
        </aside>
        </div>

        {{-- Cơ cấu --}}
        <div class="fs-rr-split">
            @foreach ([['Dịch vụ', $report['services']], ['Nguồn đơn', $report['platforms']]] as [$title, $group])
                <section class="fs-rr-card">
                    <header class="fs-rr-card__head"><div><h2>{{ $title }}</h2><p>Cơ cấu doanh thu</p></div></header>
                    @if (empty($group['rows']))
                        <div class="fs-empty">Không có dữ liệu trong khoảng thời gian này.</div>
                    @else
                        <div class="fs-rr-donutwrap">
                            <div class="fs-rr-donutbox">
                                <div class="fs-rr-donut" style="background: conic-gradient({{ $group['gradient'] }})" role="img" aria-label="Cơ cấu {{ $title }}"></div>
                                <div class="fs-rr-donutbox__center"><strong>{{ number_format(array_sum(array_column($group['rows'], 'total')), 0, ',', '.') }}</strong><small>đơn</small></div>
                            </div>
                            <ul class="fs-rr-legendlist">
                                @foreach ($group['rows'] as $row)
                                    <li>
                                        <i style="background: {{ $row['color'] }}"></i>
                                        <span class="fs-rr-legendlist__name">{{ $row['label'] }}<small>{{ number_format($row['total'], 0, ',', '.') }} đơn</small></span>
                                        <span class="fs-rr-legendlist__val"><b>{{ $money($row['revenue']) }}</b><small>{{ $row['share'] }}%</small></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        @if (count($report['payments']) >= 2)
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Thanh toán</h2><p>Cơ cấu doanh thu</p></div></header>
                @php $paySum = max(1, array_sum(array_column($report['payments'], 'revenue'))); @endphp
                <ul class="fs-rr-paylist">
                    @foreach ($report['payments'] as $row)
                        @php $share = round($row['revenue'] / $paySum * 100, 1); @endphp
                        <li>
                            <div><b>{{ $row['label'] }}</b><small>{{ number_format($row['total'], 0, ',', '.') }} đơn</small></div>
                            <div class="fs-rr-paylist__bar"><i style="width: {{ $share }}%"></i></div>
                            <div class="fs-rr-paylist__val"><b>{{ $money($row['revenue']) }}</b><small>{{ $share }}%</small></div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @elseif (count($report['payments']) === 1)
            <p class="fs-rr-note">Thanh toán: 100% {{ $report['payments'][0]['label'] }} ({{ number_format($report['payments'][0]['total'], 0, ',', '.') }} đơn) — chưa có hình thức khác trong kỳ này.</p>
        @endif

        {{-- Bảng chi tiết --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Chi tiết {{ $report['granularity_label'] }}</h2><p>Bấm tiêu đề cột để sắp xếp</p></div></header>
            <div class="overflow-x-auto">
                <table class="fs-rr-table">
                    <thead>
                        <tr>
                            @foreach (['date' => 'Kỳ', 'total' => 'Tổng đơn', 'completed' => 'Hoàn thành', 'discount' => 'Giảm giá', 'revenue' => 'Doanh thu'] as $col => $label)
                                <th @class(['is-sorted' => $this->sortBy === $col])><button type="button" wire:click="sort('{{ $col }}')">{{ $label }} <span>{{ $sortArrow($col) }}</span></button></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr @class(['is-weekend' => $row['weekend']])>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ number_format($row['total'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['completed'], 0, ',', '.') }}</td>
                                <td>{{ $money($row['discount']) }}</td>
                                <td>{{ $money($row['revenue']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Không có dữ liệu</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Tổng cộng</td>
                            <td>{{ number_format($report['totals']['total'], 0, ',', '.') }}</td>
                            <td>{{ number_format($report['totals']['completed'], 0, ',', '.') }}</td>
                            <td>{{ $money($report['totals']['discount']) }}</td>
                            <td>{{ $money($report['totals']['revenue']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
