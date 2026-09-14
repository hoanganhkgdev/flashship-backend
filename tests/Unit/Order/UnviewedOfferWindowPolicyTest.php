<?php

namespace Tests\Unit\Order;

use Modules\Order\Services\UnviewedOfferWindowPolicy;
use PHPUnit\Framework\TestCase;

class UnviewedOfferWindowPolicyTest extends TestCase
{
    public function test_warns_after_two_unviewed_offers(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
        ]);

        $this->assertTrue($result['should_warn']);
        $this->assertFalse($result['should_offline']);
    }

    public function test_viewing_an_offer_does_not_erase_two_previous_misses(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => now()],
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
        ]);

        $this->assertSame(3, $result['unviewed']);
        $this->assertTrue($result['should_offline']);
    }

    public function test_accepted_and_declined_offers_are_good_samples(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'accepted', 'viewed_at' => null],
            ['result' => 'declined', 'viewed_at' => now()],
            ['result' => 'expired', 'viewed_at' => now()],
            ['result' => 'expired', 'viewed_at' => null],
        ]);

        $this->assertSame(2, $result['unviewed']);
        $this->assertFalse($result['should_offline']);
    }

    public function test_only_five_most_recent_samples_are_counted(): void
    {
        $result = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'accepted', 'viewed_at' => now()],
            ['result' => 'accepted', 'viewed_at' => now()],
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'accepted', 'viewed_at' => now()],
            ['result' => 'expired', 'viewed_at' => null],
        ]);

        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['unviewed']);
        $this->assertFalse($result['should_offline']);
    }
}
