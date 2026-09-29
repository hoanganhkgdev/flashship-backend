<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Services\DispatchRadiusPolicy;
use Modules\Order\Services\UnviewedOfferWindowPolicy;
use Tests\TestCase;

class OperationalSettingsTest extends TestCase
{
    // Giá trị ghi trong test phải rollback — nếu không, DB test MySQL dùng
    // chung giữ lại cấu hình lạ (ví dụ grace 5 phút) làm hỏng test khác.
    use DatabaseTransactions;

    private int $cityA;

    private int $cityB;

    protected function setUp(): void
    {
        parent::setUp();

        // Chỉ tạo bảng cho SQLite in-memory; DDL trên MySQL tự commit, phá transaction.
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('group')->default('general');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            Schema::create(OperationalSettings::CITY_TABLE, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('city_id');
                $table->string('key');
                $table->text('value');
                $table->timestamps();
                $table->unique(['city_id', 'key']);
            });
        }

        $now = now();
        DB::table('settings')->upsert(collect(OperationalSettings::DEFAULTS)
            ->map(fn (string $value, string $key) => [
                'key' => $key,
                'value' => $value,
                'group' => 'operations',
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(), ['key'], ['value', 'group', 'updated_at']);
        OperationalSettings::flush();

        // City::created tự khởi tạo bộ cấu hình riêng cho khu vực.
        $this->cityA = City::create(['name' => 'Ops A', 'slug' => 'ops-a-'.uniqid()])->id;
        $this->cityB = City::create(['name' => 'Ops B', 'slug' => 'ops-b-'.uniqid()])->id;
    }

    protected function tearDown(): void
    {
        // Bộ nhớ đệm static sống qua các test trong cùng process.
        OperationalSettings::flush();
        parent::tearDown();
    }

    public function test_new_city_gets_full_copy_of_current_policy(): void
    {
        $this->assertSame(
            count(OperationalSettings::DEFAULTS),
            DB::table(OperationalSettings::CITY_TABLE)->where('city_id', $this->cityA)->count(),
        );
        $this->assertSame(4.0, DispatchRadiusPolicy::radiusForElapsedSeconds(0, $this->cityA));
        $this->assertSame([3 => 1, 6 => 2, 10 => 4], DriverScoreService::streakMilestones($this->cityA));
        $this->assertSame(140, DriverScoreService::weeklyBonusScore($this->cityA));
        $this->assertSame(-2, DriverScoreService::declinePenalty($this->cityA));
        $this->assertSame(5_000, OperationalSettings::rainBonusAmount($this->cityA));
        $this->assertSame(300, OperationalSettings::completionRadiusMeters($this->cityA));
        $this->assertSame(5_000, OperationalSettings::nightSurcharge($this->cityA, 23));
        $this->assertSame(10_000, OperationalSettings::nightSurcharge($this->cityA, 2));
        $this->assertSame(0, OperationalSettings::nightSurcharge($this->cityA, 12));
    }

    public function test_changing_one_city_does_not_affect_another(): void
    {
        OperationalSettings::put([
            'dispatch.max_road_distance_km' => 6.5,
            'driver_score.streak_milestones' => '2:1,5:3',
            'driver_score.weekly_bonus_amount' => 250_000,
            'driver_score.unviewed_window_size' => 4,
            'driver_score.unviewed_limit' => 2,
            'driver_score.shift_low_min_percent' => 55,
            'order.rain_bonus_amount' => 8_000,
            'order.completion_radius_meters' => 450,
            'pricing.night_23_00_amount' => 7_000,
            'dispatch.score_weight' => 20,
        ], $this->cityA);

        $this->assertSame(6.5, DispatchRadiusPolicy::radiusForElapsedSeconds(300, $this->cityA));
        $this->assertSame([2 => 1, 5 => 3], DriverScoreService::streakMilestones($this->cityA));
        $this->assertSame(250_000, DriverScoreService::weeklyBonusAmount($this->cityA));
        $this->assertSame(0.55, OperationalSettings::shiftOnlineThresholds($this->cityA)['low']);
        $this->assertSame(8_000, OperationalSettings::rainBonusAmount($this->cityA));
        $this->assertSame(0.45, OperationalSettings::completionRadiusKm($this->cityA));
        $this->assertSame(7_000, OperationalSettings::nightSurcharge($this->cityA, 23));
        $this->assertSame(20.0, OperationalSettings::dispatchWeights($this->cityA)['score']);
        $this->assertTrue(UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
        ], $this->cityA)['should_offline']);

        // Khu vực B vẫn giữ nguyên chính sách cũ.
        $this->assertSame(4.0, DispatchRadiusPolicy::radiusForElapsedSeconds(300, $this->cityB));
        $this->assertSame([3 => 1, 6 => 2, 10 => 4], DriverScoreService::streakMilestones($this->cityB));
        $this->assertSame(200_000, DriverScoreService::weeklyBonusAmount($this->cityB));
        $this->assertSame(5_000, OperationalSettings::rainBonusAmount($this->cityB));
        $this->assertSame(5_000, OperationalSettings::nightSurcharge($this->cityB, 23));
        $this->assertFalse(UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
        ], $this->cityB)['should_offline']);

        // Không có khu vực → dùng giá trị chung, không bị khu vực nào kéo theo.
        $this->assertSame(4.0, DispatchRadiusPolicy::radiusForElapsedSeconds(0, null));
    }

    public function test_initialize_city_does_not_overwrite_existing_values(): void
    {
        OperationalSettings::put(['order.rain_bonus_amount' => 9_000], $this->cityA);
        OperationalSettings::initializeCity($this->cityA);

        $this->assertSame(9_000, OperationalSettings::rainBonusAmount($this->cityA));
    }

    public function test_city_copy_starts_from_current_shared_values(): void
    {
        DB::table('settings')->where('key', 'order.completion_radius_meters')->update(['value' => '350']);
        OperationalSettings::flush();

        $cityC = City::create(['name' => 'Ops C', 'slug' => 'ops-c-'.uniqid()])->id;

        $this->assertSame(350, OperationalSettings::completionRadiusMeters($cityC));
        $this->assertSame(300, OperationalSettings::completionRadiusMeters($this->cityA));
    }
}
