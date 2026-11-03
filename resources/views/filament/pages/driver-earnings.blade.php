<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/revenue-report.css') }}?v={{ filemtime(public_path('css/revenue-report.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/driver-earnings.css') }}?v={{ filemtime(public_path('css/driver-earnings.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/revenue-page.css') }}?v={{ filemtime(public_path('css/revenue-page.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/driver-earnings-page.css') }}?v={{ filemtime(public_path('css/driver-earnings-page.css')) }}">
    {{-- fs-revenue-page: dùng chung thẻ KPI với trang Doanh thu; fs-de-page: bố cục riêng của trang này (Thu phí tài xế dùng chung fs-driver-earnings nên không đụng). --}}
    <div class="fs-revenue fs-driver-earnings fs-revenue-page fs-de-page">
    @php
        $report = $this->getReportData();
        $totals = $report['totals'];
        $chart = $report['chart'];
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . '₫';
        $sameYear = $report['previous_from']->year === $report['previous_to']->year && $report['previous_to']->year === $report['to']->year;
        $dateFmt = $sameYear ? 'd/m' : 'd/m/Y';
        $compareLabel = $report['previous_from']->format($dateFmt) . '–' . $report['previous_to']->format($dateFmt);
        $sortArrow = fn (string $col) => $this->sortBy === $col ? ($this->sortDir === 'asc' ? '↑' : '↓') : '';
        $bestRow = collect($chart['bars'])->sortByDesc('revenue')->first();
        $hasChartData = $totals['orders'] > 0 || $totals['total'] > 0 || array_sum(array_column($chart['bars'], 'prev_revenue')) > 0;
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Hiệu suất tài xế</p>
            <h1 class="fs-page-header__title">Thu nhập tài xế</h1>
            <p class="fs-page-header__description">
                {{ filament()->getTenant()?->name }} · Theo dõi thu nhập và hiệu suất giao hàng trong kỳ.
            </p>
        </div>
        <div class="fs-rr-head-actions">
            <div class="fs-rr-period-badge">
                <span>Kỳ đối soát</span>
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
        </div>

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
                (khoảng {{ $money($report['quality']['missing_fee']) }}) nên chưa được tính vào thu nhập của tài xế trong kỳ này.
            </span>
        </div>
    @endif

    <div wire:loading.class="opacity-50" class="fs-rr-body">
        <section aria-label="Tổng quan thu nhập">
            <div class="fs-rv-kpis">
                <article class="fs-rv-kpi fs-rv-kpi--hero">
                    <div class="fs-rv-kpi__head"><span>Tổng thu nhập tài xế</span><x-heroicon-o-banknotes /></div>
                    <strong>{{ $money($totals['total']) }}</strong>
                    @include('filament.pages.partials.delta', ['change' => $totals['earnings_change'], 'label' => $compareLabel])
                    @if ($hasChartData && $chart['spark_points'])
                        <svg class="fs-rv-spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline points="{{ $chart['spark_points'] }}" fill="none" stroke="currentColor" stroke-width="2.2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>
                    @endif
                    <small>Phí giao (gồm phụ phí đêm), bonus, thưởng mưa và bù giảm giá</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--driver">
                    <div class="fs-rv-kpi__head"><span>Tài xế có thu nhập</span><x-heroicon-o-user-group /></div>
                    <strong>{{ number_format($totals['earning_drivers'], 0, ',', '.') }}<small class="fs-de-of"> / {{ number_format($totals['drivers'], 0, ',', '.') }}</small></strong>
                    <small>{{ number_format($totals['online'], 0, ',', '.') }} tài xế đang online</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--orders">
                    <div class="fs-rv-kpi__head"><span>Đơn hoàn thành</span><x-heroicon-o-check-circle /></div>
                    <strong>{{ number_format($totals['orders'], 0, ',', '.') }}</strong>
                    <small>Trung bình {{ $money($totals['avg_per_order']) }} / đơn</small>
                </article>
                <article class="fs-rv-kpi fs-rv-kpi--cost">
                    <div class="fs-rv-kpi__head"><span>Trung bình / tài xế</span><x-heroicon-o-calculator /></div>
                    <strong>{{ $money($totals['avg_per_driver']) }}</strong>
                    <small>Tính trên tài xế có thu nhập</small>
                </article>
            </div>
            <p class="fs-revenue-compare">Tăng/giảm so với kỳ {{ $compareLabel }} · Tổng hợp toàn khu vực.</p>
        </section>

        {{-- Bảng tài xế --}}
        <section class="fs-rr-card fs-de-details">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Chi tiết theo tài xế</h2>
                    <p>Bấm tiêu đề cột để sắp xếp · bộ lọc chỉ áp dụng cho danh sách, dòng tổng là toàn khu vực.</p>
                </div>
                <span class="fs-revenue-row-count">{{ number_format(count($report['drivers']), 0, ',', '.') }} / {{ number_format($report['visible_count'], 0, ',', '.') }} tài xế</span>
            </header>
            <div class="fs-de-list-filters">
                <div class="fs-de-search-wrap"><x-heroicon-o-magnifying-glass /><input type="search" class="fs-rr-search" wire:model.live.debounce.300ms="search" placeholder="Tìm tên hoặc số điện thoại" aria-label="Tìm tài xế"></div>
                <select class="fs-rr-select" wire:model.live="onlineStatus" aria-label="Trạng thái online">
                    <option value="all">Tất cả trạng thái</option>
                    <option value="online">Đang online</option>
                    <option value="offline">Offline</option>
                </select>
                <div class="fs-seg" role="group" aria-label="Hoạt động trong kỳ">
                    @foreach (['has_orders' => 'Có thu nhập', 'all' => 'Tất cả', 'no_orders' => 'Chưa có đơn'] as $key => $label)
                        <button type="button" wire:click="$set('activity', '{{ $key }}')" aria-pressed="{{ $activity === $key ? 'true' : 'false' }}" @class(['is-active' => $activity === $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="fs-revenue-table-scroll" tabindex="0" role="region" aria-label="Bảng thu nhập tài xế">
                <table class="fs-rr-table fs-de-table">
                    <thead>
                        <tr>
                            <th scope="col" class="fs-de-rank">#</th>
                            @foreach (['name' => 'Tài xế', 'total' => 'Tổng thu nhập', 'orders' => 'Đơn', 'shipping' => 'Phí giao', 'surcharge' => 'Bonus', 'rain' => 'Thưởng mưa', 'discount' => 'Bù giảm giá', 'avg' => 'TB / đơn'] as $col => $label)
                                <th scope="col" aria-sort="{{ $this->sortBy === $col ? ($this->sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}" @class(['is-sorted' => $this->sortBy === $col])><button type="button" wire:click="sort('{{ $col }}')">{{ $label }} <span>{{ $sortArrow($col) }}</span></button></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['drivers'] as $i => $d)
                            <tr>
                                <td class="fs-de-rank">{{ $i + 1 }}</td>
                                <td>
                                    <div class="fs-de-driver">
                                        <span class="fs-de-avatar">{{ mb_strtoupper(mb_substr(trim($d['name']), 0, 1)) }}<i class="fs-de-dot {{ $d['is_online'] ? 'is-online' : '' }}" title="{{ $d['is_online'] ? 'Đang online' : 'Offline' }}"></i></span>
                                        <div>
                                            <b>{{ $d['name'] }}</b>
                                            <small>{{ $d['phone'] ?: 'Chưa có SĐT' }}@if ($d['locked']) · <em>Bị khóa</em>@endif</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="fs-de-total">
                                    <b>{{ $money($d['total']) }}</b>
                                    <span class="fs-de-share" title="{{ $d['share'] }}% thu nhập toàn khu vực"><i style="width: {{ min(100, $d['share']) }}%"></i></span>
                                    <small>{{ $d['share'] }}% khu vực</small>
                                </td>
                                <td>{{ number_format($d['orders'], 0, ',', '.') }}</td>
                                <td>{{ $money($d['shipping']) }}</td>
                                <td>{{ $d['surcharge'] ? $money($d['surcharge']) : '—' }}</td>
                                <td>{{ $d['rain'] ? $money($d['rain']) : '—' }}</td>
                                <td>{{ $d['discount'] ? $money($d['discount']) : '—' }}</td>
                                <td>{{ $d['avg'] ? $money($d['avg']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="fs-de-empty">Không có tài xế phù hợp với bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="fs-de-rank"></td>
                            <td>Tổng toàn khu vực</td>
                            <td class="fs-de-total"><b>{{ $money($totals['total']) }}</b></td>
                            <td>{{ number_format($totals['orders'], 0, ',', '.') }}</td>
                            <td>{{ $money($totals['shipping']) }}</td>
                            <td>{{ $money($totals['surcharge']) }}</td>
                            <td>{{ $money($totals['rain']) }}</td>
                            <td>{{ $money($totals['discount']) }}</td>
                            <td>{{ $money($totals['avg_per_order']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if ($report['has_more'])
                <button type="button" class="fs-de-more" wire:click="showMore" wire:loading.attr="disabled">
                    Xem thêm {{ min(25, $report['visible_count'] - $this->limit) }} tài xế
                </button>
            @endif
        </section>

        {{-- Biểu đồ --}}
        <div class="fs-rr-main-grid">
        <section class="fs-rr-card fs-rr-chart-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Xu hướng thu nhập</h2>
                    <p>Thu nhập toàn khu vực · {{ $report['granularity_label'] }}</p>
                </div>
                @if ($hasChartData)
                <div class="fs-rr-legend">
                    <span><i class="fs-rr-legend__bar"></i>Thu nhập</span>
                    @if ($chart['has_previous'])<span><i class="fs-rr-legend__dash"></i>Kỳ trước</span>@endif
                    <span><i class="fs-rr-legend__line"></i>Đơn hoàn thành <small>(thang riêng, tối đa {{ $chart['max_orders'] }})</small></span>
                </div>
                @endif
            </header>

            @if (! $hasChartData)
                <div class="fs-revenue-empty">
                    <x-heroicon-o-banknotes />
                    <h3>Chưa có thu nhập trong kỳ</h3>
                    <p>Chọn khoảng thời gian khác để xem thu nhập của tài xế.</p>
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
                                        <span>Thu nhập <strong>{{ $money($bar['revenue']) }}</strong></span>
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

        {{-- Cấu thành thu nhập --}}
        <aside class="fs-rr-card fs-rr-composition fs-de-composition">
            <header class="fs-rr-card__head"><div><h2>Cấu thành thu nhập</h2><p>Các khoản ghi nhận trong kỳ</p></div></header>
            <div class="fs-revenue-stack fs-de-stack" role="img" aria-label="Tỷ trọng phí giao, bonus, thưởng mưa và bù giảm giá">
                @foreach (['shipping', 'surcharge', 'rain', 'discount'] as $part)
                    <i class="is-{{ $part }}" style="width: {{ $totals[$part] / max(1, $totals['total']) * 100 }}%"></i>
                @endforeach
            </div>
            <ul class="fs-de-parts">
                @foreach (['shipping' => ['Phí giao', 'Thu từ khách, gồm phụ phí đêm và freeship công ty trả'], 'surcharge' => ['Bonus', 'Admin thưởng thêm cho đơn'], 'rain' => ['Thưởng mưa', 'Đơn đủ điều kiện'], 'discount' => ['Bù giảm giá', 'Phần voucher công ty trả thay']] as $part => [$label, $hint])
                    @php $pct = round($totals[$part] / max(1, $totals['total']) * 100, 1); @endphp
                    <li @class(['is-zero' => $totals[$part] === 0])>
                        <span class="fs-de-parts__name"><i class="is-{{ $part }}"></i>{{ $label }}</span>
                        <b>{{ $money($totals[$part]) }}</b>
                        <small>{{ $hint }}</small><em>{{ $pct }}%</em>
                        <span class="fs-de-parts__bar"><i class="is-{{ $part }}" style="width: {{ $pct }}%"></i></span>
                    </li>
                @endforeach
            </ul>
            @if ($bestRow && $bestRow['revenue'] > 0)
                <div class="fs-rr-highlight"><x-heroicon-o-sparkles /><span>Cao nhất kỳ: <b>{{ $bestRow['label'] }}</b><strong>{{ $money($bestRow['revenue']) }}</strong></span></div>
            @endif
        </aside>
        </div>

        <p class="fs-de-accounting-note">Mọi khoản tính theo đơn hoàn thành trong kỳ tại khu vực này (theo ngày hoàn thành đơn). Phí giao đã gồm phụ phí đêm và phí freeship công ty trả thay khách. Không gồm nạp/rút ví, điều chỉnh và khoản phạt.</p>

    </div>
    </div>
</x-filament-panels::page>
