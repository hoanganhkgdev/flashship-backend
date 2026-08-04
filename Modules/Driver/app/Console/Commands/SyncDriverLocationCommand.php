<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverLocationLog;

class SyncDriverLocationCommand extends Command
{
    protected $signature   = 'driver:sync-location';
    protected $description = 'Sync GPS tài xế từ Firebase RTDB → MySQL (chạy mỗi 5s)';

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

        // Đã ép toàn bộ app Tài xế cập nhật lên bản ghi path mới (force update)
        // — path cũ "flashship_main/locations" không còn tài xế nào ghi vào
        // nữa, bỏ hẳn fallback, chỉ đọc path mới.
        $newLocations = $this->readLocations('locations');

        if ($newLocations === null) {
            return self::SUCCESS;
        }

        $now    = time();
        $synced = 0;

        foreach ($onlineDrivers as $d) {
            $key  = "driver_{$d->id}";
            $data = $newLocations[$key] ?? null;

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

            // Chỉ ghi log lịch sử khi toạ độ thực sự đổi so với lần trước, tránh
            // phình bảng vì cron chạy mỗi 5s trong khi vị trí cũ vẫn còn "mới".
            if (isset($update['latitude']) && (
                $d->latitude === null
                || round((float) $d->latitude, 6) !== round((float) $update['latitude'], 6)
                || round((float) $d->longitude, 6) !== round((float) $update['longitude'], 6)
            )) {
                DriverLocationLog::create([
                    'driver_id' => $d->id,
                    'latitude'  => $update['latitude'],
                    'longitude' => $update['longitude'],
                    'bearing'   => $update['bearing'] ?? null,
                    'source'    => 'sync',
                ]);
            }

            DB::table('users')->where('id', $d->id)->update($update);
            $synced++;
        }

        if ($synced > 0) {
            Log::debug("[SyncLocation] Synced {$synced} driver GPS từ Firebase → MySQL.");
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function readLocations(string $path): ?array
    {
        try {
            return RTDBService::db()->getReference($path)->getValue();
        } catch (\Throwable $e) {
            Log::error("[SyncLocation] Firebase read failed ({$path}): " . $e->getMessage());
            return null;
        }
    }
}
