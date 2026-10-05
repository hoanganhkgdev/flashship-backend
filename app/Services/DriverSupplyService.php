<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverLocationService;

/**
 * Lực lượng tài xế theo thời gian thực. Nguồn duy nhất cho cả Dashboard và
 * Theo dõi phát đơn, để hai trang không bao giờ ra hai con số khác nhau.
 */
class DriverSupplyService
{
    /** Giây cache — đủ ngắn để realtime, đủ dài để không đọc Firebase dồn dập. */
    private const CACHE_TTL = 10;

    /**
     * @return array{online: int, ready: int, busy1: int, busy2: int, holding: int, dead: int}
     */
    public function snapshot(?int $cityId): array
    {
        return Arr::except($this->snapshotWithDrivers($cityId), ['drivers']);
    }

    /**
     * Như snapshot() nhưng kèm danh sách tài xế từng nhóm
     * (`drivers[nhóm] = [{id, name, phone, active, seen_ago}]`) để bấm vào xem là ai.
     */
    public function snapshotWithDrivers(?int $cityId): array
    {
        return Cache::remember('driver_supply_v2:'.($cityId ?? 'all'), self::CACHE_TTL, fn () => $this->compute($cityId));
    }

    private function compute(?int $cityId): array
    {
        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where('is_online', true)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->get(['id', 'name', 'phone']);

        $ids = $drivers->pluck('id');

        // "Dead" = không có GPS/heartbeat đủ mới trên Firebase (nguồn duy
        // nhất, thay cho cột last_heartbeat_at đã ngừng được cập nhật định
        // kỳ từ khi bỏ cron sync GPS).
        $lastSeen = app(DriverLocationService::class)->lastSeenFor($ids->all());
        $now = time();

        // Số đơn active theo tài xế (assigned/processing)
        $activeCounts = DB::table('orders')
            ->whereIn('delivery_man_id', $ids)
            ->whereIn('status', ['assigned', 'processing'])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->pluck('cnt', 'delivery_man_id');

        $holdingOffer = DB::table('orders')
            ->where('status', 'pending')
            ->whereIn('dispatching_to_driver_id', $ids)
            ->pluck('dispatching_to_driver_id')
            ->flip();

        $online = $drivers->count();
        $counts = ['ready' => 0, 'busy1' => 0, 'busy2' => 0, 'holding' => 0, 'dead' => 0];
        $groups = array_fill_keys(array_keys($counts), []);

        foreach ($drivers as $d) {
            $active = (int) ($activeCounts[$d->id] ?? 0);
            $seen = $lastSeen[$d->id] ?? null;

            $group = match (true) {
                $seen === null || $now - $seen > DriverLocationService::POS_MAX_AGE_SECS => 'dead',
                $active >= 2 => 'busy2',
                isset($holdingOffer[$d->id]) => 'holding',
                $active === 1 => 'busy1',
                default => 'ready',
            };

            $counts[$group]++;
            $groups[$group][] = [
                'id' => (int) $d->id,
                'name' => $d->name,
                'phone' => $d->phone,
                'active' => $active,
                'seen_ago' => $seen === null ? null : max(0, $now - $seen),
            ];
        }

        return ['online' => $online] + $counts + ['drivers' => $groups];
    }
}
