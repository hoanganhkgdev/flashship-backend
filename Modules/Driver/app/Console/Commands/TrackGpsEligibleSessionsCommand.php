<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverGpsEligibleSession;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverLocationService;

/**
 * Ghi các khoảng tài xế thực sự đủ điều kiện nhận đơn: có ý định
 * Online và GPS còn tươi. Mất GPS không đổi is_online; nó chỉ đóng
 * khoảng eligible. Có GPS lại thì mở khoảng mới và tự nhận đơn trở lại.
 */
class TrackGpsEligibleSessionsCommand extends Command
{
    protected $signature = 'drivers:track-gps-eligibility';
    protected $description = 'Theo dõi các khoảng Online có GPS hợp lệ để chấm giờ ca';

    public function handle(): void
    {
        $now = Carbon::now();
        $locations = RTDBService::getDriverLocations();
        $online = User::where('user_type', 'driver')
            ->where('is_online', true)
            ->get(['id', 'online_since']);
        $onlineIds = $online->pluck('id');

        // Tự chữa các phiên online kiểu cũ bị bỏ mở khi một luồng khác
        // (đăng nhập thiết bị mới, nghỉ phép, khoá tài khoản...) đã chuyển
        // user sang Offline mà không đi qua toggleOnline(). Các phiên này
        // không còn dùng để chấm điểm, nhưng không được để treo vô hạn.
        DriverShiftSession::whereNull('ended_at')
            ->whereNotIn('driver_id', $onlineIds)
            ->update(['ended_at' => $now]);

        // Tài xế chủ động Offline: không cộng tới thời điểm Offline nếu GPS
        // đã chết từ trước; chỉ giữ tối đa vùng tươi sau fix cuối.
        $offlineSessions = DriverGpsEligibleSession::whereNull('ended_at')
            ->whereNotIn('driver_id', $onlineIds)
            ->get();
        foreach ($offlineSessions as $session) {
            DB::transaction(function () use ($session, $now) {
                $locked = DriverGpsEligibleSession::where('id', $session->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();
                if (!$locked) return;
                $endedAt = $locked->last_gps_at->copy()
                    ->addSeconds(DriverLocationService::POS_MAX_AGE_SECS)
                    ->min($now);
                $locked->update(['ended_at' => $endedAt]);
            });
        }

        foreach ($online as $driver) {
            $data = $locations["driver_{$driver->id}"] ?? null;
            $updatedAt = $data['updated_at'] ?? null;
            $gpsAt = is_numeric($updatedAt)
                ? Carbon::createFromTimestamp((int) ($updatedAt / 1000), config('app.timezone'))
                : null;
            $gpsAge = $gpsAt ? $gpsAt->diffInSeconds($now, false) : null;
            $fresh = $gpsAt
                && isset($data['lat'], $data['lng'])
                && $gpsAge >= -5
                && $gpsAge <= DriverLocationService::POS_MAX_AGE_SECS;

            DB::transaction(function () use ($driver, $gpsAt, $fresh, $now) {
                $state = DB::table('driver_gps_eligibility_states')
                    ->where('driver_id', $driver->id)
                    ->lockForUpdate()
                    ->first();
                $firstObservation = !$state;
                if ($firstObservation) {
                    DB::table('driver_gps_eligibility_states')->insert([
                        'driver_id'               => $driver->id,
                        'initialized_at'          => $now,
                        'last_observed_gps_at'    => $gpsAt,
                    ]);
                } elseif ($gpsAt) {
                    DB::table('driver_gps_eligibility_states')
                        ->where('driver_id', $driver->id)
                        ->update(['last_observed_gps_at' => $gpsAt]);
                }

                $open = DriverGpsEligibleSession::where('driver_id', $driver->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();

                if ($fresh) {
                    if ($open) {
                        $open->update(['last_gps_at' => $gpsAt]);
                        return;
                    }

                    $startedAt = $gpsAt;
                    // Lần chạy đầu sau deploy có thể rơi giữa ca: nếu GPS
                    // đang tươi, bảo toàn phần giờ Online trước rollout.
                    // Sau mốc khởi tạo, mỗi lần GPS phục hồi chỉ mở từ
                    // fix mới, không hồi tố khoảng GPS đã mất.
                    if ($firstObservation && $driver->online_since) {
                        $startedAt = $driver->online_since;
                    } elseif ($driver->online_since && $startedAt->lessThan($driver->online_since)) {
                        $startedAt = $driver->online_since;
                    }
                    DriverGpsEligibleSession::create([
                        'driver_id'  => $driver->id,
                        'started_at' => $startedAt,
                        'last_gps_at'=> $gpsAt,
                    ]);
                    return;
                }

                if ($open) {
                    // Chỉ cho phép vùng đệm ngắn sau fix cuối; không cộng tới
                    // lúc cron phát hiện, vì khoảng đó tài xế không thể nhận đơn.
                    $endedAt = $open->last_gps_at->copy()
                        ->addSeconds(DriverLocationService::POS_MAX_AGE_SECS)
                        ->min($now);
                    $open->update(['ended_at' => $endedAt]);
                }
            });
        }
    }
}
