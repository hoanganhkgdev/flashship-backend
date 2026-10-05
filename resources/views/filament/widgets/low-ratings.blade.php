<x-filament-widgets::widget>
    @php $reviews = $this->getReviews(); @endphp
    <section class="fs-widget">
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
            <div class="fs-empty"><div>Đã xử lý hết đánh giá thấp</div></div>
        @else
            <ul class="fs-review-list">
                @foreach ($reviews as $r)
                    <li>
                        <a href="{{ $r['url'] }}" wire:navigate class="fs-review">
                            <span class="fs-review__stars">{{ str_repeat('★', $r['rating']) }}<i>{{ str_repeat('★', 5 - $r['rating']) }}</i></span>
                            <span class="fs-review__body">
                                <b>{{ $r['driver'] }} <small>· {{ $r['code'] }}</small></b>
                                <span>{{ $r['note'] ? \Illuminate\Support\Str::limit($r['note'], 90) : 'Không có nhận xét' }}</span>
                            </span>
                            <small class="fs-review__when">{{ $r['when'] }}</small>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
