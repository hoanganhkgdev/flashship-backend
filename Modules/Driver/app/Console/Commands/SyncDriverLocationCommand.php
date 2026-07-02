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

            if (!$data || !isset($data['lat'], $data['lng'])) {
                continue;
            }

            $updatedAt = $data['updated_at'] ?? 0;
            $age       = is_numeric($updatedAt) ? $now - (int) ($updatedAt / 1000) : PHP_INT_MAX;

            // Chỉ cập nhật nếu GPS từ Firebase mới hơn 10 phút
            if ($age > 600) {
                continue;
            }

            $update = [
                'latitude'  => $data['lat'],
                'longitude' => $data['lng'],
            ];
            if (isset($data['bearing'])) {
                $update['bearing'] = $data['bearing'];
            }

            DB::table('users')->where('id', $d->id)->update($update);
            DriverGeoService::add($d->id, $d->city_id, (float) $data['lat'], (float) $data['lng']);
            $synced++;
        }

        if ($synced > 0) {
            Log::debug("[SyncLocation] Synced {$synced} driver GPS từ Firebase → MySQL.");
        }

        return self::SUCCESS;
    }
}
