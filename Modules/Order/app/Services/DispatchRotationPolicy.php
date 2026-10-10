<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;

final class DispatchRotationPolicy
{
    /**
     * Chia ứng viên thành các dải điểm tương đương tính từ người tốt nhất.
     * Dải tốt hơn luôn đứng trước; trong cùng dải, người chưa từng được phát
     * hoặc đã lâu chưa có cơ hội sẽ đứng trước. Nhờ vậy một chênh lệch rất nhỏ
     * về khoảng cách/điểm không khiến cùng một tài xế luôn chiếm lượt đầu.
     *
     * @param  callable(mixed): float  $score
     */
    public static function sort(Collection $drivers, callable $score, float $fairScoreBand = 0): Collection
    {
        // Tính điểm một lần cho mỗi tài xế thay vì mỗi lần so sánh.
        $scores = $drivers->mapWithKeys(fn ($driver) => [$driver->id => $score($driver)]);
        $bestScore = $scores->isEmpty() ? 0.0 : (float) $scores->max();

        $bandOf = static function ($driver) use ($scores, $bestScore, $fairScoreBand): int {
            if ($fairScoreBand <= 0) {
                return 0;
            }

            $distanceFromBest = max(0.0, $bestScore - (float) $scores[$driver->id]);

            // Chênh lệch đúng bằng ngưỡng vẫn nằm trong cùng một dải.
            return max(0, (int) ceil(($distanceFromBest / $fairScoreBand) - 1e-9) - 1);
        };

        return $drivers->sort(function ($left, $right) use ($scores, $bandOf, $fairScoreBand) {
            if ($fairScoreBand > 0) {
                $byBand = $bandOf($left) <=> $bandOf($right);
                if ($byBand !== 0) {
                    return $byBand;
                }
            } else {
                $byScore = $scores[$right->id] <=> $scores[$left->id];
                if ($byScore !== 0) {
                    return $byScore;
                }
            }

            $leftAt = $left->getAttribute('_last_offered_timestamp');
            $rightAt = $right->getAttribute('_last_offered_timestamp');
            if ($leftAt === null && $rightAt !== null) {
                return -1;
            }
            if ($leftAt !== null && $rightAt === null) {
                return 1;
            }
            if ($leftAt !== $rightAt) {
                return $leftAt <=> $rightAt;
            }

            $byScore = $scores[$right->id] <=> $scores[$left->id];
            if ($byScore !== 0) {
                return $byScore;
            }

            return $left->id <=> $right->id;
        })->values();
    }
}
