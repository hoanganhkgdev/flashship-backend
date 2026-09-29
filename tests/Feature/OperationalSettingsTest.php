<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    }

    protected function tearDown(): void
    {
        // Bộ nhớ đệm static sống qua các test trong cùng process.
        OperationalSettings::flush();
        parent::tearDown();
    }

    public function test_seeded_defaults_preserve_current_policy(): void
    {
        $this->assertSame(4.0, DispatchRadiusPolicy::radiusForElapsedSeconds(0));
        $this->assertSame([3 => 1, 6 => 2, 10 => 4], DriverScoreService::streakMilestones());
        $this->assertSame(140, DriverScoreService::weeklyBonusScore());
        $this->assertSame(70, DriverScoreService::weeklyPenaltyScore());
        $this->assertSame(200_000, DriverScoreService::weeklyBonusAmount());
        $this->assertSame(100_000, DriverScoreService::weeklyPenaltyAmount());
        $this->assertSame(-2, DriverScoreService::declinePenalty());
        $this->assertSame(5_000, OperationalSettings::rainBonusAmount());
        $this->assertSame(300, OperationalSettings::completionRadiusMeters());
        $this->assertSame(5_000, OperationalSettings::nightSurcharge(23));
        $this->assertSame(10_000, OperationalSettings::nightSurcharge(2));
        $this->assertSame(0, OperationalSettings::nightSurcharge(12));
    }

    public function test_saved_values_are_used_by_dispatch_and_driver_score_services(): void
    {
        OperationalSettings::put([
            'dispatch.max_road_distance_km' => 6.5,
            'driver_score.streak_milestones' => '2:1,5:3',
            'driver_score.daily_bonus_cap' => 12,
            'driver_score.weekly_bonus_score' => 135,
            'driver_score.weekly_penalty_score' => 65,
            'driver_score.weekly_bonus_amount' => 250_000,
            'driver_score.weekly_penalty_amount' => 80_000,
            'driver_score.decline_penalty' => -4,
            'driver_score.viewed_timeout_penalty' => -3,
            'driver_score.unviewed_penalty' => -5,
            'driver_score.unviewed_window_size' => 4,
            'driver_score.unviewed_limit' => 2,
            'driver_score.shift_normal_min_percent' => 90,
            'driver_score.shift_reduced_min_percent' => 75,
            'driver_score.shift_mid_min_percent' => 65,
            'driver_score.shift_low_min_percent' => 55,
            'driver_score.shift_reduced_penalty' => -2,
            'driver_score.shift_mid_penalty' => -4,
            'driver_score.shift_low_penalty' => -8,
            'driver_score.shift_critical_penalty' => -12,
            'order.rain_bonus_amount' => 8_000,
            'order.completion_radius_meters' => 450,
            'order.auto_complete_grace_minutes' => 5,
            'order.max_distance_km' => 20,
            'order.rating_window_hours' => 48,
            'order.delayed_reminder_minutes' => 20,
            'order.max_active_per_driver' => 1,
            'order.stack_max_pickup_km' => 1.2,
            'order.stack_max_delivery_km' => 2.2,
            'pricing.night_23_00_amount' => 7_000,
            'pricing.night_01_03_amount' => 12_000,
            'dispatch.offer_open_seconds' => 35,
            'dispatch.offer_decision_seconds' => 40,
            'dispatch.total_timeout_minutes' => 18,
            'dispatch.retry_seconds' => 20,
            'dispatch.score_weight' => 20,
            'dispatch.wait_weight' => 30,
            'dispatch.distance_weight' => 50,
            'dispatch.wait_cap_minutes' => 360,
            'rain_mode.auto_off_hours' => 8,
            'debt.penalty_overdue_hours' => 36,
            'wallet.low_balance_threshold' => 150_000,
        ]);

        $this->assertSame(6.5, DispatchRadiusPolicy::radiusForElapsedSeconds(300));
        $this->assertSame([2 => 1, 5 => 3], DriverScoreService::streakMilestones());
        $this->assertSame(12, DriverScoreService::dailyBonusCap());
        $this->assertSame(135, DriverScoreService::weeklyBonusScore());
        $this->assertSame(65, DriverScoreService::weeklyPenaltyScore());
        $this->assertSame(250_000, DriverScoreService::weeklyBonusAmount());
        $this->assertSame(80_000, DriverScoreService::weeklyPenaltyAmount());
        $this->assertSame(-4, DriverScoreService::declinePenalty());
        $this->assertSame(-3, DriverScoreService::viewedTimeoutPenalty());
        $this->assertSame(-5, DriverScoreService::unviewedPenalty());
        $this->assertSame(['normal' => 0.9, 'reduced' => 0.75, 'mid' => 0.65, 'low' => 0.55], OperationalSettings::shiftOnlineThresholds());
        $this->assertSame(['reduced' => -2, 'mid' => -4, 'low' => -8, 'critical' => -12], OperationalSettings::shiftOnlinePenalties());
        $this->assertSame(8_000, OperationalSettings::rainBonusAmount());
        $this->assertSame(450, OperationalSettings::completionRadiusMeters());
        $this->assertSame(0.45, OperationalSettings::completionRadiusKm());
        $this->assertSame(5, OperationalSettings::autoCompleteGraceMinutes());
        $this->assertSame(20.0, OperationalSettings::maxOrderDistanceKm());
        $this->assertSame(48, OperationalSettings::ratingWindowHours());
        $this->assertSame(20, OperationalSettings::delayedReminderMinutes());
        $this->assertSame(1, OperationalSettings::maxActiveOrdersPerDriver());
        $this->assertSame(1.2, OperationalSettings::stackMaxPickupKm());
        $this->assertSame(2.2, OperationalSettings::stackMaxDeliveryKm());
        $this->assertSame(7_000, OperationalSettings::nightSurcharge(23));
        $this->assertSame(12_000, OperationalSettings::nightSurcharge(2));
        $this->assertSame(35, OperationalSettings::offerOpenSeconds());
        $this->assertSame(40, OperationalSettings::offerDecisionSeconds());
        $this->assertSame(18, OperationalSettings::dispatchTimeoutMinutes());
        $this->assertSame(20, OperationalSettings::dispatchRetrySeconds());
        $this->assertSame(['score' => 20.0, 'wait' => 30.0, 'distance' => 50.0], OperationalSettings::dispatchWeights());
        $this->assertSame(360, OperationalSettings::dispatchWaitCapMinutes());
        $this->assertSame(8, OperationalSettings::rainModeAutoOffHours());
        $this->assertSame(36, OperationalSettings::penaltyDebtOverdueHours());
        $this->assertSame(150_000, OperationalSettings::lowWalletBalanceThreshold());

        $window = UnviewedOfferWindowPolicy::evaluate([
            ['result' => 'expired', 'viewed_at' => null],
            ['result' => 'expired', 'viewed_at' => null],
        ]);
        $this->assertTrue($window['should_offline']);

        $this->assertSame('operations', DB::table('settings')->where('key', 'dispatch.max_road_distance_km')->value('group'));
    }
}
