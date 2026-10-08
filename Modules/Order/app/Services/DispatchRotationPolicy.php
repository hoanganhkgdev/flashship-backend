<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;

final class DispatchRotationPolicy
{
    /**
     * Tài xế chưa từng được phát đứng trước; còn lại xếp theo lần phát gần
     * nhất từ cũ tới mới. Điểm composite chỉ phá hoà trong cùng một lượt.
     *
     * @param  callable(mixed): float  $score
     */
    public static function sort(Collection $drivers, callable $score): Collection
    {
        return $drivers->sort(function ($left, $right) use ($score) {
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

            $byScore = $score($right) <=> $score($left);

            return $byScore !== 0 ? $byScore : ($left->id <=> $right->id);
        })->values();
    }
}
