<x-filament-panels::page>
    @php
        $report = $this->getReportData();
        $totals = $report['totals'];
        $overall = $report['overall'];
        $chart = $report['chart'];
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . '₫';
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        $periodKeys = array_keys($this->periods);
        $periodIdx = array_search($this->date, $periodKeys, true);
        $periodIdx = $periodIdx === false ? 0 : $periodIdx;
        $sortArrow = fn (string $col) => $this->sortBy === $col ? ($this->sortDir === 'asc' ? '↑' : '↓') : '';
        $collectRate = $totals['collect_rate'];
        $topDebt = collect($report['drivers'])->sortByDesc('remaining')->first();
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo tài chính</p>
            <h1 class="fs-page-header__title">Thu phí tài xế</h1>
            <p class="fs-page-header__description">
                Khu vực {{ filament()->getTenant()?->name }} · Đối soát khoản phải thu, số đã thu, tiền công ty chi và tiền tài xế đã rút.
            </p>
        </div>
        <div class="fs-rr-head-actions">
            <div class="fs-rr-period-badge"><span>Kỳ đối soát</span><b>{{ $this->rangeLabel }}</b></div>
            <x-filament::button wire:click="export" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">
                Xuất Excel
            </x-filament::button>
        </div>
    </header>

    {{-- Thanh lọc --}}
    <section class="fs-rr-toolbar">
        <div class="fs-seg" role="group" aria-label="Kỳ báo cáo">
            @foreach (['week' => 'Theo tuần', 'month' => 'Theo tháng'] as $key => $label)
                <button type="button" wire:click="$set('mode', '{{ $key }}')" @class(['is-active' => $mode === $key])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="fs-rr-controls">
            <div class="fs-nav" role="group" aria-label="Chọn kỳ">
                <button type="button" wire:click="shiftPeriod(-1)" @disabled($periodIdx >= count($periodKeys) - 1) aria-label="Kỳ trước">‹</button>
                <select class="fs-rr-select" wire:model.live="date" aria-label="Kỳ báo cáo">
                    @foreach ($this->periods as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <button type="button" wire:click="shiftPeriod(1)" @disabled($periodIdx <= 0) aria-label="Kỳ sau">›</button>
            </div>
        </div>

        <div class="fs-rr-controls fs-rr-controls--row2">
            <input type="search" class="fs-rr-search" wire:model.live.debounce.300ms="search" placeholder="Tìm tên hoặc số điện thoại" aria-label="Tìm tài xế">
            <div class="fs-seg" role="group" aria-label="Công nợ">
                @foreach (['all' => 'Tất cả', 'outstanding' => 'Còn nợ', 'settled' => 'Đã hoàn tất'] as $key => $label)
                    <button type="button" wire:click="$set('debtStatus', '{{ $key }}')" @class(['is-active' => $debtStatus === $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </section>

    @if ($overall['amount'] > 0)
        <div class="fs-fr-alert" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                <b>Nợ chưa thu của mọi kỳ: {{ $money($overall['amount']) }}</b>
                · {{ $num($overall['items']) }} khoản của {{ $num($overall['drivers']) }} tài xế@if ($overall['oldest']), cũ nhất từ {{ $overall['oldest']->format('d/m/Y') }}@endif.
                Số này gồm cả các kỳ không nằm trong khoảng đang xem.
            </span>
        </div>
    @endif

    <div wire:loading.class="opacity-50" class="fs-rr-body">
        <div class="fs-rr-kpi-grid fs-fr-kpi-grid">
            <article class="fs-rr-kpi fs-fr-kpi--debt">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-exclamation-circle /></span>
                <div><span class="fs-rr-kpi__label">Còn phải thu</span><strong>{{ $money($totals['remaining']) }}</strong></div>
                <small>{{ $num($totals['debtors']) }} tài xế còn nợ trong kỳ</small>
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--average">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-document-text /></span>
                <div><span class="fs-rr-kpi__label">Tổng phải thu</span><strong>{{ $money($totals['total_due']) }}</strong></div>
                <small>{{ $num($totals['drivers']) }} tài xế có phát sinh</small>
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--orders">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-check-badge /></span>
                <div><span class="fs-rr-kpi__label">Đã thu</span><strong>{{ $money($totals['total_paid']) }}</strong></div>
                <small>{{ $collectRate === null ? 'Chưa có khoản phải thu' : $collectRate.'% tiến độ thu' }}</small>
            </article>
            <article class="fs-rr-kpi fs-rr-kpi--rate">
                <span class="fs-rr-kpi__icon"><x-heroicon-o-arrow-up-tray /></span>
                <div><span class="fs-rr-kpi__label">Tài xế đã rút</span><strong>{{ $money($totals['withdrawn']) }}</strong></div>
                <small>{{ $num($report['pending_withdraw']['items']) }} yêu cầu chờ · {{ $money($report['pending_withdraw']['amount']) }}</small>
            </article>
        </div>

        {{-- Xu hướng thu nợ --}}
        <div class="fs-rr-main-grid">
        <section class="fs-rr-card fs-rr-chart-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Xu hướng thu nợ</h2>
                    <p>8 {{ $mode === 'month' ? 'tháng' : 'tuần' }} gần nhất, kết thúc ở kỳ đang xem</p>
                </div>
                <div class="fs-rr-legend">
                    <span><i class="fs-rr-legend__bar"></i>Đã thu</span>
                    <span><i class="fs-rr-legend__dash"></i>Phải thu</span>
                    <span><i class="fs-rr-legend__line"></i>Còn nợ <small>(thang riêng, tối đa {{ $money($chart['max_orders']) }})</small></span>
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
                                        <span>Phải thu <strong>{{ $money($bar['due']) }}</strong></span>
                                        <span>Đã thu <strong>{{ $money($bar['paid']) }}</strong></span>
                                        <span>Còn nợ <strong>{{ $money($bar['remaining']) }}</strong></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <svg class="fs-rr-lines" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            @if ($chart['prev_points'])
                                <polyline points="{{ $chart['prev_points'] }}" fill="none" stroke="#94a3b8" stroke-width="1.6" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                            @endif
                            @if ($chart['orders_points'])
                                <polyline points="{{ $chart['orders_points'] }}" fill="none" stroke="#ef4444" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>
                            @endif
                        </svg>
                    </div>
                    <div class="fs-rr-xaxis">
                        @foreach ($chart['bars'] as $bar)<span>{{ $bar['show_label'] ? $bar['label'] : '' }}</span>@endforeach
                    </div>
                </div>
            </div>
        </section>

        <aside class="fs-rr-card fs-fr-collection">
            <header class="fs-rr-card__head"><div><h2>Tiến độ thu trong kỳ</h2><p>{{ $this->rangeLabel }}</p></div></header>
            <div class="fs-fr-collection__amount"><span>Còn phải thu</span><strong>{{ $money($totals['remaining']) }}</strong></div>
            @if ($collectRate !== null)
                <div class="fs-fr-meter" role="img" aria-label="Đã thu {{ $collectRate }}%">
                    <div class="fs-fr-meter__bar"><i style="width: {{ $collectRate }}%"></i></div>
                    <div class="fs-fr-meter__legend"><span><b>{{ $collectRate }}%</b> đã thu</span><span>{{ $money($totals['total_paid']) }} / {{ $money($totals['total_due']) }}</span></div>
                </div>
            @else
                <p class="fs-fr-empty-period">Không có khoản phải thu trong kỳ này.</p>
            @endif
            <div class="fs-fr-breakdown">
                <div><span><i></i>Phí tuần</span><b>{{ $money($totals['fee_due']) }}</b></div>
                <div><span><i></i>Phạt điểm</span><b>{{ $money($totals['penalty']) }}</b></div>
                <div><span><i></i>Khoản khác</span><b>{{ $money($totals['other']) }}</b></div>
            </div>
            @if ($topDebt && $topDebt['remaining'] > 0)
                <div class="fs-fr-priority"><x-heroicon-o-bell-alert /><span><small>Cần thu nhiều nhất</small><b>{{ $topDebt['name'] }}</b><strong>{{ $money($topDebt['remaining']) }}</strong></span></div>
            @endif
            <div class="fs-fr-company-paid"><span>Công ty đã chi</span><b>{{ $money($totals['admin_paid']) }}</b><small>Thưởng {{ $money($totals['bonus']) }} · Bù giảm giá {{ $money($totals['voucher']) }} · Mưa {{ $money($totals['rain']) }}</small></div>
        </aside>
        </div>

        <p class="fs-de-accounting-note">Điều chỉnh ví thủ công trong kỳ: <b>+{{ $money($report['adjust']['credit']) }}</b> ghi có · <b>−{{ $money($report['adjust']['debit']) }}</b> ghi nợ; không tính vào các chỉ số trên.</p>

        {{-- Bảng tài xế --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head">
                <div>
                    <h2>Chi tiết theo tài xế</h2>
                    <p>{{ $num($report['visible_count']) }} tài xế có phát sinh · bấm tiêu đề cột để sắp xếp</p>
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="fs-rr-table fs-de-table fs-fr-table">
                    <thead>
                        <tr>
                            <th class="fs-de-rank">#</th>
                            @foreach (['name' => 'Tài xế', 'fee_due' => 'Phí tuần', 'penalty' => 'Phạt', 'other' => 'Khác', 'total_due' => 'Phải thu', 'total_paid' => 'Đã thu', 'remaining' => 'Còn nợ', 'admin_paid' => 'Công ty chi', 'withdrawn' => 'Đã rút'] as $col => $label)
                                <th @class(['is-sorted' => $this->sortBy === $col])><button type="button" wire:click="sort('{{ $col }}')">{{ $label }} <span>{{ $sortArrow($col) }}</span></button></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['drivers'] as $i => $d)
                            @php $paidPct = $d['total_due'] > 0 ? min(100, round($d['total_paid'] / $d['total_due'] * 100)) : 0; @endphp
                            <tr @class(['fs-fr-row--due' => $d['remaining'] > 0, 'fs-fr-row--settled' => $d['remaining'] <= 0 && $d['total_due'] > 0])>
                                <td class="fs-de-rank">{{ $i + 1 }}</td>
                                <td>
                                    <div class="fs-de-driver">
                                        <span class="fs-de-avatar">{{ mb_strtoupper(mb_substr(trim($d['name']), 0, 1)) }}</span>
                                        <div>
                                            <b>{{ $d['name'] }}</b>
                                            <small>{{ $d['phone'] ?: 'Chưa có SĐT' }} · TX #{{ $d['id'] }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $d['fee_due'] ? $money($d['fee_due']) : '—' }}</td>
                                <td>{{ $d['penalty'] ? $money($d['penalty']) : '—' }}</td>
                                <td>{{ $d['other'] ? $money($d['other']) : '—' }}</td>
                                <td>{{ $d['total_due'] ? $money($d['total_due']) : '—' }}</td>
                                <td class="fs-de-total">
                                    <b class="fs-fr-paid-amount">{{ $d['total_paid'] ? $money($d['total_paid']) : '—' }}</b>
                                    @if ($d['total_due'] > 0)
                                        <span class="fs-de-share fs-fr-paid"><i style="width: {{ $paidPct }}%"></i></span>
                                        <small>{{ $paidPct }}%</small>
                                    @endif
                                </td>
                                <td>
                                    @if ($d['remaining'] > 0)
                                        <span class="fs-fr-chip fs-fr-chip--due">{{ $money($d['remaining']) }}</span>
                                    @elseif ($d['total_due'] > 0)
                                        <span class="fs-fr-chip fs-fr-chip--ok">Đã xong</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $d['admin_paid'] ? $money($d['admin_paid']) : '—' }}</td>
                                <td>{{ $d['withdrawn'] ? $money($d['withdrawn']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="fs-de-empty">Không có dữ liệu phù hợp trong kỳ này.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td>Tổng toàn khu vực</td>
                            <td>{{ $money($totals['fee_due']) }}</td>
                            <td>{{ $money($totals['penalty']) }}</td>
                            <td>{{ $money($totals['other']) }}</td>
                            <td>{{ $money($totals['total_due']) }}</td>
                            <td>{{ $money($totals['total_paid']) }}</td>
                            <td>{{ $money($totals['remaining']) }}</td>
                            <td>{{ $money($totals['admin_paid']) }}</td>
                            <td>{{ $money($totals['withdrawn']) }}</td>
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
