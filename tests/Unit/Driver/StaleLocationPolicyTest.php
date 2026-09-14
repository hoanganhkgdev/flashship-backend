<?php

namespace Tests\Unit\Driver;

use Carbon\CarbonImmutable;
use Modules\Driver\Services\DriverLocationService;
use Modules\Driver\Services\StaleLocationPolicy;
use PHPUnit\Framework\TestCase;

class StaleLocationPolicyTest extends TestCase
{
    public function test_warning_threshold_matches_dispatch_location_freshness(): void
    {
        $this->assertSame(
            DriverLocationService::POS_MAX_AGE_SECS,
            StaleLocationPolicy::STALE_AFTER_SECONDS,
        );
    }

    public function test_fresh_location_extends_the_online_expiry(): void
    {
        $now = CarbonImmutable::parse('2026-09-14 10:05:00', 'Asia/Ho_Chi_Minh');
        $gpsAt = $now->subSeconds(30);

        $warningDueAt = (new StaleLocationPolicy)->warningDueTimestamp([
            'lat' => 10.0,
            'lng' => 105.0,
            'updated_at' => $gpsAt->getTimestampMs(),
        ], $now->subMinutes(10), $now);

        $this->assertSame($gpsAt->addSeconds(180)->getTimestamp(), $warningDueAt);
    }

    public function test_missing_location_warns_three_minutes_after_going_online(): void
    {
        $now = CarbonImmutable::parse('2026-09-14 10:05:00', 'Asia/Ho_Chi_Minh');
        $onlineSince = $now->subMinutes(3);

        $warningDueAt = (new StaleLocationPolicy)->warningDueTimestamp(null, $onlineSince, $now);

        $this->assertSame($onlineSince->addSeconds(180)->getTimestamp(), $warningDueAt);
    }

    public function test_location_from_a_previous_session_does_not_expire_the_new_session_early(): void
    {
        $now = CarbonImmutable::parse('2026-09-14 10:05:00', 'Asia/Ho_Chi_Minh');
        $onlineSince = $now->subSeconds(30);

        $warningDueAt = (new StaleLocationPolicy)->warningDueTimestamp([
            'lat' => 10.0,
            'lng' => 105.0,
            'updated_at' => $now->subHour()->getTimestampMs(),
        ], $onlineSince, $now);

        $this->assertSame($onlineSince->addSeconds(180)->getTimestamp(), $warningDueAt);
    }

    public function test_future_timestamp_is_not_trusted(): void
    {
        $now = CarbonImmutable::parse('2026-09-14 10:05:00', 'Asia/Ho_Chi_Minh');
        $onlineSince = $now->subSeconds(30);

        $warningDueAt = (new StaleLocationPolicy)->warningDueTimestamp([
            'lat' => 10.0,
            'lng' => 105.0,
            'updated_at' => $now->addMinute()->getTimestampMs(),
        ], $onlineSince, $now);

        $this->assertSame($onlineSince->addSeconds(180)->getTimestamp(), $warningDueAt);
    }

    public function test_driver_has_one_full_minute_after_the_warning(): void
    {
        $notifiedAt = CarbonImmutable::parse('2026-09-14 10:07:13', 'Asia/Ho_Chi_Minh');

        $offlineDueAt = (new StaleLocationPolicy)->offlineDueTimestamp($notifiedAt);

        $this->assertSame($notifiedAt->addMinute()->getTimestamp(), $offlineDueAt);
    }
}
