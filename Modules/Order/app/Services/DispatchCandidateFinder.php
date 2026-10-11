<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverLocationService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

/**
 * Tìm + xếp hạng ứng viên tài xế cho 1 đơn — quét toàn thành phố, lọc bận/nợ
 * quá hạn/bằng lái/ghép-tuyến/khoảng cách đường thật, rồi sắp theo điểm
 * composite từ DispatchScoringCalculator. Không gửi offer, chỉ trả về danh
 * sách ứng viên đã xếp hạng.
 */
class DispatchCandidateFinder
{
    const MAX_DRIVERS = 50;

    private array $lastDiagnostics = [];

    // Ghép đơn tự động trong find() — điều kiện: điểm lấy 2 đơn gần nhau VÀ
    // điểm giao 2 đơn cũng gần nhau (cùng khu vực lấy, cùng khu vực giao mới
    // hợp lý để 1 tài xế chạy được cả 2 mà không vòng vèo quá xa).
    // Không còn khái niệm "bán kính tìm kiếm" (2km/4km đường chim bay) — quét
    // TOÀN BỘ tài xế online đủ điều kiện trong thành phố ngay từ đầu, tính
    // khoảng cách đường đi thật (Google Distance Matrix, 1 lần cho cả lô) cho
    // tất cả, rồi lọc thẳng ai vượt trần này. Không ai trong trần thì coi như
    // không có tài xế, KHÔNG gán đại người xa — gán xa chỉ dời vấn đề sang lúc
    // tài xế huỷ/không chạy, không giải quyết được gì thêm.
    public function __construct(
        private readonly DriverLocationService $locationService,
        private readonly DispatchScoringCalculator $scoringCalculator,
    ) {}

