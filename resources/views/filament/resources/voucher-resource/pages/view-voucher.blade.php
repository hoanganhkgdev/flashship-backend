<x-filament-panels::page>
    @php
        $v = $this->record;
        $summary = $this->getSummary();
        $daily = $this->getDaily();
        $usages = $this->getRecentUsages();
        $rules = $this->getRules();
        $money = fn ($x) => number_format((int) $x, 0, ',', '.') . '₫';
        $num = fn ($x) => number_format((int) $x, 0, ',', '.');
        $statusKey = $v->statusKey();
        $statusLabel = ['active' => 'Đang chạy', 'full' => 'Hết lượt', 'expired' => 'Hết hạn', 'inactive' => 'Đã tắt'][$statusKey];
        $statusColor = ['active' => 'success', 'full' => 'warning', 'expired' => 'danger', 'inactive' => 'gray'][$statusKey];
        $orderColors = ['pending' => 'warning', 'assigned' => 'info', 'processing' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];
        $orderLabels = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã huỷ'];
    @endphp

    <section class="fs-rr-card fs-vc-head">
        <div class="fs-vc-preview">
            <b>{{ $v->code }}</b>
            <strong>{{ \Modules\Core\Models\Voucher::describeOffer($v->type, $v->value, $v->max_discount) }}</strong>
            <span>{{ $v->description ?: 'Không có mô tả' }}</span>
        </div>
        <div class="fs-vc-head__side">
            <span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span>
            @if ($summary['limit_pct'] !== null)
                <div class="fs-vc-limit">
                    <span class="fs-vc-meter"><i style="width: {{ $summary['limit_pct'] }}%"></i></span>
                    <small>{{ $num($v->used_count) }} / {{ $num($v->usage_limit) }} lượt ({{ $summary['limit_pct'] }}%)</small>
                </div>
            @else
                <small class="fs-rr-note">Không giới hạn tổng lượt</small>
            @endif
        </div>
    </section>

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Lượt dùng</span><strong>{{ $num($summary['uses']) }}</strong><small>{{ $num($summary['users']) }} người · gần nhất {{ $summary['last_at']?->format('d/m/Y') ?? '—' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Chi phí giảm giá</span><strong>{{ $money($summary['cost']) }}</strong><small>TB {{ $money($summary['avg']) }} / đơn hoàn thành</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn áp mã</span><strong>{{ $num($summary['orders']) }}</strong><small>{{ $num($summary['completed']) }} hoàn thành · {{ $num($summary['cancelled']) }} đã hủy</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tạo lúc</span><strong class="fs-cp-date">{{ $v->created_at?->format('d/m/Y') }}</strong><small>{{ $v->expires_at ? 'Hết hạn '.$v->expires_at->format('d/m/Y') : 'Không có hạn dùng' }}</small></article>
    </div>

    <div class="fs-cp-grid">
        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Lượt dùng 30 ngày</h2><p>{{ $num($daily['total']) }} lượt · cao nhất {{ $num($daily['max']) }}/ngày</p></div></header>
                <div class="fs-vc-bars" role="img" aria-label="Lượt dùng theo ngày">
                    @foreach ($daily['days'] as $d)
                        <span class="fs-vc-bars__col" title="{{ $d['label'] }}: {{ $d['count'] }} lượt"><i style="height: {{ $d['pct'] }}%"></i></span>
                    @endforeach
                </div>
                <div class="fs-vc-bars__axis"><span>{{ $daily['days'][0]['label'] }}</span><span>{{ end($daily['days'])['label'] }}</span></div>
            </section>

            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Người dùng gần đây</h2><p>{{ $usages->count() }} lượt mới nhất</p></div></header>
                @forelse ($usages as $u)
                    <div class="fs-vc-use">
                        <div>
                            <b>{{ $u['name'] ?? 'Tài khoản đã xóa' }}</b>
                            <small>{{ $u['phone'] ?? '—' }} · {{ \Carbon\Carbon::parse($u['used_at'])->format('H:i · d/m/Y') }}</small>
                        </div>
                        <div class="fs-vc-use__order">
                            @if ($u['url'])
                                <a href="{{ $u['url'] }}" wire:navigate>{{ $u['order_code'] }}</a>
                            @endif
                            @if ($u['order_status'])
                                <span class="fs-order-pill fs-order-pill--{{ $orderColors[$u['order_status']] ?? 'gray' }}">{{ $orderLabels[$u['order_status']] ?? $u['order_status'] }}</span>
                            @endif
                            @if ($u['discount_amount'])<em>−{{ $money($u['discount_amount']) }}</em>@endif
                        </div>
                    </div>
                @empty
                    <p class="fs-rr-note">Mã này chưa có lượt dùng nào.</p>
                @endforelse
            </section>
        </div>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Điều kiện áp dụng</h2><p>Thiết lập hiện tại của mã</p></div></header>
            <dl class="fs-vc-rules">
                @foreach ($rules as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    </div>
</x-filament-panels::page>
