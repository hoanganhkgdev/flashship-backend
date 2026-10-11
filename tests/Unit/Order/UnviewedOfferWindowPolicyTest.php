<?php

namespace Tests\Unit\Order;

use Modules\Order\Services\UnviewedOfferWindowPolicy;
use PHPUnit\Framework\TestCase;

class UnviewedOfferWindowPolicyTest extends TestCase
{
    public function test_warns_after_two_unviewed_offers(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            $this->timedOut(),
            $this->timedOut(),
        ], null);

        $this->assertTrue($result['should_warn']);
        $this->assertFalse($result['should_offline']);
    }

    public function test_viewing_an_offer_does_not_erase_two_previous_misses(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            $this->timedOut(),
            $this->timedOut(viewed: true),
            $this->timedOut(),
            $this->timedOut(),
        ], null);

        $this->assertSame(3, $result['unviewed']);
        $this->assertTrue($result['should_offline']);
    }

    public function test_accepted_and_declined_offers_are_good_samples(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            $this->timedOut(),
            ['result' => 'accepted', 'viewed_at' => null],
            ['result' => 'declined', 'viewed_at' => now()],
            $this->timedOut(viewed: true),
            $this->timedOut(),
        ], null);

        $this->assertSame(2, $result['unviewed']);
        $this->assertFalse($result['should_offline']);
    }

    public function test_only_five_most_recent_samples_are_counted(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'accepted', 'viewed_at' => now()],
            ['result' => 'accepted', 'viewed_at' => now()],
            $this->timedOut(),
            $this->timedOut(),
            ['result' => 'accepted', 'viewed_at' => now()],
            $this->timedOut(),
        ], null);

        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['unviewed']);
        $this->assertFalse($result['should_offline']);
    }

    public function test_offer_closed_early_because_another_driver_won_is_ignored(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            [
                'result' => 'expired',
                'viewed_at' => null,
                'expires_at' => now()->addSeconds(20),
                'responded_at' => now(),
            ],
            $this->timedOut(),
        ], null);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['unviewed']);
        $this->assertFalse($result['should_warn']);
        $this->assertFalse($result['should_offline']);
    }

    private function timedOut(bool $viewed = false): array
    {
        return [
            'result' => 'expired',
            'viewed_at' => $viewed ? now()->subSecond() : null,
            'expires_at' => now()->subSecond(),
            'responded_at' => now(),
        ];
    }
}
