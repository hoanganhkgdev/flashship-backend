<x-filament-widgets::widget>
    @php $max = max(1, ...array_values($distribution)); @endphp
    <style>
        .rating-overview { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)) minmax(280px,1.6fr); gap:10px; }
        .rating-kpi,.rating-distribution { padding:15px; border:1px solid #e5e7eb; border-radius:14px; background:#fff; }
        .rating-kpi { display:flex; flex-direction:column; justify-content:center; min-height:104px; }
        .rating-kpi span { color:#64748b; font-size:12px; }
        .rating-kpi strong { margin-top:7px; color:#0f172a; font-size:25px; font-weight:650; line-height:1; }
        .rating-kpi small { margin-top:7px; color:#94a3b8; font-size:11px; }
        .rating-bars { display:grid; gap:6px; margin-top:8px; }
        .rating-bar { display:grid; grid-template-columns:28px minmax(0,1fr) 28px; align-items:center; gap:7px; color:#64748b; font-size:11px; }
        .rating-bar i { height:6px; overflow:hidden; border-radius:999px; background:#f1f5f9; }
        .rating-bar i::after { display:block; width:var(--rating-width); height:100%; border-radius:inherit; background:var(--rating-color); content:''; }
        .dark .rating-kpi,.dark .rating-distribution { border-color:#293142; background:#171b25; }
        .dark .rating-kpi strong { color:#f8fafc; }
        @media(max-width:900px){.rating-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.rating-distribution{grid-column:1/-1}}
    </style>
    <div class="rating-overview">
        <div class="rating-kpi"><span>Tổng đánh giá</span><strong>{{ number_format($total) }}</strong><small>Phản hồi đã ghi nhận</small></div>
        <div class="rating-kpi"><span>Điểm trung bình</span><strong style="color:{{ $averageColor }}">{{ $average }} ★</strong><small>Trên thang điểm 5</small></div>
        <div class="rating-kpi"><span>Cần xử lý</span><strong style="color:{{ $low ? '#ef4444' : '#16a34a' }}">{{ number_format($low) }}</strong><small>Đánh giá từ 1–2 sao</small></div>
        <div class="rating-distribution">
            <span style="color:#475569;font-size:12px;font-weight:600">Phân bố đánh giá</span>
            <div class="rating-bars">
                @foreach([5 => '#22c55e', 4 => '#84cc16', 3 => '#f59e0b', 2 => '#f97316', 1 => '#ef4444'] as $star => $color)
                    <div class="rating-bar" style="--rating-width:{{ ($distribution[$star] / $max) * 100 }}%;--rating-color:{{ $color }}"><span>{{ $star }}★</span><i></i><b>{{ $distribution[$star] }}</b></div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
