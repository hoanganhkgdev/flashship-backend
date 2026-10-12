<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\City;
use Modules\Shop\Models\ShopDestinationPrice;
use Modules\Shop\Services\ShopPricingService;
use Tests\TestCase;

class ShopDestinationPricingTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    // Nội ô và Sân bay lấy từ toạ độ bảng giá Phú Quốc.
    private const DUONG_DONG = [10.2185, 103.9640];

    private const AIRPORT = [10.16282, 103.99801];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Cache::flush();
        Http::fake(['*' => Http::response([], 500)]);
        $this->city = City::create(['name' => 'Dest pricing', 'slug' => 'dest-pricing-'.uniqid(), 'is_active' => true]);
    }

    private function zone(string $name, array $at, int $fee, array $extra = []): ShopDestinationPrice
    {
        return ShopDestinationPrice::create($extra + [
            'city_id' => $this->city->id, 'name' => $name, 'lat' => $at[0], 'lng' => $at[1],
            'radius_km' => 1.5, 'fee' => $fee,
        ]);
    }

    private function estimate(array $from, array $to, ?int $cityId = null, ?float $kg = null): array
    {
        return ShopPricingService::estimateFromCoords('food', $from[0], $from[1], $to[0], $to[1], $kg, $cityId ?? $this->city->id);
    }

    public function test_destination_inside_a_zone_uses_the_fixed_price(): void
    {
        $this->zone('Nội ô', self::DUONG_DONG, 10000, ['is_origin' => true, 'radius_km' => 2.5]);
        $this->zone('Sân bay', self::AIRPORT, 55000, ['fee_max' => 60000]);

        $result = $this->estimate(self::DUONG_DONG, [10.1630, 103.9985]);

        $this->assertSame('destination', $result['pricing_mode']);
        $this->assertSame('Sân bay', $result['destination_name']);
        $this->assertSame(55000, $result['base_fee']);
    }

    public function test_destination_outside_every_zone_falls_back_to_distance_pricing(): void
    {
        $this->zone('Nội ô', self::DUONG_DONG, 10000, ['is_origin' => true, 'radius_km' => 2.5]);
        $this->zone('Sân bay', self::AIRPORT, 55000);

        $result = $this->estimate(self::DUONG_DONG, [10.30, 103.90]);

        $this->assertSame('distance', $result['pricing_mode']);
        $this->assertNull($result['destination_name']);
    }

    public function test_pickup_outside_the_origin_zone_uses_distance_pricing(): void
    {
        $this->zone('Nội ô', self::DUONG_DONG, 10000, ['is_origin' => true, 'radius_km' => 2.5]);
        $this->zone('Sân bay', self::AIRPORT, 55000);

        $result = $this->estimate([10.0150, 104.0146], self::AIRPORT);

        $this->assertSame('distance', $result['pricing_mode']);
    }

    public function test_overlapping_zones_pick_the_one_with_the_nearest_center(): void
    {
        $this->zone('Xa', [10.2300, 103.9640], 40000, ['radius_km' => 3]);
        $this->zone('Gần', [10.2330, 103.9640], 20000, ['radius_km' => 3]);

        $result = $this->estimate([10.2300, 103.9640], [10.2325, 103.9640]);

        $this->assertSame('Gần', $result['destination_name']);
    }

    public function test_inactive_zone_and_other_city_zone_are_ignored(): void
    {
        $this->zone('Tạm tắt', self::AIRPORT, 55000, ['is_active' => false]);
        $other = City::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true]);
        ShopDestinationPrice::create([
            'city_id' => $other->id, 'name' => 'Khác', 'lat' => self::AIRPORT[0], 'lng' => self::AIRPORT[1],
            'radius_km' => 1.5, 'fee' => 99000,
        ]);

        $result = $this->estimate(self::DUONG_DONG, self::AIRPORT);

        $this->assertSame('distance', $result['pricing_mode']);
    }

    public function test_weight_and_night_surcharges_still_apply_on_top_of_the_fixed_price(): void
    {
        $this->zone('Sân bay', self::AIRPORT, 55000);

        $result = ShopPricingService::estimateFromCoords(
            'parcel', self::DUONG_DONG[0], self::DUONG_DONG[1], self::AIRPORT[0], self::AIRPORT[1], 2.0, $this->city->id
        );

        $this->assertSame(55000, $result['base_fee']);
        $this->assertSame(55000 + $result['weight_surcharge'] + $result['night_surcharge'], $result['fee']);
    }
}
