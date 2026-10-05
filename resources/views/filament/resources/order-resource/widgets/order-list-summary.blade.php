<x-filament-widgets::widget>
    @php $num = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp
    <div class="fs-ol-summary" x-data="{ comfy: false }" x-init="try { comfy = localStorage.getItem('fs-orders-comfy') === '1' } catch (e) {}; document.body.classList.toggle('fs-orders-comfy', comfy)">
        <span><b>{{ $num($total) }}</b> đơn đang xem</span>
        <span>Hoàn thành <b>{{ $num($completed) }}</b></span>
        <span>Hủy <b @class(['fs-dr-low' => $cancelRate >= 25])>{{ $num($cancelled) }}</b> ({{ $cancelRate }}%)</span>
        <span>Phí ship (đơn hoàn thành) <b>{{ $num($fee) }}₫</b></span>
        <span>Thu hộ (đơn hoàn thành) <b>{{ $num($cod) }}₫</b></span>
        <button type="button" class="fs-ol-density"
            x-on:click="comfy = ! comfy; document.body.classList.toggle('fs-orders-comfy', comfy); try { localStorage.setItem('fs-orders-comfy', comfy ? '1' : '0') } catch (e) {}"
            x-text="comfy ? 'Chế độ: thoải mái' : 'Chế độ: gọn'"></button>
    </div>
</x-filament-widgets::widget>
