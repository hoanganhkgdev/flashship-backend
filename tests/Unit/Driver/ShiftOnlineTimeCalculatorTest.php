<?php

namespace Tests\Unit\Driver;

use Carbon\Carbon;
use Modules\Driver\Services\ShiftOnlineTimeCalculator;
use PHPUnit\Framework\TestCase;

class ShiftOnlineTimeCalculatorTest extends TestCase
{
    public function test_it_counts_only_the_parts_of_online_sessions_inside_the_shift(): void
    {
        $sessions = [
            $this->session('2026-09-13 12:30:00', '2026-09-13 14:00:00'),
            $this->session('2026-09-13 16:00:00', '2026-09-13 18:30:00'),
        ];

        $seconds = (new ShiftOnlineTimeCalculator)->seconds(
            $sessions,
            Carbon::parse('2026-09-13 13:00:00'),
            Carbon::parse('2026-09-13 18:00:00'),
        );

        $this->assertSame(3 * 60 * 60, $seconds);
    }

    public function test_it_merges_overlapping_sessions_instead_of_counting_twice(): void
    {
        $sessions = [
            $this->session('2026-09-13 13:00:00', '2026-09-13 15:00:00'),
            $this->session('2026-09-13 14:00:00', '2026-09-13 16:00:00'),
        ];

        $seconds = (new ShiftOnlineTimeCalculator)->seconds(
            $sessions,
            Carbon::parse('2026-09-13 13:00:00'),
            Carbon::parse('2026-09-13 18:00:00'),
        );

        $this->assertSame(3 * 60 * 60, $seconds);
    }

    public function test_an_open_session_is_capped_at_the_end_of_the_shift(): void
    {
        $sessions = [$this->session('2026-09-13 14:00:00', null)];

        $seconds = (new ShiftOnlineTimeCalculator)->seconds(
            $sessions,
            Carbon::parse('2026-09-13 13:00:00'),
            Carbon::parse('2026-09-13 18:00:00'),
        );

        $this->assertSame(4 * 60 * 60, $seconds);
    }

    private function session(string $startedAt, ?string $endedAt): object
    {
        return (object) [
            'started_at' => Carbon::parse($startedAt),
            'ended_at' => $endedAt ? Carbon::parse($endedAt) : null,
        ];
    }
}
