<?php

namespace Tests\Unit\Driver;

use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\ReferralService;
use PHPUnit\Framework\TestCase;

class ReferralServiceTest extends TestCase
{
    public function test_normalize_code_strips_symbols_and_uppercases(): void
    {
        $this->assertSame('AB3K9X', ReferralService::normalizeCode(' ab3-k9 x '));
        $this->assertSame('', ReferralService::normalizeCode(null));
    }

    public function test_same_person_ignores_phone_prefix_format(): void
    {
        $this->assertTrue(ReferralService::isSamePerson('0901234567', '+84901234567'));
        $this->assertTrue(ReferralService::isSamePerson('84901234567', '0901234567'));
        $this->assertFalse(ReferralService::isSamePerson('0901234567', '0901234568'));
        $this->assertFalse(ReferralService::isSamePerson('', ''));
        $this->assertFalse(ReferralService::isSamePerson(null, '0901234567'));
    }

    public function test_referral_settings_have_defaults(): void
    {
        $this->assertSame('50000', OperationalSettings::DEFAULTS['referral.reward_amount']);
        $this->assertSame('1', OperationalSettings::DEFAULTS['referral.min_orders']);
    }
}
