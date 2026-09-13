<?php

namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\StaleLocationPolicy;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;

class AutoOfflineStaleLocationCommand extends Command
{
    protected $signature = 'drivers:auto-offline-stale-location';

    protected $description = 'Cảnh báo GPS quá hạn 2 phút và tự Offline sau thêm 1 phút không phục hồi';

    public function handle(StaleLocationPolicy $policy): void
    {
        $now = Carbon::now();
        $locations = RTDBService::getDriverLocations();
        if ($locations === null) {
            $this->warn('Không đọc được Firebase; bỏ qua để không Offline oan tài xế.');

            return;
        }

        $drivers = User::where('user_type', 'driver')
            ->where('is_online', true)
            ->get([
                'id', 'name', 'online_since', 'fcm_token',
                'gps_stale_notified_at', 'gps_stale_evidence_at',
            ]);
        $activeDriverIds = Order::whereIn('status', ['assigned', 'processing'])
            ->whereIn('delivery_man_id', $drivers->pluck('id'))
            ->pluck('delivery_man_id')
            ->flip();
        $offlineCount = 0;
        $warningCount = 0;

        foreach ($drivers as $driver) {
            if ($activeDriverIds->has($driver->id)) {
                $this->clearWarning($driver->id);

                continue;
            }

            $onlineSince = $driver->online_since
                ?? DriverShiftSession::where('driver_id', $driver->id)
                    ->whereNull('ended_at')
                    ->max('started_at');
            if (! $onlineSince) {
                Log::warning('[AutoOfflineGPS] Tài xế Online không có mốc bắt đầu; bỏ qua vòng này.', [
                    'driver_id' => $driver->id,
                ]);

                continue;
            }

            $location = $locations["driver_{$driver->id}"] ?? null;
            $lastEvidenceTimestamp = $policy->lastEvidenceTimestamp(
                $location,
                Carbon::parse($onlineSince),
                $now,
            );
            $warningDueTimestamp = $lastEvidenceTimestamp + StaleLocationPolicy::STALE_AFTER_SECONDS;
            if ($warningDueTimestamp > $now->getTimestamp()) {
                $this->clearWarning($driver->id);

                continue;
            }

            $warnedEvidenceTimestamp = $driver->gps_stale_evidence_at?->getTimestamp();
            if (! $driver->gps_stale_notified_at
                || $warnedEvidenceTimestamp !== $lastEvidenceTimestamp) {
                if ($this->markWarning($driver->id, $lastEvidenceTimestamp, $now)) {
                    $warningCount++;
                    $this->sendWarning($driver);
                }

                continue;
            }

            $offlineAtTimestamp = $policy->offlineDueTimestamp($driver->gps_stale_notified_at);
            if ($offlineAtTimestamp > $now->getTimestamp()) {
                continue;
            }

            $result = $this->forceOffline($driver->id, $offlineAtTimestamp, $now);
            if (! $result) {
                continue;
            }

            $offlineCount++;
            RTDBService::removeDriverLocation($driver->id);
            $this->releaseOffers($driver->id, $result['offers']);

            if ($driver->fcm_token) {
                FCMService::getInstance()->sendDriverNotice(
                    $driver->fcm_token,
                    'Đã chuyển Offline',
                    'GPS chưa được khôi phục sau cảnh báo. Hãy mở app, bật GPS rồi Online lại.',
                    ['type' => 'driver_auto_offline', 'reason' => 'stale_location'],
                );
            }

            Log::info('[AutoOfflineGPS] Đã Offline tài xế do vị trí quá hạn.', [
                'driver_id' => $driver->id,
                'driver_name' => $driver->name,
                'session_ended_at' => Carbon::createFromTimestamp(
                    $offlineAtTimestamp,
                    config('app.timezone'),
                )->toIso8601String(),
            ]);
        }

        $this->info("[AutoOfflineGPS] Đã cảnh báo {$warningCount}, Offline {$offlineCount} tài xế.");
    }

