@php
    $order = $getRecord();
@endphp
@if ($order?->exists)
    <link rel="stylesheet" href="{{ asset('css/order-quick-view.css') }}?v={{ filemtime(public_path('css/order-quick-view.css')) }}">
    @php
        $money = fn ($value) => number_format((int) $value, 0, ',', '.') . '₫';
        $phoneNumber = fn ($phone) => preg_replace('/\D+/', '', (string) $phone);
        $status = match ($order->status) {
            'pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng',
            'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy', default => $order->status,
        };
        $stage = match ($order->status) { 'assigned' => 1, 'processing' => 2, 'completed' => 3, default => 0 };
        $service = \Modules\Core\Models\ServiceType::where('key', $order->service_type)->value('label') ?: $order->service_type;
        $source = match ($order->platform) { 'customer_app' => 'App khách', 'shop_app' => 'App shop', 'call_center' => 'Tổng đài', default => $order->platform ?: 'Chưa rõ nguồn' };
        $payment = match ($order->payment_method) { 'cod' => 'COD', 'prepaid' => 'Thanh toán trước', 'wallet' => 'Ví', default => $order->payment_method ?: 'Chưa xác định' };
        $cargoLabel = match ($order->cargo_type) { 'standard' => 'Hàng thông thường', 'food' => 'Thực phẩm', 'flowers' => 'Giỏ hoa', 'parcel' => 'Kiện hàng', default => 'Loại hàng khác' };
        $note = trim((string) preg_replace('/^\s*\[Chưa có điểm giao[^\]]*\]\s*/u', '', (string) $order->order_note));
        $timeline = \Modules\Order\Services\OrderTimeline::for($order)->reverse()->values();
        $stops = [['Điểm lấy hàng', $order->pickup_address, $order->sender_name ?: $order->sender?->name, $order->pickup_phone, $order->store_name]];
        if (in_array($order->platform, ['customer_app', 'shop_app'], true)) {
            $stops[] = ['Điểm giao hàng', $order->delivery_address, $order->receiver_name, $order->delivery_phone, null];
        }
    @endphp
    <div class="fs-order-quickview">
        <section class="fs-qv-overview" data-status="{{ $order->status }}" aria-label="Trạng thái đơn hàng">
            <div class="fs-qv-overview__top">
                <div><span class="fs-qv-eyebrow">Trạng thái hiện tại</span><h2><i aria-hidden="true"></i>{{ $status }}</h2></div>
                @if ($order->status === 'pending')
                    <span class="fs-qv-wait"><x-heroicon-o-clock />Chờ {{ max(0, (int) $order->created_at?->diffInMinutes(now())) }} phút</span>
                @elseif ($order->completed_at)
                    <span class="fs-qv-finished">{{ $order->completed_at->format('H:i · d/m/Y') }}</span>
                @endif
            </div>
            <div class="fs-qv-meta"><span>{{ $service }}</span><span>{{ $source }}</span><span>{{ $order->city?->name ?: 'Chưa xác định khu vực' }}</span></div>
            <p class="fs-qv-created">Tạo lúc {{ $order->created_at?->format('H:i · d/m/Y') }}@if ($order->creator?->name) · {{ $order->creator->name }}@endif</p>
            @if ($order->status !== 'cancelled')
                <ol class="fs-qv-progress" aria-label="Tiến trình đơn">
                    @foreach (['Đã tạo', 'Đã nhận', 'Đã lấy hàng', 'Hoàn thành'] as $i => $label)
                        <li @class(['is-done' => $i <= $stage])><i aria-hidden="true">@if ($i <= $stage)<x-heroicon-m-check />@else{{ $i + 1 }}@endif</i><span>{{ $label }}</span></li>
                    @endforeach
                </ol>
            @else
                <div class="fs-qv-cancel"><b>{{ \Modules\Order\Services\OrderTimeline::cancelReasonLabel($order->cancel_reason) }}</b>@if ($order->cancel_note)<p>{{ $order->cancel_note }}</p>@endif</div>
            @endif
        </section>

        <div class="fs-qv-layout">
            <div class="fs-qv-main">
                <section class="fs-qv-card">
                    <header class="fs-qv-card__head"><x-heroicon-o-map-pin /><h3>Hành trình giao nhận</h3>@if ($order->distance)<span>{{ number_format((float) $order->distance, 1, ',', '.') }} km</span>@endif</header>
                    <div class="fs-qv-route">
                        @foreach ($stops as [$label, $address, $name, $phone, $store])
                            <div class="fs-qv-stop {{ $loop->first ? 'is-pickup' : 'is-delivery' }}">
                                <i class="fs-qv-stop__dot" aria-hidden="true">{{ $loop->first ? 'A' : 'B' }}</i>
                                <div><span class="fs-qv-label">{{ $label }}</span><p class="fs-qv-address">{{ $address ?: ($loop->first ? 'Chưa có địa chỉ lấy' : 'Chưa có điểm giao') }}</p>
                                    @if ($name || $store)<p class="fs-qv-contact">{{ $name }}@if ($name && $store && $name !== $store) · @endif@if ($store && $name !== $store){{ $store }}@endif</p>@endif
                                    @if ($phoneNumber($phone))
                                        <div class="fs-qv-contact-actions"><a href="tel:{{ $phoneNumber($phone) }}"><x-heroicon-o-phone />{{ \App\Support\OrderListPresenter::phone($phone) }}</a><a href="https://zalo.me/{{ $phoneNumber($phone) }}" target="_blank" rel="noopener noreferrer">Zalo ↗</a></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
                @if ($note !== '' || $order->cargo_type || $order->cargo_weight || $order->cargo_note)
                    <section class="fs-qv-card fs-qv-note">
                        <header class="fs-qv-card__head"><x-heroicon-o-chat-bubble-left-ellipsis /><h3>Hàng hóa & ghi chú</h3></header>
                        @if ($order->cargo_type || $order->cargo_weight)<div class="fs-qv-cargo">@if ($order->cargo_type)<span>{{ $cargoLabel }}</span>@endif @if ($order->cargo_weight)<span>{{ $order->cargo_weight }} kg</span>@endif</div>@endif
                        @if ($note !== '')<p>{{ $note }}</p>@endif
                        @if ($order->cargo_note)<p>{{ $order->cargo_note }}</p>@endif
                    </section>
                @endif
            </div>
            <aside class="fs-qv-side">
                <section class="fs-qv-card">
                    <header class="fs-qv-card__head"><x-heroicon-o-user-circle /><h3>Tài xế phụ trách</h3></header>
                    @if ($order->driver)
                        <div class="fs-qv-driver"><span>{{ mb_strtoupper(mb_substr(trim($order->driver->name), 0, 1)) }}</span><div><b>{{ $order->driver->name }}</b><small>Tài xế #{{ $order->driver->id }}</small></div></div>
                        @if ($phoneNumber($order->driver->phone))<div class="fs-qv-contact-actions"><a href="tel:{{ $phoneNumber($order->driver->phone) }}"><x-heroicon-o-phone />{{ \App\Support\OrderListPresenter::phone($order->driver->phone) }}</a><a href="https://zalo.me/{{ $phoneNumber($order->driver->phone) }}" target="_blank" rel="noopener noreferrer">Zalo ↗</a></div>@endif
                        @if ($order->driver_rating)<div class="fs-qv-rating">{{ $order->driver_rating }} / 5 <span aria-label="sao">★</span>@if ($order->driver_rating_note)<p>{{ $order->driver_rating_note }}</p>@endif</div>@endif
                    @else
                        <div class="fs-qv-unassigned"><x-heroicon-o-user-plus /><b>Chưa có tài xế</b><p>{{ $order->status === 'pending' ? 'Gán tài xế từ danh sách đơn để bắt đầu giao.' : 'Đơn chưa có thông tin tài xế.' }}</p></div>
                    @endif
                </section>
                <section class="fs-qv-card">
                    <header class="fs-qv-card__head"><x-heroicon-o-banknotes /><h3>Thanh toán</h3></header>
                    <div class="fs-qv-money"><div><span>Phí ship</span><strong>{{ $money($order->shipping_fee) }}</strong></div><div><span>Thu hộ COD</span><strong>{{ $money($order->cod_amount) }}</strong></div></div>
                    <dl class="fs-qv-fees">
                        <div><dt>Hình thức</dt><dd>{{ $payment }}</dd></div>
                        @foreach (['bonus_fee' => 'Thưởng thêm', 'night_surcharge' => 'Phụ phí đêm', 'discount_amount' => 'Giảm giá'] as $field => $label)
                            @if ($order->{$field})<div><dt>{{ $label }}</dt><dd>{{ $money($order->{$field}) }}</dd></div>@endif
                        @endforeach
                        @if ($order->voucher_code)<div><dt>Voucher</dt><dd>{{ $order->voucher_code }}</dd></div>@endif
                        @if ($order->is_freeship)<div><dt>Freeship</dt><dd>Khách không trả phí ship</dd></div>@endif
                        @if ($order->fee_note)<div class="fs-qv-fees__note"><dt>Ghi chú phí</dt><dd>{{ $order->fee_note }}</dd></div>@endif
                    </dl>
                </section>
            </aside>
        </div>

        <details class="fs-qv-card fs-qv-history" open>
            <summary><span><x-heroicon-o-clock />Lịch sử xử lý</span><span class="fs-qv-history__count">{{ $timeline->count() }} sự kiện <x-heroicon-o-chevron-down /></span></summary>
            <ol class="fs-qv-timeline">
                @foreach ($timeline as $event)
                    <li data-type="{{ $event['type'] }}"><i aria-hidden="true"></i><div><b>{{ $event['text'] }}</b><p>{{ $event['who'] ?: 'Hệ thống' }}</p><time>{{ $event['at']?->format('H:i:s · d/m/Y') }}</time></div></li>
                @endforeach
            </ol>
        </details>
    </div>
@else
    <p>Đơn vừa thay đổi. Vui lòng mở lại từ danh sách.</p>
@endif
