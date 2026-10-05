<x-filament-panels::page>
    @php
        $user = $this->record;
        $summary = $this->getSummary();
        $orders = $this->getRecentOrders();
        $addresses = $this->getAddresses();
        $vouchers = $this->getVoucherUsages();
        $callCenter = $this->getCallCenterMatches();
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $statusMap = [1 => ['Hoạt động', 'success'], 2 => ['Bị khóa', 'danger']];
        [$statusLabel, $statusColor] = $statusMap[(int) $user->status] ?? ['Không rõ', 'gray'];
        $initial = mb_strtoupper(mb_substr((string) $user->name, 0, 1));
        $photo = $user->profile_photo_path ? \Illuminate\Support\Facades\Storage::url($user->profile_photo_path) : null;
    @endphp

    @if ($this->sharesPhoneWithDriver())
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Số điện thoại này cũng là của một tài xế.</b> Tài khoản vẫn đặt đơn được nhưng không được dùng mã giảm giá (để chặn tài xế tự đặt đơn giảm giá rồi nhận tiền bù).</span>
        </div>
    @endif

    {{-- Hồ sơ --}}
    <section class="fs-rr-card fs-cp-profile">
        @if ($photo)
            <img class="fs-cp-avatar" src="{{ $photo }}" alt="{{ $user->name }}" onerror="this.style.display='none'; this.nextElementSibling.style.display='grid'">
            <span class="fs-cp-avatar fs-cp-avatar--text" style="display:none">{{ $initial ?: '?' }}</span>
        @else
            <span class="fs-cp-avatar fs-cp-avatar--text">{{ $initial ?: '?' }}</span>
        @endif
        <div class="fs-cp-profile__main">
            <h2>{{ $user->name }} <span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span></h2>
            <dl>
                <div><dt>Số điện thoại</dt><dd>{{ $user->phone ?: '—' }}</dd></div>
                <div><dt>Khu vực</dt><dd>{{ $user->city?->name ?? '—' }}</dd></div>
                <div><dt>Đăng ký</dt><dd>{{ $user->created_at?->format('d/m/Y H:i') }}</dd></div>
            </dl>
        </div>
    </section>

    {{-- Tóm tắt --}}
    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn đã đặt</span><strong>{{ $num($summary['total']) }}</strong><small>{{ $num($summary['completed']) }} hoàn thành · {{ $num($summary['active']) }} đang xử lý</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tổng chi tiêu</span><strong>{{ $money($summary['spent']) }}</strong><small>TB {{ $money($summary['avg']) }} / đơn · tiết kiệm {{ $money($summary['discount']) }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tỷ lệ hủy</span><strong>{{ $summary['cancel_rate'] }}%</strong><small>{{ $num($summary['cancelled']) }} đơn đã hủy</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đặt gần nhất</span><strong class="fs-cp-date">{{ $summary['last_at']?->format('d/m/Y') ?? '—' }}</strong><small>{{ $summary['first_at'] ? 'Đơn đầu tiên '.$summary['first_at']->format('d/m/Y') : 'Chưa đặt đơn nào' }}</small></article>
    </div>

    <div class="fs-cp-grid">
        {{-- Đơn gần đây --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Đơn gần đây</h2><p>{{ $orders->count() }} đơn mới nhất từ app khách hàng</p></div></header>
            @if ($orders->isEmpty())
                <div class="fs-empty"><div>Khách chưa đặt đơn nào qua app.</div></div>
            @else
                <ul class="fs-live-list fs-cp-orders">
                    @foreach ($orders as $o)
                        <li>
                            <a class="fs-live-row" href="{{ $o['url'] }}" wire:navigate>
                                <span class="fs-live-row__main">
                                    <span class="fs-live-row__code">{{ $o['code'] }}</span>
                                    <span class="fs-live-row__service">{{ $o['service'] }}</span>
                                    <span class="fs-order-pill fs-order-pill--{{ $o['color'] }}">{{ $o['status'] }}</span>
                                </span>
                                <span class="fs-live-row__route">{{ $o['route'] }}</span>
                                <span class="fs-live-row__meta"><b>{{ $money($o['fee']) }}</b><small>{{ $o['when']?->format('H:i · d/m/Y') }}</small></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="fs-cp-side">
            {{-- Địa chỉ --}}
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Địa chỉ đã lưu</h2><p>{{ $num($addresses->count()) }} địa chỉ</p></div></header>
                @forelse ($addresses as $a)
                    <div class="fs-cp-addr">
                        <b>{{ $a->label ?: ($a->place_name ?: 'Địa chỉ') }} @if ($a->is_default)<em>Mặc định</em>@endif</b>
                        <span>{{ $a->address }}</span>
                    </div>
                @empty
                    <p class="fs-rr-note">Chưa lưu địa chỉ nào.</p>
                @endforelse
            </section>

            {{-- Mã giảm giá --}}
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Mã giảm giá đã dùng</h2><p>{{ $num($this->getVoucherUsageTotal()) }} lượt</p></div></header>
                @forelse ($vouchers as $v)
                    <div class="fs-cp-addr">
                        <b>{{ $v->code }}@if ($v->discount_amount) <em>−{{ $money($v->discount_amount) }}</em>@endif</b>
                        <span>{{ $v->order_code ? 'Đơn '.$v->order_code.' · ' : '' }}{{ \Carbon\Carbon::parse($v->used_at)->format('H:i · d/m/Y') }}</span>
                    </div>
                @empty
                    <p class="fs-rr-note">Chưa dùng mã giảm giá nào.</p>
                @endforelse
            </section>

            {{-- Đơn tổng đài khớp SĐT --}}
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Đơn qua tổng đài</h2><p>Khớp theo số điện thoại · {{ $num($callCenter['count']) }} đơn</p></div></header>
                @forelse ($callCenter['latest'] as $c)
                    <a class="fs-cp-addr fs-cp-addr--link" href="{{ $c['url'] }}" wire:navigate><b>{{ $c['code'] }}</b><span>{{ $c['when']?->format('H:i · d/m/Y') }}</span></a>
                @empty
                    <p class="fs-rr-note">Không có đơn tổng đài nào ghi số điện thoại này. Đa số đơn tổng đài không ghi số điện thoại khách.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-filament-panels::page>
