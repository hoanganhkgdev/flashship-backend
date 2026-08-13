<?php
namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\GoogleMapService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\Order;

/**
 * Tìm + xếp hạng ứng viên tài xế cho 1 đơn — quét toàn thành phố, lọc bận/nợ
 * quá hạn/bằng lái/ghép-tuyến/khoảng cách đường thật, rồi sắp theo điểm
 * composite từ DispatchScoringCalculator. Không gửi offer, chỉ trả về danh
 * sách ứng viên đã xếp hạng.
 */
class DispatchCandidateFinder
{
    const MAX_DRIVERS = 50;

    // Ghép đơn tự động trong find() — điều kiện: điểm lấy 2 đơn gần nhau VÀ
    // điểm giao 2 đơn cũng gần nhau (cùng khu vực lấy, cùng khu vực giao mới
    // hợp lý để 1 tài xế chạy được cả 2 mà không vòng vèo quá xa).
    const BATCH_MAX_PICKUP_KM   = 1.0;
    const BATCH_MAX_DELIVERY_KM = 1.5;

    // Không còn khái niệm "bán kính tìm kiếm" (2km/4km đường chim bay) — quét
    // TOÀN BỘ tài xế online đủ điều kiện trong thành phố ngay từ đầu, tính
    // khoảng cách đường đi thật (Google Distance Matrix, 1 lần cho cả lô) cho
    // tất cả, rồi lọc thẳng ai vượt trần này. Không ai trong trần thì coi như
    // không có tài xế, KHÔNG gán đại người xa — gán xa chỉ dời vấn đề sang lúc
    // tài xế huỷ/không chạy, không giải quyết được gì thêm.
    const MAX_ROAD_DISTANCE_KM = 4.0;

    public function __construct(
        private readonly DriverLocationService $locationService,
        private readonly DispatchScoringCalculator $scoringCalculator,
    ) {}

    public function find(Order $order, array $excludeIds = []): Collection
    {
        if (!$order->city_id) {
            Log::warning("[Dispatch] Đơn #{$order->id} không có city_id → không thể tìm tài xế");
            return collect();
        }

        $now = now();

        // ── 1. Loại tài xế bận / đang nhận offer khác ────────────────────────────
        $busyDriverIds = Order::selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->groupBy('delivery_man_id')
            ->havingRaw('cnt >= 2')
            ->pluck('delivery_man_id');

        $receivingOfferIds = Order::where('status', 'pending')
            ->whereNotNull('dispatching_to_driver_id')
            ->where('id', '!=', $order->id)
            ->pluck('dispatching_to_driver_id');

        $unavailableIds = $busyDriverIds->merge($receivingOfferIds)->unique();

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
            ->whereNotIn('id', $unavailableIds)
            ->where('status', 1)
            ->where('is_online', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', $now);
            })
            ->with(['debts', 'driverLicenses'])
            ->get();

        // ── 2b. Lọc theo toạ độ + độ mới — đọc trực tiếp Firebase RTDB (nguồn
        // gốc do app tài xế ghi), không qua bản sao MySQL. Ai không có vị trí
        // đủ mới (app tắt/mất kết nối) bị loại ngay ở đây, thay cho check
        // heartbeat/"Chặn A" cũ dựa trên cột MySQL.
        $locations = $this->locationService->freshLocationsFor($candidates->pluck('id')->all());
        $candidates = $candidates->filter(fn (User $d) => isset($locations[$d->id]))->values();
        foreach ($candidates as $d) {
            $d->setAttribute('latitude', $locations[$d->id]['lat']);
            $d->setAttribute('longitude', $locations[$d->id]['lng']);
        }

        Log::debug("     [Candidates] Online/active: {$candidates->count()} | Bận: {$busyDriverIds->count()} | Đang nhận offer: {$receivingOfferIds->count()} | Đã hỏi: " . count($excludeIds));

