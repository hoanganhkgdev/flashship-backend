<x-filament-widgets::widget>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5" style="margin:0.5rem;">

        {{-- Header --}}
        <div style="display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.25rem;">
            <div style="display:flex; align-items:baseline; gap:6px;">
                <span class="text-xs text-gray-400">Tổng</span>
                <span class="text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($total) }}</span>
                <span class="text-xs text-gray-400">đánh giá</span>
            </div>

            <div style="width:1px; height:24px; background:#e5e7eb;"></div>

            <div style="display:flex; align-items:center; gap:4px;">
                <span class="text-2xl font-bold" style="color:{{ $averageColor }}">{{ $average }}</span>
                <span style="font-size:1.1rem; color:{{ $averageColor }}">★</span>
                <span class="text-xs text-gray-400">trung bình</span>
            </div>

            <div style="width:1px; height:24px; background:#e5e7eb;"></div>

            <div style="display:flex; align-items:center; gap:6px;">
                @if($low === 0)
                    <span class="text-xs font-semibold text-green-600">Không có đánh giá xấu ✓</span>
                @else
                    <span class="text-2xl font-bold text-red-500">{{ $low }}</span>
                    <span class="text-xs text-gray-400">đánh giá xấu (≤ 2★)</span>
                @endif
            </div>
        </div>

        {{-- Biểu đồ --}}
        <canvas id="ratingChart" height="90"></canvas>

    </div>

    <script>
    (function () {
        const data = {
            labels: ['5 ★', '4 ★', '3 ★', '2 ★', '1 ★'],
            datasets: [{
                data: [{{ $distribution[5] }}, {{ $distribution[4] }}, {{ $distribution[3] }}, {{ $distribution[2] }}, {{ $distribution[1] }}],
                backgroundColor: ['#22c55e', '#84cc16', '#f59e0b', '#f97316', '#ef4444'],
                borderRadius: 6,
                borderSkipped: false,
            }]
        };

        function initChart() {
            const canvas = document.getElementById('ratingChart');
            if (!canvas || !window.Chart) { setTimeout(initChart, 200); return; }
            if (canvas._chartInstance) canvas._chartInstance.destroy();
            canvas._chartInstance = new Chart(canvas, {
                type: 'bar',
                data: data,
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.x + ' đánh giá' } }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: '#9ca3af' },
                            grid: { color: '#f3f4f610' },
                        },
                        y: {
                            ticks: {
                                font: { weight: '600', size: 13 },
                                color: (ctx) => ['#22c55e','#84cc16','#f59e0b','#f97316','#ef4444'][ctx.index],
                            },
                            grid: { display: false },
                        }
                    }
                }
            });
        }

        initChart();
    })();
    </script>
</x-filament-widgets::widget>
