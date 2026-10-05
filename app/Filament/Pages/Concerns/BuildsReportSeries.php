<?php

namespace App\Filament\Pages\Concerns;

use Carbon\Carbon;

/**
 * Phần dùng chung của các trang báo cáo theo kỳ: chia ngày/tuần/tháng, dựng dữ liệu biểu đồ,
 * định dạng số tiền ngắn gọn. Cả trang Doanh thu và Thu nhập tài xế đều dùng.
 */
trait BuildsReportSeries
{
    /** Khoảng ngắn xem theo ngày; dài hơn gộp theo tuần/tháng để biểu đồ còn đọc được. */
    private function granularity(int $days): string
    {
        return $days <= 62 ? 'day' : ($days <= 200 ? 'week' : 'month');
    }

    /** @return array<string, array{key: string, label: string, weekend: bool}> */
    private function buckets(Carbon $from, Carbon $to, string $granularity): array
    {
        $out = [];
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            [$key, $end] = match ($granularity) {
                'week' => [(string) ($cursor->isoWeekYear * 100 + $cursor->isoWeek), $cursor->copy()->endOfWeek()->startOfDay()],
                'month' => [$cursor->format('Y-m'), $cursor->copy()->endOfMonth()->startOfDay()],
                default => [$cursor->toDateString(), $cursor->copy()],
            };
            if ($end->gt($last)) {
                $end = $last->copy();
            }

            $label = match ($granularity) {
                'week' => $cursor->equalTo($end) ? $cursor->format('d/m') : $cursor->format('d/m').'–'.$end->format('d/m'),
                'month' => $cursor->format('m/Y'),
                default => $cursor->format('d/m'),
            };
            $out[$key] = ['key' => $key, 'label' => $label, 'weekend' => $granularity === 'day' && $cursor->isWeekend()];

            $cursor = $end->copy()->addDay();
        }

        return $out;
    }

    private function bucketSql(string $column, string $granularity): string
    {
        return match ($granularity) {
            'week' => "YEARWEEK($column, 3)",
            'month' => "DATE_FORMAT($column, '%Y-%m')",
            default => "DATE($column)",
        };
    }

    /** Dữ liệu vẽ biểu đồ: cột doanh thu, đường kỳ trước và đường số đơn (toạ độ % trong khung 0–100). */
    private function chartData(array $rows): array
    {
        $count = max(1, count($rows));
        $maxRevenue = max(1, ...array_map(fn ($r) => max($r['revenue'], (int) ($r['prev_revenue'] ?? 0)), $rows ?: [['revenue' => 0]]));
        $axisMax = $this->niceMax($maxRevenue);
        $maxOrders = max(1, ...array_column($rows ?: [['completed' => 0]], 'completed'));
        $step = (int) ceil($count / 12);

        $x = fn (int $i) => round(($i + 0.5) / $count * 100, 3);
        $y = fn (float|int $v, float|int $max) => round(100 - $v / $max * 100, 3);

        $points = fn (callable $value, float|int $max) => collect($rows)
            ->map(fn ($r, $i) => ($v = $value($r)) === null ? null : $x($i).','.$y($v, $max))
            ->filter()->implode(' ');

        return [
            'bars' => array_map(fn ($r, $i) => $r + [
                'height' => $r['revenue'] ? max(2, round($r['revenue'] / $axisMax * 100, 2)) : 0,
                'show_label' => $i % $step === 0,
            ], $rows, array_keys($rows)),
            'axis' => array_map(fn ($p) => ['pct' => $p, 'label' => $this->shortMoney((int) round($axisMax * $p / 100))], [100, 75, 50, 25, 0]),
            'has_previous' => collect($rows)->contains(fn ($r) => $r['prev_revenue'] !== null),
            'prev_points' => $points(fn ($r) => $r['prev_revenue'], $axisMax),
            'orders_points' => $points(fn ($r) => $r['completed'], $maxOrders),
            'spark_points' => collect($rows)->map(fn ($r, $i) => $x($i).','.$y($r['revenue'], $maxRevenue))->implode(' '),
            'max_orders' => $maxOrders,
        ];
    }

    /** Trần trục tung "tròn" để 4 vạch chia ra các mốc dễ đọc (1,5 tr / 3 tr / 4,5 tr / 6 tr...). */
    private function niceMax(int $value): int
    {
        $raw = max(1, $value) / 4;
        $magnitude = 10 ** (int) floor(log10($raw));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $magnitude >= $raw) {
                return (int) round($step * $magnitude * 4);
            }
        }

        return (int) ($raw * 4);
    }

    private function shortMoney(int $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => rtrim(rtrim(number_format($value / 1_000_000_000, 1, ',', '.'), '0'), ',').' tỷ',
            $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').' tr',
            $value >= 1_000 => number_format($value / 1_000, 0, ',', '.').'k',
            default => (string) $value,
        };
    }

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
