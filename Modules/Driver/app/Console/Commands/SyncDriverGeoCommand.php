<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\DriverGeoService;
use Modules\Core\Services\RTDBService;

class SyncDriverGeoCommand extends Command
{
    protected $signature   = 'driver:sync-geo';
    protected $description = 'Sync vị trí tài xế từ Firebase RTDB vào Redis GEO (chạy mỗi 30s)';

    public function handle(): int
    {
        $onlineDrivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', true)
            ->whereNotNull('city_id')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'city_id', 'latitude', 'longitude']);

        // Dọn dẹp GEO: xóa tài xế trong Redis GEO nhưng đã offline trong DB
        $this->removeOfflineFromGeo($onlineDrivers->pluck('id')->all());

        if ($onlineDrivers->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $locations = RTDBService::db()->getReference('flashship_main/locations')->getValue();
        } catch (\Throwable $e) {
            Log::error('[SyncGeo] Firebase read failed: ' . $e->getMessage());
            $this->syncFromDB($onlineDrivers);
            return self::SUCCESS;
        }

        $synced = 0;
        $now    = time();

        foreach ($onlineDrivers as $d) {
            $key  = "driver_{$d->id}";
            $data = $locations[$key] ?? null;

            if ($data && isset($data['lat'], $data['lng'])) {
                $updatedAt = $data['updated_at'] ?? 0;
                $age       = is_numeric($updatedAt) ? $now - (int) ($updatedAt / 1000) : PHP_INT_MAX;

                if ($age <= DriverGeoService::GPS_FRESH_SECS) {
                    // GPS mới từ Firebase → dùng luôn và cập nhật DB
                    DriverGeoService::updateLocation($d->id, $d->city_id, (float) $data['lat'], (float) $data['lng']);

                    $dbUpdate = ['latitude' => $data['lat'], 'longitude' => $data['lng']];
                    if (isset($data['bearing'])) {
                        $dbUpdate['bearing'] = $data['bearing'];
                    }
                    DB::table('users')->where('id', $d->id)->update($dbUpdate);
                } else {
                    // GPS cũ trên Firebase nhưng tài xế vẫn online
                    // → dùng vị trí DB (mới nhất đã biết) để giữ trong GEO
                    DriverGeoService::updateLocation($d->id, $d->city_id, (float) $d->latitude, (float) $d->longitude);
                }
            } else {
                // Không có GPS trên Firebase → dùng vị trí DB
                DriverGeoService::updateLocation($d->id, $d->city_id, (float) $d->latitude, (float) $d->longitude);
            }

            $synced++;
        }

        Log::debug("[SyncGeo] Synced {$synced} online drivers to GEO.");
        return self::SUCCESS;
    }

    /**
     * Xóa khỏi GEO những tài xế đã offline (không còn trong danh sách online).
     */
    private function removeOfflineFromGeo(array $onlineIds): void
    {
        // Lấy tất cả city có tài xế online
        $cities = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', true)
            ->whereNotNull('city_id')
            ->distinct()
            ->pluck('city_id')
            ->all();

        // Thêm các city đã biết có GEO data (từ offline cũng cần dọn)
        $knownCities = DB::table('users')
            ->where('user_type', 'driver')
            ->whereNotNull('city_id')
            ->distinct()
            ->pluck('city_id')
            ->all();

        foreach (array_unique(array_merge($cities, $knownCities)) as $cityId) {
            DriverGeoService::removeOfflineDrivers($cityId, $onlineIds);
        }
    }

    /**
     * Fallback khi Firebase không đọc được.
     */
    private function syncFromDB($drivers): void
    {
        foreach ($drivers as $d) {
            DriverGeoService::updateLocation($d->id, $d->city_id, (float) $d->latitude, (float) $d->longitude);
        }
        Log::warning('[SyncGeo] Firebase failed — fallback to DB coords for ' . count($drivers) . ' drivers.');
    }
}
