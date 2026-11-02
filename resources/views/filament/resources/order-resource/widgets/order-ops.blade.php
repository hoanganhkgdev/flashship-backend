<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    {{-- Tab trực (Chờ nhận, Đang xử lý) làm mới 15s cùng bảng, để "Chờ lâu nhất", tỷ lệ hủy... không đứng yên từ lúc mở trang. --}}
    <div class="fs-order-pulse" @if (in_array($this->activeTab ?? 'new', ['new', 'processing'], true)) wire:poll.15s @endif>
        <article class="fs-order-pulse__card is-neutral">
            <span class="fs-order-pulse__icon"><x-heroicon-o-archive-box /></span>
            <span class="fs-order-pulse__label">Đơn hôm nay</span>
            <strong>{{ $num($total) }}</strong>
            <small>{{ $num($active) }} đơn đang xử lý, tạo hôm nay</small>
        </article>
        <article class="fs-order-pulse__card {{ $oldest >= 5 ? 'is-danger' : ($pending ? 'is-warning' : 'is-success') }}">
            <span class="fs-order-pulse__icon"><x-heroicon-o-clock /></span>
            <span class="fs-order-pulse__label">Chờ lâu nhất</span>
            <strong>{{ $pending ? \App\Filament\Resources\OrderResource::minutesLabel((int) $oldest) : '—' }}</strong>
            <small>{{ $pending ? $num($pending).' đơn chưa có tài xế' : 'Không có đơn đang chờ' }}</small>
        </article>
        <article class="fs-order-pulse__card {{ $cancelRate >= 25 ? 'is-danger' : 'is-neutral' }}">
            <span class="fs-order-pulse__icon"><x-heroicon-o-x-circle /></span>
            <span class="fs-order-pulse__label">Tỷ lệ hủy</span>
            <strong>{{ $cancelRate }}%</strong>
            <small>{{ $num($cancelled) }} đơn · {{ $num($quick) }} hủy sớm</small>
        </article>
        <article class="fs-order-pulse__card {{ $dupes > 0 ? 'is-warning' : 'is-success' }}">
            <span class="fs-order-pulse__icon"><x-heroicon-o-document-duplicate /></span>
            <span class="fs-order-pulse__label">Nghi trùng</span>
            <strong>{{ $num($dupes) }}</strong>
            <small>{{ $dupes ? 'Cần kiểm tra trong hôm nay' : 'Không phát hiện đơn trùng' }}</small>
        </article>
    </div>
</x-filament-widgets::widget>