        $afterDebt = $candidates->filter(fn(User $d) => !$this->hasBlockedDebt($d));
        if (($removed = $candidates->count() - $afterDebt->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do nợ quá hạn");
        }

        $afterLicense = $afterDebt->filter(function (User $d) use ($order) {
            if ($order->service_type === 'car') {
                return $d->has_car_license;
            }
            return true;
        });
        if (($removed = $afterDebt->count() - $afterLicense->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do không phù hợp loại xe ({$order->service_type})");
        }

        // ── 4. Ghép đơn: giữ tài xế rảnh HOẶC có 1 đơn mà đơn đang chạy "cùng
        // tuyến" với đơn mới — điểm lấy 2 đơn ≤ BATCH_MAX_PICKUP_KM VÀ điểm
        // giao 2 đơn ≤ BATCH_MAX_DELIVERY_KM (cả 2 điều kiện, không phải 1).
        $activeOrders = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereIn('delivery_man_id', $afterLicense->pluck('id'))
            ->get(['delivery_man_id', 'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng'])
            ->keyBy('delivery_man_id');

        $afterDetour = $afterLicense->filter(function (User $d) use ($order, $activeOrders) {
            $active = $activeOrders->get($d->id);
            if (!$active) return true;
            if (!$active->pickup_lat || !$active->pickup_lng || !$active->delivery_lat || !$active->delivery_lng) return false;

            $pickupToPickup = GoogleMapService::haversineKm(
                (float) $order->pickup_lat, (float) $order->pickup_lng,
                (float) $active->pickup_lat, (float) $active->pickup_lng
            );
            $deliveryToDelivery = GoogleMapService::haversineKm(
                (float) $order->delivery_lat, (float) $order->delivery_lng,
                (float) $active->delivery_lat, (float) $active->delivery_lng
            );

            return $pickupToPickup <= self::BATCH_MAX_PICKUP_KM
                && $deliveryToDelivery <= self::BATCH_MAX_DELIVERY_KM;
        });
        if (($removed = $afterLicense->count() - $afterDetour->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế — đơn đang chạy không cùng tuyến (lấy >1km hoặc giao >1.5km)");
        }

        // ── 5. Tính khoảng cách đường thật cho TOÀN BỘ ứng viên còn lại — 1 lần
        // gọi Google Distance Matrix duy nhất (không phải 1 lần/tài xế) — rồi
        // lọc thẳng ai vượt trần self::MAX_ROAD_DISTANCE_KM. Không còn khái
        // niệm "bán kính chim bay lọc thô rồi lọc lại" — quét city-wide ngay
        // từ bước 2 ở trên rồi.
        $origins = $afterDetour
            ->filter(fn (User $d) => $d->latitude && $d->longitude)
            ->mapWithKeys(fn (User $d) => [$d->id => ['lat' => (float) $d->latitude, 'lng' => (float) $d->longitude]])
            ->all();

        $roadDistances = GoogleMapService::roadDistanceBatchKm(
            $origins, (float) $order->pickup_lat, (float) $order->pickup_lng
        );

        foreach ($afterDetour as $d) {
            $d->setAttribute('_road_km', $roadDistances[$d->id] ?? null);
        }

        // Không đo được (lỗi API/thiếu toạ độ) thì tạm cho qua, không loại oan
        // vì sự cố hạ tầng tạm thời — composite score sẽ dùng trần làm fallback.
        $withinRange = $afterDetour->filter(
            fn (User $d) => $d->_road_km === null || $d->_road_km <= self::MAX_ROAD_DISTANCE_KM
        );
        if (($removed = $afterDetour->count() - $withinRange->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế — đường thật vượt trần " . self::MAX_ROAD_DISTANCE_KM . "km");
        }

        $sorted = $withinRange
            ->sortByDesc(function (User $d) {
                return $this->scoringCalculator->composite($d, $d->_road_km ?? self::MAX_ROAD_DISTANCE_KM, self::MAX_ROAD_DISTANCE_KM);
            })
            ->take(self::MAX_DRIVERS)
            ->values();

        if ($sorted->isNotEmpty()) {
            Log::debug("     [Candidates] Top " . min(5, $sorted->count()) . " tài xế:");
            foreach ($sorted->take(5) as $i => $d) {
                $km    = $d->_road_km !== null ? round($d->_road_km, 2) . 'km' : 'lỗi API';
                $score = round($this->scoringCalculator->composite($d, $d->_road_km ?? self::MAX_ROAD_DISTANCE_KM, self::MAX_ROAD_DISTANCE_KM), 1);
                $wait  = round($this->scoringCalculator->waitTimeScore($d), 1);
                Log::debug("       " . ($i + 1) . ". #{$d->id} {$d->name} | đường thật: {$km} | điểm={$score} | driver_score=" . ($d->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | wait={$wait}");
            }
        }

        return $sorted;
    }

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
    }
}
