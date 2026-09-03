<?php

namespace Tests\Unit\Driver;

use Carbon\Carbon;
use Modules\Driver\Console\Commands\ScoreShiftSessionsCommand;
use PHPUnit\Framework\TestCase;

class ScoreShiftAssignmentTest extends TestCase
{
    public function test_assignment_approved_after_shift_start_is_not_scored_retroactively(): void
    {
        $command = new class extends ScoreShiftSessionsCommand
        {
            public function effective(mixed $assignedAt, Carbon $shiftStart): bool
            {
                return $this->assignmentWasEffective($assignedAt, $shiftStart);
            }
        };

        $shiftStart = Carbon::parse('2026-09-02 18:00:00');

        $this->assertFalse($command->effective('2026-09-03 10:46:18', $shiftStart));
        $this->assertTrue($command->effective('2026-09-02 17:30:00', $shiftStart));
        $this->assertTrue($command->effective(null, $shiftStart));
    }
}
