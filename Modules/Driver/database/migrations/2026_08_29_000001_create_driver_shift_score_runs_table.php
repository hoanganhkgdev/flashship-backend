<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_shift_score_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->timestamp('shift_started_at');
            $table->timestamp('shift_ended_at');
            $table->timestamps();

            $table->unique(
                ['driver_id', 'shift_id', 'shift_started_at'],
                'driver_shift_score_runs_unique'
            );
        });

        // Backfill các ca trong 24h đã được command cũ chấm trước lúc deploy,
        // tránh bản mới chấm lại ngay lần cron đầu. Log cũ hợp lệ phải được
        // tạo từ lúc ca kết thúc đến tối đa 10 phút sau đó; không dùng cả
        // khoảng [start,end] vì log ca liền trước có thể rơi vào ca kế tiếp.
        $reasons = [
            'shift_online_normal', 'shift_online_reduced', 'shift_online_mid',
            'shift_online_low', 'shift_online_critical', 'shift_online_high',
            'shift_online_neutral', 'shift_never_online',
        ];
        $now = Carbon::now();
        $shifts = DB::table('shifts')->where('is_active', true)->get();
        foreach ($shifts as $shift) {
            foreach ([0, -1, -2] as $dayOffset) {
                $start = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->start_time);
                $end = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->end_time);
                if ($end->lessThanOrEqualTo($start)) $end->addDay();
                if ($end->greaterThan($now) || $end->lessThanOrEqualTo($now->copy()->subDay())) continue;

                $driverIds = DB::table('shift_user')
                    ->where('shift_id', $shift->id)
                    ->pluck('user_id');
                foreach ($driverIds as $driverId) {
                    $wasScored = DB::table('driver_score_logs')
                        ->where('driver_id', $driverId)
                        ->whereIn('reason', $reasons)
                        ->where('created_at', '>=', $end)
                        ->where('created_at', '<', $end->copy()->addMinutes(10))
                        ->exists();
                    if (!$wasScored) continue;

                    DB::table('driver_shift_score_runs')->insertOrIgnore([
                        'driver_id' => $driverId,
                        'shift_id' => $shift->id,
                        'shift_started_at' => $start,
                        'shift_ended_at' => $end,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shift_score_runs');
    }
};
