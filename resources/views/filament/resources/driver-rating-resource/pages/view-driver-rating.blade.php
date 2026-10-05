<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-ratings', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $o = $record;
        [$statusLabel, $statusColor] = \App\Filament\Resources\DriverRatingResource::statusLabel($o);
        $handler = $this->getHandler();
        $others = $this->getOtherRatings();
        $driverUrl = $this->driverUrl();
        $service = \App\Filament\Resources\DriverRatingResource::serviceLabels()[$o->service_type] ?? $o->service_type;
    @endphp

    @if (\App\Services\DriverRatingService::needsAction($o))
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span><b>Đánh giá thấp chưa xử lý.</b> Hãy liên hệ tài xế hoặc khách rồi bấm "Đã xử lý" và ghi lại kết quả.</span>
        </div>
    @endif

    <section class="fs-rr-card">
        <header class="fs-rr-card__head">
            <div>
                <h2>{!! \App\Filament\Resources\DriverRatingResource::stars((int) $o->driver_rating) !!} {{ $o->driver_rating }}/5</h2>
                <p>{{ $o->rated_at?->format('H:i · d/m/Y') ?? '—' }} · {{ \App\Filament\Resources\DriverRatingResource::sourceLabel($o) }}{{ ($o->sender?->name ?: $o->sender_name) ? ' · '.($o->sender?->name ?: $o->sender_name) : '' }}</p>
            </div>
            <span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span>
        </header>
        <p class="fs-rr-note fs-rt-note">{{ $o->driver_rating_note ?: 'Khách không để lại nhận xét.' }}</p>
    </section>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Đánh giá khác của tài xế</h2><p>{{ $o->driver ? '6 đánh giá gần nhất của '.$o->driver->name : 'Đơn chưa gán tài xế' }}</p></div></header>
            @forelse ($others as $r)
                <a class="fs-dr-row" href="{{ \App\Filament\Resources\DriverRatingResource::getUrl('view', ['record' => $r->id]) }}" wire:navigate>
                    <span>{!! \App\Filament\Resources\DriverRatingResource::stars((int) $r->driver_rating) !!}
                        {{ \Illuminate\Support\Str::limit($r->driver_rating_note ?: 'Không có nhận xét', 70) }}</span>
                    <small>#{{ $r->code }} · {{ $r->rated_at?->format('d/m/Y') }}</small>
                </a>
            @empty
                <p class="fs-rr-note">Chưa có đánh giá nào khác.</p>
            @endforelse
        </section>

        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Đơn hàng</h2></div></header>
                <div class="fs-dr-row"><span>Mã đơn</span><b>#{{ $o->code }}</b></div>
                <div class="fs-dr-row"><span>Dịch vụ</span><b>{{ $service }}</b></div>
                <div class="fs-dr-row"><span>Hoàn thành</span><b>{{ $o->completed_at?->format('H:i d/m/Y') ?? '—' }}</b></div>
                <div class="fs-dr-row"><span>Tài xế</span>
                    <b>@if ($driverUrl)<a href="{{ $driverUrl }}" wire:navigate>{{ $o->driver?->name }}</a>@else Chưa gán @endif</b></div>
                <div class="fs-dr-row"><span>SĐT tài xế</span><b>{{ $o->driver?->phone ?: '—' }}</b></div>
                <div class="fs-dr-row"><span>SĐT người gửi</span><b>{{ $o->sender?->phone ?: '—' }}</b></div>
            </section>

            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Xử lý</h2></div></header>
                @if ($o->rating_handled_at)
                    <div class="fs-cp-addr">
                        <b>{{ $o->rating_hidden ? 'Đã ẩn khỏi thống kê' : 'Đã xử lý' }}</b>
                        <span>{{ $o->rating_handled_at->format('H:i · d/m/Y') }} · {{ $handler?->name ?? 'Quản trị viên' }}{{ $o->rating_handle_note ? ' · '.$o->rating_handle_note : '' }}</span>
                    </div>
                @else
                    <p class="fs-rr-note">{{ \App\Services\DriverRatingService::needsAction($o) ? 'Chưa xử lý.' : 'Đánh giá này không cần xử lý.' }}</p>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
