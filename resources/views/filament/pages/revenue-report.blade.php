<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/revenue-report.css') }}?v={{ filemtime(public_path('css/revenue-report.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/revenue-page.css') }}?v={{ filemtime(public_path('css/revenue-page.css')) }}">
    <div class="fs-revenue fs-revenue-page">
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
        $hasChartData = $summary['completed_orders'] > 0 || array_sum(array_column($chart['bars'], 'prev_revenue')) > 0;
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo kinh doanh</p>
            <h1 class="fs-page-header__title">Doanh thu</h1>
            <p class="fs-page-header__description">
                {{ filament()->getTenant()?->name }} · Tiền khách trả cho các chuyến hoàn thành và khoản công ty chi thêm cho tài xế.
            </p>
        </div>
        <div class="fs-rr-head-actions">
            <div class="fs-rr-period-badge">
                <span>Kỳ báo cáo</span>
                <b>{{ $report['from']->format('d/m/Y') }} – {{ $report['to']->format('d/m/Y') }}</b>
            </div>
            @if ($this->canExport())
            <x-filament::button wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray">
                Xuất CSV
            </x-filament::button>
            @endif
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
                <input type="date" wire:model.live.debounce.500ms="from" value="{{ $this->from }}" aria-label="Từ ngày">
                <span>→</span>
                <input type="date" wire:model.live.debounce.500ms="to" value="{{ $this->to }}" aria-label="Đến ngày">
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

    @if ($report['clamped'])
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>Khoảng thời gian quá dài — báo cáo chỉ tính <b>2 năm gần nhất</b> đến {{ $report['to']->format('d/m/Y') }}.</span>
        </div>
    @endif

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
        {{-- Bốn chỉ số: khách trả, công ty chi, tài xế nhận, đơn --}}
        @php
            $costs = $summary['costs'];
            $driverTotal = max(1, $summary['driver_total']);
            $costShare = round($summary['platform_cost'] / $driverTotal * 100, 1);
        @endphp
        <section class="fs-revenue-overview" aria-label="Tổng quan doanh thu">
            <div class="fs-rv-kpis">
                <article class="fs-rv-kpi fs-rv-kpi--hero">
                    <div class="fs-rv-kpi__head"><span>Doanh thu · khách trả</span><x-heroicon-o-banknotes /></div>
                    <strong>{{ $money($summary['total_revenue']) }}</strong>
                    @include('filament.pages.partials.delta', ['change' => $summary['revenue_change'], 'label' => $compareLabel])
                    @if ($hasChartData && $chart['spark_points'])
                        <svg class="fs-rv-spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline points="{{ $chart['spark_points'] }}" fill="none" stroke="currentColor" stroke-width="2.2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>
                    @endif
                    <small>Cước đơn hoàn thành · đã gồm phụ phí đêm, đã trừ voucher</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--cost">
                    <div class="fs-rv-kpi__head"><span>Công ty chi</span><x-heroicon-o-arrow-trending-down /></div>
                    <strong>{{ $money($summary['platform_cost']) }}</strong>
                    @include('filament.pages.partials.delta', ['change' => $summary['cost_change'], 'label' => $compareLabel])
                    <small>{{ $costShare }}% tiền tài xế nhận · bonus, bù voucher, freeship, thưởng mưa</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--driver">
                    <div class="fs-rv-kpi__head"><span>Tài xế nhận</span><x-heroicon-o-truck /></div>
                    <strong>{{ $money($summary['driver_total']) }}</strong>
                    @include('filament.pages.partials.delta', ['change' => $summary['driver_change'], 'label' => $compareLabel])
                    <small>Khách trả + công ty chi</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--orders">
                    <div class="fs-rv-kpi__head"><span>Đơn hoàn thành</span><x-heroicon-o-check-circle /></div>
                    <strong>{{ number_format($summary['completed_orders'], 0, ',', '.') }}</strong>
                    @include('filament.pages.partials.delta', ['change' => $summary['orders_change'], 'label' => $compareLabel])
                    <dl class="fs-rv-kpi__facts">
                        <div><dt>TB / đơn</dt><dd>{{ $money($summary['avg_fee']) }}</dd></div>
                        <div><dt>Tỷ lệ hoàn thành</dt><dd>{{ $summary['completion_rate'] }}%</dd></div>
                        <div><dt>Huỷ · đang xử lý</dt><dd>{{ number_format($summary['cancelled_orders'], 0, ',', '.') }} · {{ number_format($summary['active_orders'], 0, ',', '.') }}</dd></div>
                    </dl>
                </article>
            </div>
            <p class="fs-revenue-compare">Tăng/giảm so với kỳ {{ $compareLabel }} (cùng số ngày, liền trước).</p>
        </section>

        <div class="fs-rr-main-grid">
        {{-- Biểu đồ xu hướng --}}
        <section class="fs-rr-card fs-rr-chart-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Xu hướng doanh thu</h2>
                    <p>Ghi nhận theo ngày hoàn thành đơn · {{ $report['granularity_label'] }}</p>
                </div>
                @if ($hasChartData)
                <div class="fs-rr-legend">
                    <span><i class="fs-rr-legend__bar"></i>Khách trả</span>
                    @if ($chart['has_previous'])<span><i class="fs-rr-legend__dash"></i>Kỳ trước</span>@endif
                    <span><i class="fs-rr-legend__line"></i>Đơn hoàn thành <small>(thang riêng, tối đa {{ $chart['max_orders'] }})</small></span>
                </div>
                @endif
            </header>

            @if (! $hasChartData)
                <div class="fs-revenue-empty">
                    <x-heroicon-o-chart-bar />
                    <h3>Chưa có doanh thu trong kỳ</h3>
                    <p>Chọn khoảng thời gian khác hoặc điều chỉnh bộ lọc để xem báo cáo.</p>
                </div>
            @else
            <div class="fs-rr-chart">
                <div class="fs-rr-yaxis">
                    @foreach ($chart['axis'] as $tick)<span style="top: {{ 100 - $tick['pct'] }}%">{{ $tick['label'] }}</span>@endforeach
                </div>
                <div class="fs-rr-plotwrap">
                    <div class="fs-rr-plot">
                        @foreach ($chart['axis'] as $tick)<div class="fs-rr-gridline" style="top: {{ 100 - $tick['pct'] }}%"></div>@endforeach
                        <div class="fs-rr-cols">
                            @foreach ($chart['bars'] as $bar)
                                <div class="fs-rr-col" tabindex="0" role="img" aria-label="{{ $bar['label'] }}: {{ $money($bar['revenue']) }}, {{ $bar['completed'] }} đơn hoàn thành">
                                    <i class="fs-rr-bar" style="height: {{ $bar['height'] }}%"></i>
                                    <div class="fs-rr-tip">
                                        <b>{{ $bar['label'] }}</b>
                                        <span>Khách trả <strong>{{ $money($bar['revenue']) }}</strong></span>
                                        <span>Công ty chi <strong>{{ $money($bar['cost']) }}</strong></span>
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
            @endif
        </section>

        {{-- Dòng tiền: khách trả + công ty chi = tài xế nhận (khớp ví tài xế khi hoàn thành đơn) --}}
        @php
            $flow = [
                ['label' => 'Bonus tài xế', 'hint' => 'Admin thưởng thêm cho đơn', 'value' => $costs['bonus']],
                ['label' => 'Bù voucher', 'hint' => 'Phần giảm giá công ty trả thay', 'value' => $costs['discount']],
                ['label' => 'Bù freeship', 'hint' => 'Đơn tổng đài miễn phí cho khách', 'value' => $costs['freeship']],
                ['label' => 'Thưởng mưa', 'hint' => 'Đơn đủ điều kiện', 'value' => $costs['rain']],
            ];
        @endphp
        <aside class="fs-rr-card fs-rv-flow" aria-label="Dòng tiền">
            <header class="fs-rr-card__head"><div><h2>Dòng tiền</h2><p>Tiền tài xế nhận đến từ đâu</p></div></header>

            <div class="fs-rv-flow__bar" role="img" aria-label="Khách trả {{ round(100 - $costShare, 1) }}%, công ty chi {{ $costShare }}%">
                <i class="is-collected" style="width: {{ 100 - $costShare }}%"></i>
                <i class="is-cost" style="width: {{ $costShare }}%"></i>
            </div>

            <ol class="fs-rv-flow__list">
                <li class="fs-rv-flow__row is-collected">
                    <span><i></i>Khách trả</span><b>{{ $money($summary['total_revenue']) }}</b>
                    @if ($summary['night_included'] > 0)<small>gồm {{ $money($summary['night_included']) }} phụ phí đêm</small>@endif
                </li>
                @foreach ($flow as $item)
                    <li @class(['fs-rv-flow__row fs-rv-flow__row--cost', 'is-zero' => $item['value'] === 0])>
                        <span><em>+</em>{{ $item['label'] }}</span><b>{{ $money($item['value']) }}</b>
                        <small>{{ $item['hint'] }}</small>
                        <i class="fs-rv-flow__meter" style="--w: {{ $summary['platform_cost'] ? round($item['value'] / $summary['platform_cost'] * 100, 1) : 0 }}%"></i>
                    </li>
                @endforeach
                <li class="fs-rv-flow__row is-total"><span>= Tài xế nhận</span><b>{{ $money($summary['driver_total']) }}</b></li>
            </ol>

            @if ($bestRow && $bestRow['revenue'] > 0)
                <div class="fs-rr-highlight"><x-heroicon-o-sparkles /><span>Cao nhất kỳ: <b>{{ $bestRow['label'] }}</b><strong>{{ $money($bestRow['revenue']) }}</strong></span></div>
            @endif
        </aside>
        </div>

        {{-- Cơ cấu --}}
        <div class="fs-rr-split">
            @foreach ([['Dịch vụ', $report['services']], ['Nguồn đơn', $report['platforms']]] as [$title, $group])
                <section class="fs-rr-card">
                    <header class="fs-rr-card__head"><div><h2>{{ $title }}</h2><p>Tiền khách trả theo {{ mb_strtolower($title) }}</p></div></header>
                    @if (empty($group['rows']))
                        <div class="fs-empty">Không có dữ liệu trong khoảng thời gian này.</div>
                    @else
                        <div class="fs-revenue-breakdown">
                            <ul class="fs-revenue-ranks">
                                @foreach ($group['rows'] as $row)
                                    <li style="--rr-rank-color: {{ $row['color'] }}">
                                        <div class="fs-revenue-ranks__top"><span><i></i>{{ $row['label'] }}</span><b>{{ $money($row['revenue']) }}</b></div>
                                        <div class="fs-revenue-ranks__track"><i style="width: {{ $row['share'] }}%"></i></div>
                                        <div class="fs-revenue-ranks__meta"><span>{{ number_format($row['total'], 0, ',', '.') }} đơn hoàn thành</span><span>{{ $row['share'] }}%</span></div>
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
        <section class="fs-rr-card fs-revenue-details">
            <header class="fs-rr-card__head"><div><h2>Chi tiết {{ $report['granularity_label'] }}</h2><p>Bấm tiêu đề cột để sắp xếp</p></div><span class="fs-revenue-row-count">{{ count($report['rows']) }} kỳ báo cáo</span></header>
            <div class="fs-revenue-table-scroll" tabindex="0" role="region" aria-label="Bảng chi tiết doanh thu">
                <table class="fs-rr-table">
                    <thead>
                        <tr>
                            @foreach (['date' => 'Kỳ', 'total' => 'Tổng đơn', 'completed' => 'Hoàn thành', 'revenue' => 'Khách trả', 'cost' => 'Công ty chi'] as $col => $label)
                                <th scope="col" aria-sort="{{ $this->sortBy === $col ? ($this->sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}" @class(['is-sorted' => $this->sortBy === $col])><button type="button" wire:click="sort('{{ $col }}')">{{ $label }} <span>{{ $sortArrow($col) }}</span></button></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr @class(['is-weekend' => $row['weekend']])>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ number_format($row['total'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['completed'], 0, ',', '.') }}</td>
                                <td>{{ $money($row['revenue']) }}</td>
                                <td>{{ $money($row['cost']) }}</td>
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
                            <td>{{ $money($report['totals']['revenue']) }}</td>
                            <td>{{ $money($report['totals']['cost']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
    </div>
</x-filament-panels::page>
