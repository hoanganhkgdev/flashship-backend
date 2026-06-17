<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DriverGeoService;

class SyncDriverGeoCommand extends Command
{
    protected $signature   = 'driver:sync-geo';
    protected $description = 'Sync vị trí tài xế online từ DB vào Redis GEO (chạy mỗi 5 phút)';

    public function handle(): int
    {
        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('city_id')
            ->get(['id', 'city_id', 'latitude', 'longitude']);

        foreach ($drivers as $d) {
            DriverGeoService::updateLocation(
                $d->id,
                $d->city_id,
                (float) $d->latitude,
                (float) $d->longitude
            );
        }

        $this->info("Synced {$drivers->count()} online drivers to Redis GEO.");
        return self::SUCCESS;
    }
}
