<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Tests\TestCase;

/** Token FCM không còn tồn tại thì bị bỏ khỏi tài khoản, không gửi lại vô ích. */
class FcmTokenCleanupTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // Trả lại FCMService thật (singleton) để test khác không dùng bản giả.
        (fn () => self::resetInstance())->call(FCMService::getInstance());
        parent::tearDown();
    }

    public function test_not_found_token_is_forgotten_without_retry(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $user = User::create([
            'name' => 'D', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'x', 'user_type' => 'driver', 'status' => 1, 'fcm_token' => 'dead-token-'.uniqid(),
        ]);

        $calls = 0;
        $fcm = FCMService::getInstance();
        $messaging = \Mockery::mock(\Kreait\Firebase\Contract\Messaging::class);
        $messaging->shouldReceive('send')->andReturnUsing(function () use (&$calls) {
            $calls++;
            throw new \Kreait\Firebase\Exception\Messaging\NotFound('Requested entity was not found.');
        });
        (fn () => $this->messaging = $messaging)->call($fcm);

        $fcm->deliver($user->fcm_token, ['title' => 't', 'body' => 'b']);

        $this->assertSame(1, $calls);
        $this->assertNull($user->fresh()->fcm_token);
    }
}
