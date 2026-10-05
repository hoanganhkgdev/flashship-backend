<x-filament-panels::page>
    @php
        $report = $this->getReportData();
        $totals = $report['totals'];
        $chart = $report['chart'];
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . '₫';
        $sameYear = $report['previous_from']->year === $report['previous_to']->year && $report['previous_to']->year === $report['to']->year;
        $dateFmt = $sameYear ? 'd/m' : 'd/m/Y';
        $compareLabel = $report['previous_from']->format($dateFmt) . '–' . $report['previous_to']->format($dateFmt);
        $sortArrow = fn (string $col) => $this->sortBy === $col ? ($this->sortDir === 'asc' ? '↑' : '↓') : '';
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Hiệu suất tài xế</p>
            <h1 class="fs-page-header__title">Thu nhập tài xế</h1>
            <p class="fs-page-header__description">
                Khu vực {{ filament()->getTenant()?->name }} · Đối soát phí giao, phụ phí, thưởng mưa và bù giảm giá theo kỳ.
            </p>
        </div>
        <x-filament::button wire:click="exportCsv" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">
            Xuất CSV
        </x-filament::button>
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
        </div>

        <div class="fs-rr-controls fs-rr-controls--row2">
            <input type="search" class="fs-rr-search" wire:model.live.debounce.300ms="search" placeholder="Tìm tên hoặc số điện thoại" aria-label="Tìm tài xế">
            <select class="fs-rr-select" wire:model.live="onlineStatus" aria-label="Trạng thái online">
                <option value="all">Tất cả trạng thái</option>
                <option value="online">Đang online</option>
                <option value="offline">Offline</option>
            </select>
            <div class="fs-seg" role="group" aria-label="Hoạt động trong kỳ">
                @foreach (['has_orders' => 'Có thu nhập', 'all' => 'Tất cả', 'no_orders' => 'Chưa có đơn'] as $key => $label)
                    <button type="button" wire:click="$set('activity', '{{ $key }}')" @class(['is-active' => $activity === $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </section>

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
        <div class="fs-rr-top">
            <article class="fs-rr-hero">
                <p class="fs-rr-hero__label">Tổng thu nhập tài xế</p>
                <strong class="fs-rr-hero__value">{{ $money($totals['total']) }}</strong>
                @include('filament.pages.partials.delta', ['change' => $totals['earnings_change'], 'label' => $compareLabel])
                <svg class="fs-rr-spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <defs><linearGradient id="deSpark" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#f97316" stop-opacity=".35"/><stop offset="1" stop-color="#f97316" stop-opacity="0"/></linearGradient></defs>
                    @if ($chart['spark_points'])
                        <polygon points="0,100 {{ $chart['spark_points'] }} 100,100" fill="url(#deSpark)"/>
                        <polyline points="{{ $chart['spark_points'] }}" fill="none" stroke="#f97316" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                    @endif
                </svg>
            </article>

            <div class="fs-rr-kpis">
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Tài xế có thu nhập</span>
                    <strong>{{ number_format($totals['earning_drivers'], 0, ',', '.') }}</strong>
                    <small>trên {{ number_format($totals['drivers'], 0, ',', '.') }} tài xế · {{ number_format($totals['online'], 0, ',', '.') }} đang online</small>
                </article>
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Đơn hoàn thành</span>
                    <strong>{{ number_format($totals['orders'], 0, ',', '.') }}</strong>
                    <small>TB {{ $money($totals['avg_per_order']) }} thu nhập / đơn</small>
                </article>
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Trung bình / tài xế</span>
                    <strong>{{ $money($totals['avg_per_driver']) }}</strong>
                    <small>Tính trên tài xế có thu nhập trong kỳ</small>
                </article>
            </div>
        </div>

        {{-- Cấu thành thu nhập --}}
        <section class="fs-rr-card">
            <div class="fs-rr-bridge fs-rr-bridge--wide">
                <div class="fs-rr-bridge__item"><span>Phí giao</span><strong>{{ $money($totals['shipping']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Phụ phí</span><strong>{{ $money($totals['surcharge']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Thưởng mưa</span><strong>{{ $money($totals['rain']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Bù giảm giá</span><strong>{{ $money($totals['discount']) }}</strong></div>
                <span class="fs-rr-bridge__op">=</span>
                <div class="fs-rr-bridge__item fs-rr-bridge__item--total"><span>Tổng thu nhập</span><strong>{{ $money($totals['total']) }}</strong></div>
            </div>
            <div class="fs-rr-memo">
                <span>Phí giao và phụ phí tính theo ngày đơn hoàn thành; thưởng mưa và bù giảm giá tính theo ngày ghi có vào ví.</span>
                <span>Không gồm nạp/rút ví, điều chỉnh của admin và các khoản phạt.</span>
            </div>
        </section>

        {{-- Biểu đồ --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Xu hướng thu nhập</h2>
                    <p>{{ $report['from']->format('d/m/Y') }} – {{ $report['to']->format('d/m/Y') }} · {{ $report['granularity_label'] }}</p>
                </div>
                <div class="fs-rr-legend">
                    <span><i class="fs-rr-legend__bar"></i>Thu nhập</span>
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
        </section>

        {{-- Bảng tài xế --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Chi tiết theo tài xế</h2>
                    <p>{{ number_format($report['visible_count'], 0, ',', '.') }} tài xế · bấm tiêu đề cột để sắp xếp</p>
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="fs-rr-table fs-de-table">
                    <thead>
                        <tr>
                            <th class="fs-de-rank">#</th>
                            @foreach (['name' => 'Tài xế', 'orders' => 'Đơn', 'shipping' => 'Phí giao', 'surcharge' => 'Phụ phí', 'rain' => 'Thưởng mưa', 'discount' => 'Bù giảm giá', 'total' => 'Tổng thu nhập', 'avg' => 'TB / đơn'] as $col => $label)
                                <th @class(['is-sorted' => $this->sortBy === $col])><button type="button" wire:click="sort('{{ $col }}')">{{ $label }} <span>{{ $sortArrow($col) }}</span></button></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['drivers'] as $i => $d)
                            <tr>
                                <td class="fs-de-rank">{{ $i + 1 }}</td>
                                <td>
                                    <div class="fs-de-driver">
                                        <span class="fs-de-dot {{ $d['is_online'] ? 'is-online' : '' }}" title="{{ $d['is_online'] ? 'Đang online' : 'Offline' }}"></span>
                                        <div>
                                            <b>{{ $d['name'] }}</b>
                                            <small>{{ $d['phone'] ?: 'Chưa có SĐT' }}@if ($d['locked']) · <em>Bị khóa</em>@endif</small>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ number_format($d['orders'], 0, ',', '.') }}</td>
                                <td>{{ $money($d['shipping']) }}</td>
                                <td>{{ $d['surcharge'] ? $money($d['surcharge']) : '—' }}</td>
                                <td>{{ $d['rain'] ? $money($d['rain']) : '—' }}</td>
                                <td>{{ $d['discount'] ? $money($d['discount']) : '—' }}</td>
                                <td class="fs-de-total">
                                    <b>{{ $money($d['total']) }}</b>
                                    <span class="fs-de-share"><i style="width: {{ min(100, $d['share'] * 4) }}%"></i></span>
                                    <small>{{ $d['share'] }}%</small>
                                </td>
                                <td>{{ $d['avg'] ? $money($d['avg']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="fs-de-empty">Không có tài xế phù hợp với bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td>Tổng toàn khu vực</td>
                            <td>{{ number_format($totals['orders'], 0, ',', '.') }}</td>
                            <td>{{ $money($totals['shipping']) }}</td>
                            <td>{{ $money($totals['surcharge']) }}</td>
                            <td>{{ $money($totals['rain']) }}</td>
                            <td>{{ $money($totals['discount']) }}</td>
                            <td class="fs-de-total"><b>{{ $money($totals['total']) }}</b></td>
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
    </div>
</x-filament-panels::page>
