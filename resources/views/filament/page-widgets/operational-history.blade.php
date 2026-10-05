<x-filament-widgets::widget>
    <section class="fs-rr-card">
        <header class="fs-rr-card__head"><div><h2>Lịch sử thay đổi</h2><p>10 lần lưu gần nhất của khu vực {{ $city }}: ai đổi, đổi gì, từ giá trị nào</p></div></header>
        @forelse ($logs as $l)
            <div class="fs-cp-addr"><b>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}</b><span>{{ $l['detail'] }}</span></div>
        @empty
            <p class="fs-rr-note">Chưa có thay đổi nào được ghi lại. Lịch sử bắt đầu từ lần lưu tiếp theo.</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
