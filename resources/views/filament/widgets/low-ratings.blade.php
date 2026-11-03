<x-filament-widgets::widget>
    @php $reviews = $this->getReviews(); @endphp
    <section class="fs-widget" wire:poll.60s>
        <header class="fs-widget-header">
            <div>
                <h2 class="fs-widget-title">Đánh giá thấp chờ xử lý</h2>
                <p class="fs-widget-description">1–2 sao chưa có ghi chú xử lý</p>
            </div>
            @if ($reviews->isEmpty())
                <span class="fs-status-pill"><span class="fs-status-dot"></span>Không có</span>
            @else
                <span class="fs-alert-total">{{ $this->getPending() }} chờ xử lý</span>
            @endif
        </header>

        @if ($reviews->isEmpty())
            <div class="fs-dash-empty"><x-heroicon-o-face-smile /><span>Đã xử lý hết đánh giá thấp</span></div>
        @else
            <ul class="fs-review-list">
                @foreach ($reviews as $r)
                    <li>
                        <a href="{{ $r['url'] }}" wire:navigate class="fs-review fs-dash-review">
                            <span class="fs-dash-review__top">
                                <span class="fs-review__stars" aria-label="{{ $r['rating'] }} sao">{{ str_repeat('★', $r['rating']) }}<i>{{ str_repeat('★', 5 - $r['rating']) }}</i></span>
                                <small class="fs-review__when">{{ $r['when'] }}</small>
                            </span>
                            <b>{{ $r['driver'] }} <small>· {{ $r['code'] }}</small></b>
                            <span class="fs-dash-review__note">{{ $r['note'] ?: 'Không có nhận xét' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
