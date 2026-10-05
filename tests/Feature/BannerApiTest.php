<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Banner;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Tests\TestCase;

class BannerApiTest extends TestCase
{
    use DatabaseTransactions;

    private City $cityA;

    private City $cityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::table('banners')->delete();
        $this->cityA = City::create(['name' => 'Thành phố A', 'slug' => 'banner-test-a-'.uniqid()]);
        $this->cityB = City::create(['name' => 'Thành phố B', 'slug' => 'banner-test-b-'.uniqid()]);
    }

    private function banner(array $attrs = []): Banner
    {
        return Banner::create($attrs + ['title' => 'Banner '.uniqid(), 'image_path' => 'banners/x.png', 'is_active' => true, 'sort_order' => 0]);
    }

    private function customerIn(City $city): User
    {
        return User::create([
            'name' => 'Khách banner', 'email' => 'banner-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $city->id,
        ]);
    }

    private function titles($response): array
    {
        return collect($response->json('data'))->pluck('title')->all();
    }

    public function test_logged_in_customer_gets_own_city_and_global_banners_only(): void
    {
        $this->banner(['title' => 'Chung', 'city_id' => null]);
        $this->banner(['title' => 'Của A', 'city_id' => $this->cityA->id]);
        $this->banner(['title' => 'Của B', 'city_id' => $this->cityB->id]);

        Sanctum::actingAs($this->customerIn($this->cityA));
        $titles = $this->titles($this->getJson('/api/banners')->assertOk());

        $this->assertEqualsCanonicalizing(['Chung', 'Của A'], $titles);
    }

    public function test_guest_without_city_only_gets_global_banners(): void
    {
        $this->banner(['title' => 'Chung', 'city_id' => null]);
        $this->banner(['title' => 'Của A', 'city_id' => $this->cityA->id]);

        $this->assertSame(['Chung'], $this->titles($this->getJson('/api/banners')->assertOk()));
    }

    public function test_explicit_city_id_query_still_works(): void
    {
        $this->banner(['title' => 'Chung', 'city_id' => null]);
        $this->banner(['title' => 'Của A', 'city_id' => $this->cityA->id]);
        $this->banner(['title' => 'Của B', 'city_id' => $this->cityB->id]);

        $titles = $this->titles($this->getJson('/api/banners?city_id='.$this->cityB->id)->assertOk());
        $this->assertEqualsCanonicalizing(['Chung', 'Của B'], $titles);
    }

    public function test_scheduled_expired_and_hidden_banners_are_not_returned(): void
    {
        $this->banner(['title' => 'Đang chạy', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->banner(['title' => 'Không hẹn giờ']);
        $this->banner(['title' => 'Chưa tới giờ', 'starts_at' => now()->addHour()]);
        $this->banner(['title' => 'Đã hết hạn', 'ends_at' => now()->subMinute()]);
        $this->banner(['title' => 'Đã ẩn', 'is_active' => false]);

        $this->assertEqualsCanonicalizing(['Đang chạy', 'Không hẹn giờ'], $this->titles($this->getJson('/api/banners')->assertOk()));
    }

    public function test_response_keeps_image_path_and_adds_absolute_image_url(): void
    {
        $this->banner(['title' => 'Có ảnh', 'image_path' => 'banners/abc.png']);

        $item = $this->getJson('/api/banners')->assertOk()->json('data.0');
        $this->assertSame('banners/abc.png', $item['image_path']);
        $this->assertStringEndsWith('/storage/banners/abc.png', $item['image_url']);
        $this->assertStringStartsWith('http', $item['image_url']);
    }

    public function test_status_key_covers_every_state(): void
    {
        $this->assertSame('live', $this->banner()->statusKey());
        $this->assertSame('inactive', $this->banner(['is_active' => false])->statusKey());
        $this->assertSame('scheduled', $this->banner(['starts_at' => now()->addDay()])->statusKey());
        $this->assertSame('expired', $this->banner(['ends_at' => now()->subDay()])->statusKey());
    }
}
