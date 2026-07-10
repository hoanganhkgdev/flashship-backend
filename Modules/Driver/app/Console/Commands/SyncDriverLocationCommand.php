<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\DriverGeoService;
use Modules\Core\Services\RTDBService;

class SyncDriverLocationCommand extends Command
{
    protected $signature   = 'driver:sync-location';
    protected $description = 'Sync GPS tài xế từ Firebase RTDB → MySQL (chạy mỗi 30s)';

    public function handle(): int
    {
        $onlineDrivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', true)
            ->whereNotNull('city_id')
            ->get(['id', 'city_id', 'latitude', 'longitude']);

        if ($onlineDrivers->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $locations = RTDBService::db()
                ->getReference('flashship_main/locations')
                ->getValue();
        } catch (\Throwable $e) {
            Log::error('[SyncLocation] Firebase read failed: ' . $e->getMessage());
            return self::SUCCESS;
        }

        $now    = time();
        $synced = 0;

        foreach ($onlineDrivers as $d) {
            $key  = "driver_{$d->id}";
            $data = $locations[$key] ?? null;

            if (!$data) {
                continue;
            }

            $updatedAt = $data['updated_at'] ?? 0;
            $posAge    = is_numeric($updatedAt) ? $now - (int) ($updatedAt / 1000) : PHP_INT_MAX;

            $update = [];

            // Vị trí: chỉ cập nhật nếu GPS mới hơn 10 phút (app chỉ gửi khi
            // di chuyển ≥10m nên tài xế ngồi yên sẽ stale — bình thường).
            if ($posAge <= 600 && isset($data['lat'], $data['lng'])) {
                $update['latitude']         = $data['lat'];
                $update['longitude']        = $data['lng'];
                $update['last_location_at'] = now();
                if (isset($data['bearing'])) {
                    $update['bearing'] = $data['bearing'];
                }
            }

            // Nhịp tim: app ghi heartbeat_at mỗi 30s kể cả đứng yên; app đời cũ
            // không có heartbeat thì mốc ghi vị trí gần nhất cũng tính là còn sống.
            $hbMs = max((int) ($data['heartbeat_at'] ?? 0), (int) $updatedAt);
            if ($hbMs > 0) {
                $hbTs = (int) ($hbMs / 1000);
                if ($now - $hbTs <= 900) {
                    $update['last_heartbeat_at'] = date('Y-m-d H:i:s', $hbTs);
                }
            }

            if (empty($update)) {
                continue;
            }

            DB::table('users')->where('id', $d->id)->update($update);
            if (isset($update['latitude'])) {
                DriverGeoService::add($d->id, $d->city_id, (float) $data['lat'], (float) $data['lng']);
            }
            $synced++;
        }

        if ($synced > 0) {
            Log::debug("[SyncLocation] Synced {$synced} driver GPS từ Firebase → MySQL.");
        }

        // Xóa khỏi GEO những tài xế vừa offline (xử lý trường hợp app crash không gọi toggleOnline)
        $recentlyOffline = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', false)
            ->whereNotNull('city_id')
            ->where('updated_at', '>=', now()->subMinutes(5))
            ->get(['id', 'city_id']);

        foreach ($recentlyOffline as $d) {
            DriverGeoService::remove($d->id, $d->city_id);
        }

        return self::SUCCESS;
    }
}
