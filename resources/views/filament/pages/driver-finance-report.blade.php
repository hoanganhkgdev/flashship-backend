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
    @endphp

    <header class="fs-page-header">
        <div>
            <p class="fs-page-header__eyebrow">Báo cáo tài chính</p>
            <h1 class="fs-page-header__title">Thu phí tài xế</h1>
            <p class="fs-page-header__description">
                Khu vực {{ filament()->getTenant()?->name }} · Đối soát khoản phải thu, số đã thu, tiền công ty chi và tiền tài xế đã rút.
            </p>
        </div>
        <x-filament::button wire:click="export" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">
            Xuất Excel
        </x-filament::button>
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
        <div class="fs-rr-top">
            <article class="fs-rr-hero">
                <p class="fs-rr-hero__label">Còn phải thu · {{ $this->rangeLabel }}</p>
                <strong class="fs-rr-hero__value">{{ $money($totals['remaining']) }}</strong>
                @if ($collectRate === null)
                    <span class="fs-rr-delta fs-rr-delta--flat">Không có khoản phải thu trong kỳ này</span>
                @else
                    <div class="fs-fr-meter" role="img" aria-label="Đã thu {{ $collectRate }}%">
                        <div class="fs-fr-meter__bar"><i style="width: {{ $collectRate }}%"></i></div>
                        <div class="fs-fr-meter__legend">
                            <span><b>{{ $collectRate }}%</b> đã thu</span>
                            <span>{{ $money($totals['total_paid']) }} / {{ $money($totals['total_due']) }}</span>
                        </div>
                    </div>
                    <small class="fs-fr-hero__note">{{ $num($totals['debtors']) }} tài xế còn nợ trong kỳ này</small>
                @endif
            </article>

            <div class="fs-rr-kpis">
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Tổng phải thu</span>
                    <strong>{{ $money($totals['total_due']) }}</strong>
                    <small>Phí tuần {{ $money($totals['fee_due']) }} · Phạt {{ $money($totals['penalty']) }} · Khác {{ $money($totals['other']) }}</small>
                </article>
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Công ty đã chi</span>
                    <strong>{{ $money($totals['admin_paid']) }}</strong>
                    <small>Thưởng điểm, bù giảm giá, thưởng mưa</small>
                </article>
                <article class="fs-rr-kpi">
                    <span class="fs-rr-kpi__label">Tài xế đã rút</span>
                    <strong>{{ $money($totals['withdrawn']) }}</strong>
                    <small>
                        @if ($report['pending_withdraw']['items'] > 0)
                            Đang chờ duyệt: <b>{{ $money($report['pending_withdraw']['amount']) }}</b> ({{ $num($report['pending_withdraw']['items']) }} yêu cầu)
                        @else
                            Không có yêu cầu nào đang chờ duyệt
                        @endif
                    </small>
                </article>
            </div>
        </div>

        {{-- Cấu thành --}}
        <section class="fs-rr-card">
            <p class="fs-fr-sub">Khoản phải thu</p>
            <div class="fs-rr-bridge fs-rr-bridge--wide">
                <div class="fs-rr-bridge__item"><span>Phí tuần</span><strong>{{ $money($totals['fee_due']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Phạt điểm</span><strong>{{ $money($totals['penalty']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Khoản khác</span><strong>{{ $money($totals['other']) }}</strong></div>
                <span class="fs-rr-bridge__op">=</span>
                <div class="fs-rr-bridge__item fs-rr-bridge__item--total"><span>Tổng phải thu</span><strong>{{ $money($totals['total_due']) }}</strong></div>
            </div>

            <p class="fs-fr-sub fs-fr-sub--gap">Công ty đã chi cho tài xế</p>
            <div class="fs-rr-bridge fs-rr-bridge--wide">
                <div class="fs-rr-bridge__item"><span>Thưởng điểm</span><strong>{{ $money($totals['bonus']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Bù giảm giá</span><strong>{{ $money($totals['voucher']) }}</strong></div>
                <span class="fs-rr-bridge__op">+</span>
                <div class="fs-rr-bridge__item"><span>Thưởng mưa</span><strong>{{ $money($totals['rain']) }}</strong></div>
                <span class="fs-rr-bridge__op">=</span>
                <div class="fs-rr-bridge__item fs-rr-bridge__item--total"><span>Công ty đã chi</span><strong>{{ $money($totals['admin_paid']) }}</strong></div>
            </div>

            <div class="fs-rr-memo">
                <span>Điều chỉnh ví thủ công của admin trong kỳ: <b>+{{ $money($report['adjust']['credit']) }}</b> ghi có · <b>−{{ $money($report['adjust']['debit']) }}</b> ghi nợ <em>không tính vào các số trên</em></span>
            </div>
        </section>

        {{-- Xu hướng thu nợ --}}
        <section class="fs-rr-card">
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
                            <tr>
                                <td class="fs-de-rank">{{ $i + 1 }}</td>
                                <td>
                                    <div class="fs-de-driver">
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
