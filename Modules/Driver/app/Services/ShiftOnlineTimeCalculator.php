<?php

namespace Modules\Driver\Services;

use Carbon\CarbonInterface;

class ShiftOnlineTimeCalculator
{
    /**
     * Tính hợp các phiên Online giao với cửa sổ ca [start, end).
     *
     * @param  iterable<int, object{started_at: CarbonInterface, ended_at: CarbonInterface|null}>  $sessions
     */
    public function seconds(iterable $sessions, CarbonInterface $shiftStart, CarbonInterface $shiftEnd): int
    {
        $intervals = [];

        foreach ($sessions as $session) {
            $start = max($session->started_at->getTimestamp(), $shiftStart->getTimestamp());
            $end = min(
                $session->ended_at?->getTimestamp() ?? $shiftEnd->getTimestamp(),
                $shiftEnd->getTimestamp(),
            );

            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $seconds = 0;
        $mergedEnd = null;

        foreach ($intervals as [$start, $end]) {
            if ($mergedEnd === null || $start > $mergedEnd) {
                $seconds += $end - $start;
                $mergedEnd = $end;
            } elseif ($end > $mergedEnd) {
                $seconds += $end - $mergedEnd;
                $mergedEnd = $end;
            }
        }

        return $seconds;
    }
}