    public function find(Order $order, array $excludeIds = [], ?float $maxRoadDistanceKm = null): Collection
    {
        $maxRoadDistanceKm ??= DispatchRadiusPolicy::radiusForElapsedSeconds(0, $order->city_id);
        $this->lastDiagnostics = ['radius_km' => $maxRoadDistanceKm];
        if (! $order->city_id) {
            Log::warning("[Dispatch] Đơn #{$order->id} không có city_id → không thể tìm tài xế");

            return collect();
        }

        $now = now();

        // Chỉ đếm trong khu vực để phục vụ diagnostics; điều kiện loại thật
        // dùng NOT EXISTS ở query ứng viên phía dưới, tránh kéo toàn bộ ID bận
        // của mọi khu vực về PHP rồi nhét vào WHERE NOT IN.
        $busyCount = Order::query()
            ->whereIn('status', ['assigned', 'processing'])
            ->whereNotNull('delivery_man_id')
            ->whereHas('driver', fn ($query) => $query->where('city_id', $order->city_id))
            ->distinct()
            ->count('delivery_man_id');

        $receivingOfferCount = OrderDispatchLog::query()
            ->join('orders', 'orders.id', '=', 'order_dispatch_logs.order_id')
            ->join('users', 'users.id', '=', 'order_dispatch_logs.driver_id')
            ->where('order_dispatch_logs.result', 'pending')
            ->where('orders.status', 'pending')
            ->where('orders.id', '!=', $order->id)
            ->where('users.city_id', $order->city_id)
            ->distinct()
            ->count('order_dispatch_logs.driver_id');

        $onlineTotal = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->count();

        // ── 2. Toàn bộ tài xế online trong thành phố — không giới hạn khoảng
        // cách nào ở bước này. Với quy mô vài chục tài xế/thành phố, tính khoảng
        // cách đường thật cho tất cả (bước 5) rẻ hơn hẳn chi phí duy trì Redis
        // GEO + 2 lớp lọc thô/lọc lại như trước, và không bỏ sót ai.
        // Toạ độ/độ mới KHÔNG còn lọc qua cột MySQL (latitude/longitude/
        // last_heartbeat_at/last_location_at — vốn do 1 cron đồng bộ định kỳ
        // ghi, có độ trễ và từng gây race ghi đè vị trí cũ/mới lẫn lộn). Danh
        // sách ở đây chỉ lọc theo metadata quan hệ (thành phố/trạng thái); toạ
        // độ + độ mới được lọc ngay sau, đọc thẳng Firebase RTDB tại chính thời
        // điểm này — xem bước dưới.
        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->whereNotIn('id', $excludeIds)
            ->where('status', 1)
            ->where('is_online', true)
            // Offer hệ thống chỉ dành cho tài xế rảnh. Dùng correlated
            // NOT EXISTS để DB dừng ngay khi thấy một đơn active, không tải
            // danh sách tài xế bận toàn hệ thống về ứng dụng.
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('orders')
                    ->whereColumn('orders.delivery_man_id', 'users.id')
                    ->whereIn('orders.status', ['assigned', 'processing']);
            })
            ->whereNotExists(function ($query) use ($order) {
                $query->selectRaw('1')
                    ->from('order_dispatch_logs')
                    ->join('orders as offered_orders', 'offered_orders.id', '=', 'order_dispatch_logs.order_id')
                    ->whereColumn('order_dispatch_logs.driver_id', 'users.id')
                    ->where('order_dispatch_logs.result', 'pending')
                    ->where('offered_orders.status', 'pending')
                    ->where('offered_orders.id', '!=', $order->id);
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('driver_leave_requests')
                    ->whereColumn('driver_leave_requests.driver_id', 'users.id')
                    ->whereDate('driver_leave_requests.leave_date', today());
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('driver_debts')
                    ->whereColumn('driver_debts.driver_id', 'users.id')
                    ->where('driver_debts.status', 'overdue');
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                    ->orWhere('score_suspended_until', '<=', $now);
            });

        if ($order->service_type === 'car') {
            $candidates->whereExists(function ($query) {
                $query->selectRaw('1')->from('driver_licenses')
                    ->whereColumn('driver_licenses.user_id', 'users.id')
                    ->where('driver_licenses.status', 'approved');
            });
        }

        $candidates = $candidates->get();

        $metadataEligible = $candidates->count();

        // ── 2b. Lọc theo toạ độ + độ mới — đọc trực tiếp Firebase RTDB (nguồn
        // gốc do app tài xế ghi), không qua bản sao MySQL. Ai không có vị trí
        // đủ mới (app tắt/mất kết nối) bị loại ngay ở đây, thay cho check
        // heartbeat/"Chặn A" cũ dựa trên cột MySQL.
        $locations = $this->locationService->freshLocationsFor($candidates->pluck('id')->all());
        $candidates = $candidates->filter(fn (User $d) => isset($locations[$d->id]))->values();
        $freshGpsCount = $candidates->count();
        foreach ($candidates as $d) {
            $d->setAttribute('latitude', $locations[$d->id]['lat']);
            $d->setAttribute('longitude', $locations[$d->id]['lng']);
            $d->setAttribute('_gps_age_seconds', (int) ($locations[$d->id]['age_seconds'] ?? 0));
        }

        Log::debug("     [Candidates] Online/active: {$candidates->count()} | Bận: {$busyCount} | Đang nhận offer: {$receivingOfferCount} | Đã hỏi: ".count($excludeIds));

        // Không lọc theo tuyến các đơn đang giữ. Trần số đơn active ở
        // bước 1 là điều kiện duy nhất liên quan tới việc tài xế đang có đơn.
        $afterDetour = $candidates;

        // Khoảng cách đường đi không thể ngắn hơn đường chim bay. Loại trước
        // những người đã vượt trần ngay cả theo đường thẳng để không tốn một
        // Directions API request cho từng tài xế chắc chắn không đủ điều kiện.
        $straightLineEligible = $afterDetour->filter(fn (User $d) => $d->latitude !== null && $d->longitude !== null
            && GoogleMapService::haversineKm(
                (float) $d->latitude,
                (float) $d->longitude,
                (float) $order->pickup_lat,
                (float) $order->pickup_lng,
            ) <= $maxRoadDistanceKm
        );

        // ── 5. Tính khoảng cách đường thật cho nhóm còn khả năng đạt trần.
        // GoogleMapService hiện gọi Directions song song cho các điểm chưa có
        // cache; bước lọc chim bay phía trên giảm số request mà không loại oan.
        $origins = $straightLineEligible
            ->mapWithKeys(fn (User $d) => [$d->id => ['lat' => (float) $d->latitude, 'lng' => (float) $d->longitude]])
            ->all();

        $roadDistances = GoogleMapService::roadDistanceBatchKm(
            $origins, (float) $order->pickup_lat, (float) $order->pickup_lng
        );

        foreach ($straightLineEligible as $d) {
            $d->setAttribute('_road_km', $roadDistances[$d->id] ?? null);
        }

        // GoogleMapService luôn fallback sang haversine×2 khi Directions lỗi.
        // Nếu vẫn không có khoảng cách thì loại, không được gán đại
        // tài xế xa vào đúng mép bán kính.
        $withinRange = $straightLineEligible->filter(
            fn (User $d) => $d->_road_km !== null && $d->_road_km <= $maxRoadDistanceKm
        );
        if (($removed = $afterDetour->count() - $withinRange->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế — đường thật vượt trần {$maxRoadDistanceKm}km của vòng hiện tại");
        }

        $lastOffers = OrderDispatchLog::whereIn('driver_id', $withinRange->pluck('id'))
            ->selectRaw('driver_id, MAX(offered_at) as last_offered_at')
            ->groupBy('driver_id')
            ->pluck('last_offered_at', 'driver_id');

        $ranked = $withinRange->each(function (User $driver) use ($lastOffers, $maxRoadDistanceKm) {
            $lastOfferedAt = $lastOffers[$driver->id] ?? null;
            $driver->setAttribute('_distance_cap_km', $maxRoadDistanceKm);
            $driver->setAttribute('_last_offered_at', $lastOfferedAt);
            $driver->setAttribute('_last_offered_timestamp', $lastOfferedAt ? strtotime($lastOfferedAt) : null);
        });

        $sorted = DispatchGpsFreshnessPolicy::prioritize(DispatchRotationPolicy::sort(
            $ranked,
            fn (User $driver) => $this->scoringCalculator->composite(
                $driver, $driver->_road_km, $maxRoadDistanceKm, $order->city_id
            ),
            OperationalSettings::dispatchFairScoreBand($order->city_id),
        ))
            ->take(self::MAX_DRIVERS)
            ->values();

        $this->lastDiagnostics = [
            'radius_km' => $maxRoadDistanceKm,
            'online_total' => $onlineTotal,
            'busy' => $busyCount,
            'receiving_offer' => $receivingOfferCount,
            'already_offered_this_order' => count($excludeIds),
            'metadata_eligible' => $metadataEligible,
            'missing_or_stale_gps' => $metadataEligible - $freshGpsCount,
            'outside_straight_radius' => $afterDetour->count() - $straightLineEligible->count(),
            'gps_grace_priority' => $withinRange->filter(fn (User $d) => $d->_gps_age_seconds > DriverLocationService::POS_FRESH_PRIORITY_SECS)->count(),
            'outside_radius' => $afterDetour->count() - $withinRange->count(),
            'eligible_now' => $sorted->count(),
        ];

        if ($sorted->isNotEmpty()) {
            Log::debug('     [Candidates] Top '.min(5, $sorted->count()).' tài xế:');
            foreach ($sorted->take(5) as $i => $d) {
                $km = $d->_road_km !== null ? round($d->_road_km, 2).'km' : 'lỗi API';
                $score = round($this->scoringCalculator->composite($d, $d->_road_km, $maxRoadDistanceKm, $order->city_id), 1);
                $wait = round($this->scoringCalculator->waitTimeScore($d, $order->city_id), 1);
                $lastOffer = $d->_last_offered_at ?? 'chưa từng phát';
                Log::debug('       '.($i + 1).". #{$d->id} {$d->name} | lượt gần nhất: {$lastOffer} | đường thật: {$km} | điểm={$score} | driver_score=".($d->driver_score ?? DriverScoreService::DEFAULT_SCORE)." | wait={$wait}");
            }
        }

        return $sorted;
    }

    public function diagnostics(): array
    {
        return $this->lastDiagnostics;
    }
}
