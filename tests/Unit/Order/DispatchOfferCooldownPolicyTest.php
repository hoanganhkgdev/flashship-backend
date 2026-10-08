<?php

namespace Tests\Unit\Order;

use Modules\Order\Services\DispatchOfferCooldownPolicy;
use PHPUnit\Framework\TestCase;

class DispatchOfferCooldownPolicyTest extends TestCase
{
    public function test_decline_cools_down_for_one_minute(): void
    {
        $this->assertSame(60, DispatchOfferCooldownPolicy::seconds(['declined']));
    }

    public function test_one_expiry_cools_down_for_ninety_seconds(): void
    {
        $this->assertSame(90, DispatchOfferCooldownPolicy::seconds(['expired', 'accepted']));
    }

    public function test_two_consecutive_expiries_cool_down_for_five_minutes(): void
    {
        $this->assertSame(300, DispatchOfferCooldownPolicy::seconds(['expired', 'expired']));
    }

    public function test_acceptance_resets_cooldown(): void
    {
        $this->assertSame(0, DispatchOfferCooldownPolicy::seconds(['accepted', 'expired', 'expired']));
    }
}
