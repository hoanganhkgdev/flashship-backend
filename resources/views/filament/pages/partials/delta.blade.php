@php $dir = $change === null ? 'flat' : ($change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')); @endphp
<span class="fs-rr-delta fs-rr-delta--{{ $dir }}">
    @if ($change === null)
        Kỳ trước chưa có dữ liệu
    @else
        {{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '=') }} {{ ($change > 0 ? '+' : '') . $change }}%
        <small>so với {{ $label }}</small>
    @endif
</span>