    private function markWarning(int $driverId, int $lastEvidenceTimestamp, Carbon $now): bool
    {
        return (bool) User::whereKey($driverId)
            ->where('is_online', true)
            ->update([
                'gps_stale_notified_at' => $now,
                'gps_stale_evidence_at' => Carbon::createFromTimestamp(
                    $lastEvidenceTimestamp,
                    config('app.timezone'),
                ),
            ]);
    }

    private function clearWarning(int $driverId): void
    {
        User::whereKey($driverId)
            ->where(fn ($query) => $query
                ->whereNotNull('gps_stale_notified_at')
                ->orWhereNotNull('gps_stale_evidence_at'))
            ->update([
                'gps_stale_notified_at' => null,
                'gps_stale_evidence_at' => null,
            ]);
    }

    private function sendWarning(User $driver): void
    {
        if (! $driver->fcm_token) {
            return;
        }

        FCMService::getInstance()->sendDriverNotice(
            $driver->fcm_token,
            'Mất kết nối vị trí',
            'Không nhận được GPS mới trong 2 phút. Hãy mở app trong 1 phút để tránh bị chuyển Offline.',
            ['type' => 'driver_gps_stale_warning', 'reason' => 'stale_location'],
        );
    }

    /**
     * @return array{offers: Collection<int, Order>}|null
     */
    private function forceOffline(int $driverId, int $expiredAtTimestamp, Carbon $now): ?array
    {
        return DB::transaction(function () use ($driverId, $expiredAtTimestamp, $now) {
            $driver = User::whereKey($driverId)->lockForUpdate()->first();
            if (! $driver?->is_online) {
                return null;
            }

            // Không bao giờ tự Offline giữa đơn. Kiểm tra lại trong cùng
            // transaction để tránh tài xế vừa nhận đơn sau truy vấn đầu vòng.
            if (Order::where('delivery_man_id', $driverId)
                ->whereIn('status', ['assigned', 'processing'])
                ->lockForUpdate()
                ->exists()) {
                return null;
            }

            $offers = Order::where('status', 'pending')
                ->where('dispatching_to_driver_id', $driverId)
                ->lockForUpdate()
                ->get();
            foreach ($offers as $offer) {
                $offer->update([
                    'dispatching_to_driver_id' => null,
                    'offer_viewed_at' => null,
                ]);
                OrderDispatchLog::where('order_id', $offer->id)
                    ->where('driver_id', $driverId)
                    ->where('result', 'pending')
                    ->update(['result' => 'expired', 'responded_at' => $now]);
            }

            $openSessions = DriverShiftSession::where('driver_id', $driverId)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->get();
            foreach ($openSessions as $session) {
                $endedAtTimestamp = min(
                    $now->getTimestamp(),
                    max($expiredAtTimestamp, $session->started_at->getTimestamp()),
                );
                $endedAt = Carbon::createFromTimestamp(
                    $endedAtTimestamp,
                    config('app.timezone'),
                );
                $session->update(['ended_at' => $endedAt]);
            }

            $driver->update([
                'is_online' => false,
                'online_since' => null,
                'gps_stale_notified_at' => null,
                'gps_stale_evidence_at' => null,
            ]);

            return ['offers' => $offers];
        });
    }

    /** @param Collection<int, Order> $offers */
    private function releaseOffers(int $driverId, $offers): void
    {
        foreach ($offers as $offer) {
            RTDBService::clearDriverOffer($driverId, $offer->id);
            try {
                Redis::eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    1,
                    "dispatch:lock:driver:{$driverId}",
                    (string) $offer->id,
                );
            } catch (\Throwable $e) {
                Log::warning('[AutoOfflineGPS] Không xóa được dispatch lock.', [
                    'driver_id' => $driverId,
                    'order_id' => $offer->id,
                    'message' => $e->getMessage(),
                ]);
            }
            try {
                app(DispatchService::class)->sendToNextDriver($offer->fresh());
            } catch (\Throwable $e) {
                Log::error('[AutoOfflineGPS] Không thể phát đơn cho tài xế kế tiếp.', [
                    'driver_id' => $driverId,
                    'order_id' => $offer->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
