<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-ol-summary" x-data="{ comfy: false }" x-init="try { comfy = localStorage.getItem('fs-orders-comfy') === '1' } catch (e) {}; document.body.classList.toggle('fs-orders-comfy', comfy)">
        <span class="fs-ol-summary__title"><x-heroicon-o-queue-list /> Kết quả đang xem <b>{{ $num($total) }}</b></span>
        <span>Hoàn thành <b>{{ $num($completed) }}</b></span>
        <span>Hủy <b @class(['fs-dr-low' => $cancelRate >= 25])>{{ $num($cancelled) }}</b> · {{ $cancelRate }}%</span>
        <span>Phí ship <b>{{ $num($fee) }}₫</b></span>
        <span>Thu hộ <b>{{ $num($cod) }}₫</b></span>
        <button type="button" class="fs-ol-density"
            x-on:click="comfy = ! comfy; document.body.classList.toggle('fs-orders-comfy', comfy); try { localStorage.setItem('fs-orders-comfy', comfy ? '1' : '0') } catch (e) {}"
            x-text="comfy ? 'Chế độ: thoải mái' : 'Chế độ: gọn'"></button>
    </div>
</x-filament-widgets::widget>
