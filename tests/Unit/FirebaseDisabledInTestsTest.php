<?php

namespace Tests\Unit;

use Modules\Core\Services\RTDBService;
use Tests\TestCase;

/** Test không bao giờ được chạm Firebase production (máy dev dùng chung service account). */
class FirebaseDisabledInTestsTest extends TestCase
{
    public function test_rtdb_network_is_blocked(): void
    {
        $start = microtime(true);
        $this->assertFalse(RTDBService::performWrite('set', 'test_guard/should_not_exist', ['x' => 1], [0]));
        $this->assertLessThan(2, microtime(true) - $start, 'Không được thực sự gọi mạng');
    }

    public function test_session_device_read_is_blocked(): void
    {
        $this->expectException(\Throwable::class);
        RTDBService::getSessionDevice(1);
    }

    public function test_custom_token_signing_still_works_offline(): void
    {
        $this->assertNotNull(RTDBService::createCustomAuthToken('driver_1_dev', ['driver_id' => '1']));
    }
}
