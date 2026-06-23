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
            ->get(['id', 'city_id', 'latitude', 'longitude']);

        if ($onlineDrivers->isEmpty()) {
            $this->info('No online drivers.');
            return self::SUCCESS;
        }

        $synced = 0;
        $stale  = 0;
        $now    = time();

        try {
            $locations = RTDBService::db()->getReference('flashship_main/locations')->getValue();
        } catch (\Throwable $e) {
            Log::error('[SyncGeo] Firebase read failed: ' . $e->getMessage());
            $this->fallbackFromDB($onlineDrivers);
            return self::SUCCESS;
        }

        foreach ($onlineDrivers as $d) {
            $key  = "driver_{$d->id}";
            $data = $locations[$key] ?? null;

            if ($data && isset($data['lat'], $data['lng'])) {
                $updatedAt = $data['updated_at'] ?? 0;
                $age = is_numeric($updatedAt) ? $now - (int)($updatedAt / 1000) : 9999;

                if ($age > DriverGeoService::GPS_TTL_SECS) {
                    DriverGeoService::removeDriver($d->id, $d->city_id);
                    $stale++;
                    continue;
                }

                DriverGeoService::updateLocation($d->id, $d->city_id, (float) $data['lat'], (float) $data['lng']);

                $dbUpdate = [
                    'latitude'  => $data['lat'],
                    'longitude' => $data['lng'],
                ];
                if (isset($data['bearing']) && $data['bearing'] !== null) {
                    $dbUpdate['bearing'] = $data['bearing'];
                }
                DB::table('users')->where('id', $d->id)->update($dbUpdate);

                $synced++;
            } elseif ($d->latitude && $d->longitude) {
                DriverGeoService::updateLocation($d->id, $d->city_id, (float) $d->latitude, (float) $d->longitude);
                $synced++;
            }
        }

        $this->info("Synced {$synced} drivers from Firebase, removed {$stale} stale.");
        return self::SUCCESS;
    }

    private function fallbackFromDB($drivers): void
    {
        $count = 0;
        foreach ($drivers as $d) {
            if ($d->latitude && $d->longitude) {
                DriverGeoService::updateLocation($d->id, $d->city_id, (float) $d->latitude, (float) $d->longitude);
                $count++;
            }
        }
        $this->warn("Firebase failed, fallback DB sync: {$count} drivers.");
    }
}
