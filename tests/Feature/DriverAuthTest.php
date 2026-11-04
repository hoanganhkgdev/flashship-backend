<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\FirebasePhoneVerifier;
use Tests\TestCase;

/** Đăng nhập / đăng ký / đặt lại mật khẩu của tài xế. */
class DriverAuthTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Auth', 'slug' => 'auth-'.uniqid(), 'is_active' => true]);
        $this->phone = '09'.random_int(10000000, 99999999);
        // id_token "ok:<số>" = Firebase đã xác minh <số>.
        FirebasePhoneVerifier::$fake = fn (string $t) => str_starts_with($t, 'ok:') ? substr($t, 3) : null;
        RateLimiter::clear('driver-login:phone:'.$this->phone);
    }

    protected function tearDown(): void
    {
        FirebasePhoneVerifier::$fake = null;
        RateLimiter::clear('driver-login:phone:'.$this->phone);
        parent::tearDown();
    }

    private function driver(): User
    {
        return User::create([
            'name' => 'Driver', 'phone' => $this->phone, 'email' => uniqid().'@test.local',
            'password' => bcrypt('dung-mat-khau'), 'user_type' => 'driver', 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function login(string $login, string $password)
    {
        return $this->postJson('/api/auth/login', ['login' => $login, 'password' => $password, 'device_id' => 'dev-1']);
    }

    public function test_account_locks_after_five_wrong_passwords(): void
    {
        $this->driver();
        foreach (range(1, 5) as $i) {
            $this->login($this->phone, 'sai-'.$i)->assertStatus(422);
        }
        // Đúng mật khẩu cũng bị chặn trong lúc khoá; đổi dạng số (84…) không lách được.
        $this->login($this->phone, 'dung-mat-khau')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->login('84'.substr($this->phone, 1), 'dung-mat-khau')->assertStatus(429);
    }

    public function test_login_accepts_84_format_and_success_clears_counter(): void
    {
        $this->driver();
        $this->login($this->phone, 'sai')->assertStatus(422);
        $this->login('84'.substr($this->phone, 1), 'dung-mat-khau')->assertOk();
        $this->assertSame(0, RateLimiter::attempts('driver-login:phone:'.$this->phone));
    }

    public function test_register_normalizes_phone_and_blocks_duplicate_in_other_format(): void
    {
        $payload = fn (string $phone) => [
            'phone' => $phone, 'id_token' => 'ok:'.$this->phone, 'name' => 'Tài xế mới',
            'password' => 'mat-khau-1', 'city_id' => $this->city->id, 'device_id' => 'dev-1',
        ];

        $this->postJson('/api/auth/verify-otp-register', $payload('84'.substr($this->phone, 1)))->assertCreated();
        $this->assertDatabaseHas('users', ['phone' => $this->phone, 'user_type' => 'driver']);

        $this->postJson('/api/auth/verify-otp-register', $payload($this->phone))->assertStatus(422);
        $this->assertSame(1, User::where('phone', $this->phone)->count());
    }

    public function test_register_rejects_non_image_avatar(): void
    {
        $this->post('/api/auth/verify-otp-register', [
            'phone' => $this->phone, 'id_token' => 'ok:'.$this->phone, 'name' => 'X',
            'password' => 'mat-khau-1', 'city_id' => $this->city->id, 'device_id' => 'dev-1',
            'avatar' => UploadedFile::fake()->createWithContent('a.html', '<script>alert(1)</script>'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('avatar');
        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_reset_password_revokes_all_tokens(): void
    {
        $driver = $this->driver();
        $driver->createToken('api_token_old');

        $this->postJson('/api/auth/reset-password', [
            'phone' => $this->phone, 'id_token' => 'ok:'.$this->phone,
            'password' => 'mat-khau-moi', 'password_confirmation' => 'mat-khau-moi',
        ])->assertOk();

        $this->assertSame(0, $driver->tokens()->count());
        $this->login($this->phone, 'mat-khau-moi')->assertOk();
    }

    public function test_reset_password_rejects_token_for_other_phone(): void
    {
        $this->driver();
        $this->postJson('/api/auth/reset-password', [
            'phone' => $this->phone, 'id_token' => 'ok:0900000000',
            'password' => 'mat-khau-moi', 'password_confirmation' => 'mat-khau-moi',
        ])->assertStatus(422);
    }
}
