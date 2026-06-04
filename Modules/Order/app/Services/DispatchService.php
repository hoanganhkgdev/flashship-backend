<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\AutoCancelOrderJob;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchService
{
    // Bán kính tìm tài xế theo thời gian đã chờ (progressive radius)
    const RADIUS_STAGES = [
        ['after_secs' => 0,   'km' => 5],
        ['after_secs' => 120, 'km' => 8],   // sau 2 phút → mở rộng 8km
        ['after_secs' => 300, 'km' => 12],  // sau 5 phút → mở rộng 12km
    ];

    const DRIVER_OFFER_SECS  = 30;   // 30 giây mỗi tài xế
    const TIMEOUT_SECS       = 600;  // 10 phút → auto-cancel nếu không ai nhận
    const FCM_TTL_SECS       = 30;
    const MAX_DRIVERS        = 50;

    // Trọng số composite score
    const W_DISTANCE  = 40;
    const W_SCORE     = 35;
    const W_RATING    = 15;
    const W_WAIT_TIME = 10;

    const WAIT_TIME_CAP_MINS = 60; // tối đa 60 phút wait time bonus

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $now = now();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] BẮT ĐẦU PHÁT ĐƠN");
        Log::info("║  Đơn     : #{$order->id} | Mã: {$order->code}");
        Log::info("║  Loại    : {$order->service_type}");
        Log::info("║  Thành phố: {$order->city_id}");
        Log::info("║  Pickup  : {$order->pickup_address} ({$order->pickup_lat}, {$order->pickup_lng})");
        Log::info("║  Timeout : " . self::TIMEOUT_SECS . "s → auto-cancel nếu không ai nhận");
        Log::info("╚══════════════════════════════════════════════════════════════");

        // Ghi thời điểm bắt đầu dispatch để tính progressive radius
        DB::table('orders')->where('id', $order->id)->update(['dispatch_started_at' => $now]);
        $order->dispatch_started_at = $now;

        AutoCancelOrderJob::dispatch($order->id)->delay($now->copy()->addSeconds(self::TIMEOUT_SECS));

        $this->sendToNextDriver($order);
    }

    /**
     * Tìm tài xế tiếp theo (chưa được hỏi) và gửi đơn.
     * Bán kính tìm kiếm mở rộng dần theo thời gian chờ.
     */
    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        $attempt  = count($alreadyOffered) + 1;
        $radiusKm = $this->currentRadius($order);

        Log::debug("┌─ [Dispatch] Đơn #{$order->id} | Lần thử #{$attempt} | Bán kính: {$radiusKm}km");
        if (!empty($alreadyOffered)) {
            $offeredNames = User::whereIn('id', $alreadyOffered)->pluck('name', 'id');
            $offeredStr   = collect($alreadyOffered)->map(fn($id) => '#' . $id . ' ' . ($offeredNames[$id] ?? '?'))->implode(', ');
            Log::debug("│  Đã hỏi: {$offeredStr}");
        }

        $candidates = $this->getCandidates($order, $radiusKm, $alreadyOffered);

        if ($candidates->isEmpty()) {
            Log::info("└─ [Dispatch] Đơn #{$order->id}: Hết tài xế khả dụng (bán kính {$radiusKm}km) → HỦY ĐƠN");
            $this->cancelIfNoDriver($order);
            return;
        }

        Log::debug("│  Tìm được {$candidates->count()} tài xế khả dụng");
        $this->sendToDriver($order, $candidates->first());
    }

    /**
     * Xử lý khi tài xế không phản hồi trong 30 giây.
     */
    public function handleTimeout(Order $order, int $driverId): void
    {
        $driver = User::find($driverId);
        $name   = $driver?->name ?? "#{$driverId}";

        // Kiểm tra log còn pending không — tránh race condition với declineOrder
        $updated = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        // Nếu 0 row được update → driver đã decline trước đó → không xử lý nữa
        if (!$updated) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xử lý trước (decline) → bỏ qua timeout");
            return;
        }

        // Chỉ trừ điểm nếu tài xế đã MỞ xem offer nhưng không phản hồi (-1 điểm)
        // Nếu tài xế chưa thấy thông báo (offer_viewed_at = null) → không trừ điểm
        if ($order->offer_viewed_at !== null) {
            DriverScoreService::onTimeout($driverId);
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xem nhưng không phản hồi → trừ 1 điểm");
        } else {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} chưa thấy thông báo → không trừ điểm");
        }

        // Xóa offer trên RTDB — driver app tự dismiss màn hình offer
        RTDBService::clearDriverOffer($driverId);

        $this->sendToNextDriver($order->fresh());
    }

    public function cancelIfNoDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $logs      = OrderDispatchLog::where('order_id', $order->id)->get();
        $driverIds = $logs->pluck('driver_id')->unique()->toArray();
        $names     = User::whereIn('id', $driverIds)->pluck('name', 'id');

        OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        $cancelled = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => 'no_driver', 'updated_at' => now()]);

        if (!$cancelled) return;

        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendNoDriverCancellation($customer->fcm_token, $order->code);
        }

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} BỊ HỦY - Không có tài xế nhận");
        Log::info("║  Tổng số lần thử: {$logs->count()}");
        foreach ($logs as $l) {
            $n = $names[$l->driver_id] ?? '?';
            Log::info("║    - #{$l->driver_id} {$n} → {$l->result}");
        }
        Log::info("╚══════════════════════════════════════════════════════════════");
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('result', 'pending')
            ->update(['result' => 'accepted', 'responded_at' => now()]);

        RTDBService::clearDriverOffer($driver->id);

        $attempts = OrderDispatchLog::where('order_id', $order->id)->count();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} ĐƯỢC NHẬN");
        Log::info("║  Tài xế  : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("║  Sau lần thử: #{$attempts}");
        Log::info("╚══════════════════════════════════════════════════════════════");
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Tính bán kính hiện tại dựa theo thời gian đã trôi qua kể từ startDispatch.
     */
    private function currentRadius(Order $order): float
    {
        if (!$order->dispatch_started_at) {
            return self::RADIUS_STAGES[0]['km'];
        }

        $elapsed  = now()->diffInSeconds(Carbon::parse($order->dispatch_started_at));
        $radiusKm = self::RADIUS_STAGES[0]['km'];

        foreach (self::RADIUS_STAGES as $stage) {
            if ($elapsed >= $stage['after_secs']) {
                $radiusKm = $stage['km'];
            }
        }

        return $radiusKm;
    }

    private function sendToDriver(Order $order, User $driver): void
    {
        $now  = now();
        $dist = round($this->distanceKm($driver, $order), 2);

        $ratings = Order::where('delivery_man_id', $driver->id)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->avg('driver_rating') ?? 0;

        $distScore   = round((1 - min($dist, self::RADIUS_STAGES[count(self::RADIUS_STAGES) - 1]['km']) / self::RADIUS_STAGES[count(self::RADIUS_STAGES) - 1]['km']) * self::W_DISTANCE, 1);
        $scoreScore  = round((($driver->driver_score ?? 80) / 100) * self::W_SCORE, 1);
        $ratingScore = round(($ratings / 5) * self::W_RATING, 1);
        $waitScore   = round($this->waitTimeScore($driver), 1);
        $total       = round($distScore + $scoreScore + $ratingScore + $waitScore, 1);

        Log::info("│");
        Log::info("└→ [Dispatch] GỬI ĐƠN #{$order->id}");
        Log::info("     Tài xế   : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("     Khoảng cách: {$dist} km");
        Log::info("     Điểm tổng : {$total} = dist({$distScore}) + score({$scoreScore}) + rating({$ratingScore}) + wait({$waitScore})");
        Log::info("     driver_score: " . ($driver->driver_score ?? 80) . " | avg_rating: " . round($ratings, 2));
        Log::info("     FCM token : " . ($driver->fcm_token ? 'có' : 'KHÔNG CÓ'));

        OrderDispatchLog::create([
            'order_id'   => $order->id,
            'driver_id'  => $driver->id,
            'offered_at' => $now,
            'result'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'dispatching_to_driver_id' => $driver->id,
            'dispatch_attempts'        => DB::raw('dispatch_attempts + 1'),
            'updated_at'               => $now,
        ]);

        // Ghi offer lên RTDB — driver app nhận qua stream real-time (< 300ms)
        $offeredAt = $now->timestamp;
        $expiresAt = $offeredAt + self::DRIVER_OFFER_SECS;
        RTDBService::writeDriverOffer($driver->id, [
            'order_id'         => $order->id,
            'order_code'       => $order->code,
            'offered_at'       => $offeredAt,
            'expires_at'       => $expiresAt,
            'service_type'     => $order->service_type,
            'pickup_address'   => $order->pickup_address  ?? '',
            'pickup_name'      => $order->sender_name     ?? '',
            'pickup_phone'     => $order->pickup_phone    ?? '',
            'pickup_lat'       => $order->pickup_lat      ? (float) $order->pickup_lat  : null,
            'pickup_lng'       => $order->pickup_lng      ? (float) $order->pickup_lng  : null,
            'delivery_address' => $order->delivery_address ?? '',
            'delivery_phone'   => $order->delivery_phone  ?? '',
            'delivery_lat'     => $order->delivery_lat    ? (float) $order->delivery_lat : null,
            'delivery_lng'     => $order->delivery_lng    ? (float) $order->delivery_lng : null,
            'order_note'       => $order->order_note      ?? '',
            'store_name'       => $order->store_name      ?? '',
            'platform'          => $order->platform          ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type ?? null,
            'cargo_type'        => $order->cargo_type        ?? null,
            'cargo_note'       => $order->cargo_note      ?? null,
            'cargo_weight'     => $order->cargo_weight    ? (float) $order->cargo_weight : null,
            'is_batch'         => (bool) ($order->is_batch ?? false),
            'stops_count'      => $order->is_batch ? count($order->stops ?? []) : 0,
            'shipping_fee'     => (int) ($order->shipping_fee   ?? 0),
            'discount_amount'  => (int) ($order->discount_amount ?? 0),
            'bonus_fee'        => (int) ($order->bonus_fee       ?? 0),
            'payment_method'   => $order->payment_method  ?? 'prepaid',
            'cod_amount'       => (int) ($order->cod_amount      ?? 0),
            'customer_phone'   => $order->sender?->phone  ?? '',
        ]);
        Log::debug("     → RTDB offer ghi thành công (expires_at: {$expiresAt})");

        // FCM chỉ để wake-up khi app bị kill — RTDB là nguồn dữ liệu chính
        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverWakeUp($driver->fcm_token, $order->id);
                Log::debug("     → FCM wake-up gửi thành công");
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(self::DRIVER_OFFER_SECS));
    }

    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = []): Collection
    {
        if (!$order->city_id) {
            Log::warning("[Dispatch] Đơn #{$order->id} không có city_id → không thể tìm tài xế");
            return collect();
        }

        $now           = now();
        // Chỉ loại tài xế đang có 2+ đơn active (tối đa 2 đơn/lần)
        $busyDriverIds = Order::selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->groupBy('delivery_man_id')
            ->havingRaw('cnt >= 2')
            ->pluck('delivery_man_id');

        // Loại tài xế đang được phát offer (chưa phản hồi trong 30s)
        $receivingOfferIds = Order::where('status', 'pending')
            ->whereNotNull('dispatching_to_driver_id')
            ->where('id', '!=', $order->id)
            ->pluck('dispatching_to_driver_id');

        $unavailableIds = $busyDriverIds->merge($receivingOfferIds)->unique();

        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotIn('id', $unavailableIds)
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            // Loại tài xế đang bị khóa nhận đơn
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', $now);
            })
            ->with(['debts', 'wallet', 'driverLicenses'])
            ->get();

        Log::debug("     [Candidates] Online trong thành phố: {$candidates->count()} | Bận: {$busyDriverIds->count()} | Đang nhận offer: {$receivingOfferIds->count()} | Đã hỏi: " . count($excludeIds));

        $beforeDebt  = $candidates->count();
        $afterDebt   = $candidates->filter(fn(User $d) => !$this->hasBlockedDebt($d));
        $debtRemoved = $beforeDebt - $afterDebt->count();
        if ($debtRemoved > 0) {
            Log::debug("     [Candidates] Loại {$debtRemoved} tài xế do nợ quá hạn");
        }

        $afterLicense = $afterDebt->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license);
        $licRemoved   = $afterDebt->count() - $afterLicense->count();
        if ($licRemoved > 0) {
            Log::debug("     [Candidates] Loại {$licRemoved} tài xế do không có bằng xe hơi");
        }

        $afterRadius = $afterLicense->filter(fn(User $d) => $this->distanceKm($d, $order) <= $radiusKm);
        $radRemoved  = $afterLicense->count() - $afterRadius->count();
        if ($radRemoved > 0) {
            Log::debug("     [Candidates] Loại {$radRemoved} tài xế do ngoài bán kính {$radiusKm}km");
        }

        $driverIds = $afterRadius->pluck('id')->toArray();

        $ratings = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('AVG(driver_rating) as avg_rating'))
            ->groupBy('delivery_man_id')
            ->pluck('avg_rating', 'delivery_man_id');

        $sorted = $afterRadius
            ->sortByDesc(fn(User $d) => $this->compositeScore($d, $order, (float) ($ratings[$d->id] ?? 0)))
            ->take(self::MAX_DRIVERS)
            ->values();

        if ($sorted->isNotEmpty()) {
            Log::debug("     [Candidates] Top " . min(5, $sorted->count()) . " tài xế sẽ được hỏi:");
            foreach ($sorted->take(5) as $i => $d) {
                $dist  = round($this->distanceKm($d, $order), 2);
                $score = round($this->compositeScore($d, $order, (float) ($ratings[$d->id] ?? 0)), 1);
                $wait  = round($this->waitTimeScore($d), 1);
                Log::debug("       " . ($i + 1) . ". #{$d->id} {$d->name} | {$dist}km | điểm={$score} | driver_score=" . ($d->driver_score ?? 80) . " | wait={$wait}");
            }
        }

        return $sorted;
    }

    /**
     * Composite score: dist(40) + driver_score(35) + rating(15) + wait_time(10)
     */
    private function compositeScore(User $driver, Order $order, float $avgRating): float
    {
        $dist        = $this->distanceKm($driver, $order);
        $maxRadius   = self::RADIUS_STAGES[count(self::RADIUS_STAGES) - 1]['km'];
        $distScore   = (1 - min($dist, $maxRadius) / $maxRadius) * self::W_DISTANCE;
        $scoreScore  = (($driver->driver_score ?? 80) / 100) * self::W_SCORE;
        $ratingScore = ($avgRating / 5) * self::W_RATING;
        $waitScore   = $this->waitTimeScore($driver);

        return $distScore + $scoreScore + $ratingScore + $waitScore;
    }

    /**
     * Bonus tối đa 10 điểm cho tài xế chờ lâu chưa có đơn.
     * online_since reset về null khi tài xế nhận đơn, set lại khi online.
     */
    private function waitTimeScore(User $driver): float
    {
        if (!$driver->online_since) return 0;

        $waitMins = min(self::WAIT_TIME_CAP_MINS, now()->diffInMinutes(Carbon::parse($driver->online_since)));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
    }

    private function distanceKm(User $driver, Order $order): float
    {
        return $this->haversineKm(
            (float) $driver->latitude,  (float) $driver->longitude,
            (float) $order->pickup_lat, (float) $order->pickup_lng
        );
    }

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
