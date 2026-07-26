<div>
@if ($this->canManage())
<div x-data="{ open: false }" x-on:click.outside="open = false" style="position:relative;">
    @php
        $anyRaining = $this->cities->contains('is_rain_mode', true);
    @endphp

    <button
        type="button"
        x-on:click="open = !open"
        title="Chế độ trời mưa"
        style="position:relative; display:flex; align-items:center; justify-content:center; width:2.25rem; height:2.25rem; border-radius:9999px; color:{{ $anyRaining ? '#2563eb' : '#6b7280' }}; background:{{ $anyRaining ? '#dbeafe' : 'transparent' }}; transition:background .15s ease;"
        onmouseover="this.style.background='{{ $anyRaining ? '#bfdbfe' : '#f3f4f6' }}'"
        onmouseout="this.style.background='{{ $anyRaining ? '#dbeafe' : 'transparent' }}'"
    >
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:1.25rem; height:1.25rem;">
            <path d="M6.5 18a4 4 0 0 1-.5-7.97A5.5 5.5 0 0 1 16.9 8.2a4.5 4.5 0 0 1-.4 8.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M8 20l-1 2M12 20l-1 2M16 20l-1 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
        @if ($anyRaining)
        <span style="position:absolute; top:2px; right:2px; width:8px; height:8px; border-radius:50%; background:#2563eb; box-shadow:0 0 0 2px #fff;"></span>
        @endif
    </button>

    <div
        x-show="open" x-cloak x-transition
        style="position:absolute; top:calc(100% + 8px); right:0; z-index:50; width:280px; background:#fff; border-radius:12px; box-shadow:0 10px 32px rgba(0,0,0,0.18); border:1px solid #eef0f2; padding:10px; font-size:13px;"
    >
        <div style="font-weight:700; color:#111827; padding:2px 6px 8px; display:flex; align-items:center; gap:6px;">
            <span>🌧</span> Chế độ trời mưa
        </div>

        @forelse ($this->cities as $city)
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 6px; border-top:1px solid #f1f2f4;">
            <div style="min-width:0;">
                <div style="font-weight:600; color:#111827;">{{ $city->name }}</div>
                @if ($city->is_rain_mode && $city->rain_mode_started_at)
                <div style="font-size:11px; color:#9ca3af;">Bật lúc {{ $city->rain_mode_started_at->format('H:i d/m') }}</div>
                @else
                <div style="font-size:11px; color:#9ca3af;">Đang tắt</div>
                @endif
            </div>
            <button
                type="button"
                wire:click="toggleCity({{ $city->id }})"
                style="flex-shrink:0; width:38px; height:22px; border-radius:9999px; border:none; cursor:pointer; position:relative; background:{{ $city->is_rain_mode ? '#2563eb' : '#e5e7eb' }}; transition:background .15s ease;"
            >
                <span style="position:absolute; top:2px; left:{{ $city->is_rain_mode ? '18px' : '2px' }}; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.3); transition:left .15s ease;"></span>
            </button>
        </div>
        @empty
        <div style="padding:8px 6px; color:#9ca3af;">Không có thành phố nào để quản lý.</div>
        @endforelse

        <div style="font-size:11px; color:#9ca3af; padding:8px 6px 2px; border-top:1px solid #f1f2f4; margin-top:4px;">
            Bật: +5.000đ/đơn vào ví tài xế lúc hoàn thành, tạm miễn phạt điểm lơ đơn. Tự tắt sau 6 tiếng nếu quên.
        </div>
    </div>
</div>
@endif
</div>
