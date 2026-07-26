<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Jobs\DispatchOrderRetryJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\RTDBService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;
use Illuminate\Support\Collection;
use App\Events\DispatchStateChanged;
use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DispatchService
{
    const DRIVER_OFFER_SECS  = 25;   // giây để mở app (trước khi offer_viewed_at set)
    const APP_DECISION_SECS  = 30;   // giây để đọc & quyết định SAU KHI mở app (như ShopeeFood)
    const FCM_TTL_SECS       = 25;
    const MAX_DRIVERS        = 50;

    // Trọng số xếp hạng
    const W_SCORE         = 15;
    const W_RATING_CNT    = 10;
    const W_WAIT_TIME     = 50;
    const W_DISTANCE      = 25;

    const WAIT_TIME_CAP_MINS = 480; // 8 tiếng — tài xế chờ lâu được ưu tiên rõ hơn
    const RATING_COUNT_CAP   = 200;

    // Ghép đơn tự động trong getCandidates() — điều kiện: điểm lấy 2 đơn gần
    // nhau VÀ điểm giao 2 đơn cũng gần nhau (cùng khu vực lấy, cùng khu vực
    // giao mới hợp lý để 1 tài xế chạy được cả 2 mà không vòng vèo quá xa).
    const BATCH_MAX_PICKUP_KM   = 1.0;
    const BATCH_MAX_DELIVERY_KM = 1.5;

    // Bỏ lỡ (không xem) đủ 2 offer liên tiếp → tự tắt online. Lần đầu trong
    // ngày chỉ tự tắt (không phạt điểm — có thể là lỡ ngẫu nhiên 1 lần), từ
    // lần thứ 2 trong ngày trở đi mới trừ điểm — nhắm đúng nhóm lặp lại
    // nhiều lần/ngày (treo app farm giờ online mà né đơn), không phạt oan
    // người chỉ gặp 1 cụm lỡ đơn hiếm hoi.
    const MISSED_OFFERS_AUTO_OFFLINE_THRESHOLD = 2;

    // Chặn A — chỉ coi vị trí tài xế là đáng tin nếu đã được xác nhận mới
    // KỂ TỪ KHI bật online phiên này. App gửi /update-location mỗi 30s vô
    // điều kiện (không phụ thuộc di chuyển) nên tài xế đứng yên lâu vẫn được
    // làm mới liên tục — không bị chặn oan. Van an toàn: sau vài phút không
    // có vị trí mới (GPS lỗi) vẫn cho vào lại, tránh khoá tài xế vĩnh viễn.
    const LOCATION_FRESHNESS_GRACE_SECS    = 5;
    const LOCATION_FRESHNESS_MAX_WAIT_MINS = 3;

    // Không còn khái niệm "bán kính tìm kiếm" (2km/4km đường chim bay) — quét
    // TOÀN BỘ tài xế online đủ điều kiện trong thành phố ngay từ đầu, tính
    // khoảng cách đường đi thật (Google Distance Matrix, 1 lần cho cả lô) cho
    // tất cả, rồi lọc thẳng ai vượt trần này. Không ai trong trần thì coi như
    // không có tài xế, KHÔNG gán đại người xa — gán xa chỉ dời vấn đề sang lúc
    // tài xế huỷ/không chạy, không giải quyết được gì thêm.
    const MAX_ROAD_DISTANCE_KM = 4.0;

    // =========================================================================
    // REDIS HELPERS
    // =========================================================================

    private function retryKey(int $orderId): string
    {
        return "dispatch:retry_pending:{$orderId}";
    }

    private function clearDispatchCache(int $orderId): void
    {
        Redis::del($this->retryKey($orderId));
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        if (!$order->pickup_lat || !$order->pickup_lng) {
            Log::warning("[Dispatch] Đơn #{$order->id} thiếu toạ độ pickup → không dispatch");
            return;
        }

        if ($order->dispatching_to_driver_id !== null) {
            Log::info("[Dispatch] Đơn #{$order->id} đang chờ tài xế #{$order->dispatching_to_driver_id} → bỏ qua restart");
            return;
        }

        $now = now();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] BẮT ĐẦU PHÁT ĐƠN");
        Log::info("║  Đơn     : #{$order->id} | Mã: {$order->code}");
        Log::info("║  Loại    : {$order->service_type}");
        Log::info("║  Thành phố: {$order->city_id}");
        Log::info("║  Pickup  : {$order->pickup_address} ({$order->pickup_lat}, {$order->pickup_lng})");
        Log::info("╚══════════════════════════════════════════════════════════════");

        DB::table('orders')->where('id', $order->id)->update([
            'dispatch_started_at' => $order->dispatch_started_at ?? $now,
            'cancel_reason'       => null,
        ]);
        $order->dispatch_started_at = $order->dispatch_started_at ?? $now;
        $order->cancel_reason       = null;

        $this->notifyCustomer($order, 'searching');

        $this->offerToNext($order);
    }

    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;
        $this->offerToNext($order);
    }

    public function handleTimeout(Order $order, int $driverId): void
    {
        $driver = User::find($driverId);
        $name   = $driver?->name ?? "#{$driverId}";

        $updated = DB::transaction(function () use ($order, $driverId) {
            return OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $driverId)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->update(['result' => 'expired', 'responded_at' => now()]);
        });

        if (!$updated) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xử lý trước (decline/accept) → bỏ qua timeout");
            return;
        }

        Redis::del("dispatch:lock:driver:{$driverId}");

        // Offer đã kết thúc — xoá con trỏ "đang hỏi ai" NGAY, đừng đợi tìm được
        // người kế. Nếu không còn ứng viên, con trỏ ôi sẽ (1) hiển thị sai
        // "Đang chờ X" trên monitor và (2) khoá X khỏi mọi đơn khác vì bộ quét
        // coi X là "đang cầm offer" (tối đa 15 phút). Guard theo driverId để
        // không đè con trỏ nếu luồng khác đã kịp trỏ sang tài xế mới.
        DB::table('orders')->where('id', $order->id)
            ->where('dispatching_to_driver_id', $driverId)
            ->update(['dispatching_to_driver_id' => null, 'updated_at' => now()]);

        if ($order->offer_viewed_at) {
            DriverScoreService::onDecline($driverId);
            // Có tương tác (đã xem) → reset chuỗi bỏ lỡ
            DB::table('users')->where('id', $driverId)
                ->where('consecutive_missed_offers', '>', 0)
                ->update(['consecutive_missed_offers' => 0]);
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} xem đơn nhưng không nhận → -2 điểm, pop tiếp");
        } else {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} không xem đơn → 0 điểm, pop tiếp");
            if ($driver) {
                $this->trackMissedOffer($driver);
            }
        }

        RTDBService::clearDriverOffer($driverId);

        $this->sendToNextDriver($order->fresh());
    }

    /**
     * Đếm offer bỏ lỡ liên tiếp (không xem) — đủ ngưỡng tự tắt online.
     * Chặn chiêu treo app tích giờ online mà né đơn: lần đầu trong ngày chỉ
     * tự tắt (không phạt — có thể lỡ ngẫu nhiên), từ lần thứ 2 trong ngày trở
     * đi mới trừ điểm — nhắm đúng người lặp lại nhiều lần/ngày. Không đếm khi
     * đang có đơn active (đang chạy giao ngoài đường không thể bấm điện thoại
     * — lỡ đơn ghép là chính đáng).
     */
    private function trackMissedOffer(User $driver): void
    {
        $hasActive = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->exists();
        if ($hasActive) {
            return;
        }

        $missed = (int) DB::table('users')->where('id', $driver->id)->value('consecutive_missed_offers') + 1;
        DB::table('users')->where('id', $driver->id)->update(['consecutive_missed_offers' => $missed]);

        if ($missed < self::MISSED_OFFERS_AUTO_OFFLINE_THRESHOLD) {
            if ($missed === 1 && $driver->fcm_token) {
                FCMService::getInstance()->sendDriverNotice(
                    $driver->fcm_token,
                    '⚠️ Bạn vừa bỏ lỡ 1 đơn',
                    'Bỏ lỡ thêm 1 đơn nữa sẽ bị tạm tắt nhận đơn. Mở app để sẵn sàng nhận đơn nhé!',
                    ['type' => 'missed_offers_warning'],
                );
            }
            return;
        }

        // ── Đủ ngưỡng: tự tắt online ──────────────────────────────────────────
        // Từ lần thứ 2 trong ngày trở đi mới trừ điểm (-3/lần) — lazy-reset bộ
        // đếm theo ngày giống pattern daily_online_seconds/daily_online_date.
        $today             = now()->toDateString();
        $offlineCountToday = ($driver->missed_offer_offline_date === $today)
            ? (int) ($driver->missed_offer_offline_count ?? 0)
            : 0;
        $offlineCountToday++;

        DB::table('users')->where('id', $driver->id)->update([
            'missed_offer_offline_count' => $offlineCountToday,
            'missed_offer_offline_date'  => $today,
        ]);

        if ($offlineCountToday >= 2) {
            // Đang bật chế độ trời mưa cho thành phố này → bỏ qua phạt điểm,
            // lơ đơn lúc mưa to nhiều khả năng là chính đáng (đường ngập,
            // nguy hiểm), không phải né đơn. Vẫn tự tắt online như thường —
            // chỉ miễn phần trừ điểm.
            $isRaining = \Modules\Core\Models\City::where('id', $driver->city_id)->value('is_rain_mode');
            if ($isRaining) {
                Log::info("[Dispatch] Tài xế #{$driver->id} {$driver->name} tự tắt online lần {$offlineCountToday} trong ngày — bỏ qua phạt điểm do thành phố đang bật chế độ trời mưa");
            } else {
                DriverScoreService::onMissedOfferStreak($driver->id);
                Log::warning("[Dispatch] Tài xế #{$driver->id} {$driver->name} bị tự tắt online lần {$offlineCountToday} trong ngày → -3 điểm");
            }
        }

        // Tích lũy giờ online của phiên hiện tại (cùng logic với toggleOnline,
        // chỉ tính phần nằm trong cửa sổ [6:30, now]).
        $now    = now();
        $update = [
            'is_online'                 => false,
            'online_since'              => null,
            'consecutive_missed_offers' => 0,
        ];

        if ($driver->online_since) {
            $windowStart = User::onlineWindowStart($now);
            $onlineSince = Carbon::parse($driver->online_since);
            $sessionSeconds = 0;
            if ($now->greaterThan($windowStart)) {
                $sessionStart   = $onlineSince->greaterThan($windowStart) ? $onlineSince : $windowStart;
                $sessionSeconds = (int) max(0, $sessionStart->diffInSeconds($now, false));
            }
            $existing = ($driver->daily_online_date === $now->toDateString())
                ? (int) ($driver->daily_online_seconds ?? 0)
                : 0;
            $update['daily_online_seconds'] = $existing + $sessionSeconds;
            $update['daily_online_date']    = $now->toDateString();
        }

        DB::table('users')->where('id', $driver->id)->update($update);

        RTDBService::setDriverOnlineStatus($driver->id, false);

        if ($driver->fcm_token) {
            FCMService::getInstance()->sendDriverNotice(
                $driver->fcm_token,
                'Bạn đã bị tạm tắt nhận đơn',
                'Bạn bỏ lỡ 2 đơn liên tiếp nên hệ thống tạm tắt online. Mở app và bật lại khi sẵn sàng chạy.',
                ['type' => 'auto_offline_missed'],
            );
        }

        Log::warning("[Dispatch] Tài xế #{$driver->id} {$driver->name} bỏ lỡ " . self::MISSED_OFFERS_AUTO_OFFLINE_THRESHOLD . " offer liên tiếp → tự tắt online");
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        $updated = DB::transaction(function () use ($order, $driver) {
            return OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $driver->id)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->update(['result' => 'accepted', 'responded_at' => now()]);
        });

        if (!$updated) {
            Log::info("[Dispatch] Đơn #{$order->id}: Tài xế #{$driver->id} accept nhưng log đã đổi (timeout race) → bỏ qua");
            return;
        }

        RTDBService::clearDriverOffer($driver->id);
        $this->clearDispatchCache($order->id);

        $attempts = OrderDispatchLog::where('order_id', $order->id)->count();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} ĐƯỢC NHẬN");
        Log::info("║  Tài xế  : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("║  Sau lần thử: #{$attempts}");
        Log::info("╚══════════════════════════════════════════════════════════════");
        broadcast(new DispatchStateChanged());
    }

    // =========================================================================
    // PRIVATE — DISPATCH CONTROL FLOW
    // =========================================================================

    public function markNoDriver(Order $order): void
    {
        $this->cancelNoDriver($order);
    }

    private function cancelNoDriver(Order $order): void
    {
        // Giữ đơn ở trạng thái pending, không hủy
        // Chỉ dừng dispatch và thông báo cho admin xử lý thủ công
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'dispatching_to_driver_id' => null,
                'cancel_reason'            => 'no_driver',
                'updated_at'               => now(),
            ]);

        if (!$updated) return;

        $this->clearDispatchCache($order->id);
        broadcast(new DispatchStateChanged());

        $admins = User::whereIn('user_type', ['admin', 'call_center', 'city_manager'])
            ->where('city_id', $order->city_id)
            ->get();

        $znsTemplateId = config('services.zalo_zns.no_driver_template_id');

        foreach ($admins as $admin) {
            \Filament\Notifications\Notification::make()
                ->title("Đơn #{$order->code} — Không tìm được tài xế")
                ->body("Đơn từ {$order->pickup_address} đã quá " . self::DISPATCH_TIMEOUT_MINS . " phút không có tài xế nhận. Vui lòng xử lý thủ công.")
                ->danger()
                ->sendToDatabase($admin);

            if ($znsTemplateId && $admin->phone) {
                ZaloTokenService::sendTemplate($admin->phone, $znsTemplateId, [
                    'customer_name' => $admin->name,
                    'customer_id'   => $order->code,
                    'address'       => $order->pickup_address,
                ]);
            }
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Không tìm được tài xế sau " . self::DISPATCH_TIMEOUT_MINS . " phút → giữ pending, dừng dispatch");
    }

    public function offerToNext(Order $order): void
    {
        $order = $order->fresh();
        if (!$order || $order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        Log::info("┌─ [Dispatch] Đơn #{$order->id} | Quét toàn thành phố (≤" . self::MAX_ROAD_DISTANCE_KM . "km đường thật) | Đã hỏi: " . count($alreadyOffered));

        $candidates = $this->getCandidates($order, $alreadyOffered);

        if ($candidates->isEmpty()) {
            Log::info("└─ [Dispatch] Đơn #{$order->id}: không có ứng viên nào trong " . self::MAX_ROAD_DISTANCE_KM . "km đường thật → chờ quét lại");
            $this->scheduleRetryOrGiveUp($order);
            return;
        }

        $driver = $candidates->first();
        Log::info("│  Chọn: #{$driver->id} {$driver->name} | " . count($alreadyOffered) . " đã hỏi trước");
        $this->sendToDriver($order, $driver);
    }

    const DISPATCH_TIMEOUT_MINS = 15;

    /**
     * Không tìm được ai vòng này — hẹn quét lại toàn thành phố sau 15 giây
     * (có thể lúc đó đã có tài xế di chuyển vào phạm vi, vừa bật online, hoặc
     * vừa giao xong đơn cũ rảnh trở lại). Quá 15 phút kể từ lúc bắt đầu tìm
     * thì mới thật sự bỏ cuộc, báo admin xử lý tay.
     */
    private function scheduleRetryOrGiveUp(Order $order): void
    {
        $order = $order->fresh();
        if (!$order || $order->status !== 'pending') return;

        if ($order->dispatch_started_at) {
            $elapsed = (int) abs(now()->diffInMinutes($order->dispatch_started_at));
            if ($elapsed >= self::DISPATCH_TIMEOUT_MINS) {
                Log::info("╟── [Dispatch] Đơn #{$order->id}: Quá {$elapsed} phút không có tài xế → dừng dispatch, giữ pending");
                $this->cancelNoDriver($order);
                return;
            }
        }

        // Chống tích lũy RetryJob: chỉ schedule 1 retry tại 1 thời điểm
        if (!Redis::set($this->retryKey($order->id), 1, 'NX', 'EX', 20)) {
            Log::debug("╟── [Dispatch] Đơn #{$order->id}: Retry đã được lên lịch, bỏ qua");
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Chưa có ai → quét lại sau 15s");
        DispatchOrderRetryJob::dispatch($order->id)->delay(now()->addSeconds(15));
    }


    // =========================================================================
    // PRIVATE — SEND + SCORING
    // =========================================================================

    private function sendToDriver(Order $order, User $driver): void
    {
        // Lock tài xế — chặn 2 dispatch gán cùng lúc
        $lockKey = "dispatch:lock:driver:{$driver->id}";
        if (!Redis::set($lockKey, $order->id, 'NX', 'EX', 60)) {
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đang nhận offer từ dispatch khác");
            $this->sendToNextDriver($order);
            return;
        }

        // Verify lại lần cuối — tài xế có thể nhận đơn giữa lúc pop và lock
        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        if ($activeCount >= 2) {
            Redis::del($lockKey);
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đã có {$activeCount} đơn active");
            $this->sendToNextDriver($order);
            return;
        }

        // Không cần kiểm tra lại trần khoảng cách ở đây — getCandidates() đã
        // tính khoảng cách đường thật và lọc ≤ MAX_ROAD_DISTANCE_KM cho MỌI ứng
        // viên trước khi trả về, không chỉ một nhóm nhỏ như cách cũ.
        $now  = now();
        $dist = $driver->_road_km !== null
            ? round($driver->_road_km, 2)
            : round($this->haversineKm(
                (float) ($driver->latitude ?? 0), (float) ($driver->longitude ?? 0),
                (float) $order->pickup_lat, (float) $order->pickup_lng
            ), 2);

        $ratingCount    = Order::where('delivery_man_id', $driver->id)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->count();

        $scoreScore     = round(($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE, 1);
        $ratingCntScore = round(min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT, 1);
        $waitScore      = round($this->waitTimeScore($driver), 1);
        $total          = round($scoreScore + $ratingCntScore + $waitScore, 1);

        Log::info("│");
        Log::info("└→ [Dispatch] GỬI ĐƠN #{$order->id}");
        Log::info("     Tài xế     : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("     Khoảng cách (đường thật): {$dist} km");
        Log::info("     Điểm tổng  : {$total} = score({$scoreScore}) + so_dg({$ratingCntScore}) + wait({$waitScore})");
        Log::info("     driver_score: " . ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | so_danh_gia: {$ratingCount}");
        Log::info("     FCM token  : " . ($driver->fcm_token ? 'có' : 'KHÔNG CÓ'));

        $ok = $this->commitOffer($order, $driver, $now);

        if (!$ok) {
            Redis::del($lockKey);
            Log::warning("│  Skip #{$driver->id} {$driver->name}: ghi RTDB thất bại — chuyển ứng viên kế ngay, không đợi hết hạn oan");
            $this->sendToNextDriver($order);
        }
    }

    /**
     * Thực hiện gửi offer thật cho 1 tài xế đã qua hết mọi bước kiểm tra
     * (ghi RTDB + OrderDispatchLog + cập nhật đơn + FCM + lên lịch timeout
     * job) — dùng cho luồng auto dispatch (sendToDriver()), lỗi thì cascade
     * sang ứng viên kế. Gán tay giờ dùng assignDriverDirectly() riêng, KHÔNG
     * qua bước offer nên không gọi hàm này.
     *
     * @return bool true nếu ghi RTDB thành công (offer đã thật sự tới tay tài xế)
     */
    private function commitOffer(Order $order, User $driver, Carbon $now): bool
    {
        // Ghi RTDB TRƯỚC khi cam kết gán offer cho tài xế này (tạo log/cập
        // nhật đơn) — RTDB là kênh CHÍNH để app đọc offer, ghi thất bại thì
        // tài xế không hề nhận được gì dù hệ thống tưởng đã gửi. Kiểm tra kết
        // quả thật (không nuốt lỗi âm thầm như trước) để có thể xử lý ngay,
        // không bắt tài xế "ôm" offer ma rồi đợi hết 25s hệ thống mới nhận ra.
        $offeredAt = $now->timestamp;
        $expiresAt = $offeredAt + self::DRIVER_OFFER_SECS;
        $rtdbOk = RTDBService::writeDriverOffer($driver->id, [
            'order_id'          => $order->id,
            'order_code'        => $order->code,
            'offered_at'        => $offeredAt,
            'expires_at'        => $expiresAt,
            'service_type'      => $order->service_type,
            'pickup_address'    => $order->pickup_address    ?? '',
            'pickup_place_name' => $order->pickup_place_name ?? null,
            'pickup_name'       => $order->sender_name       ?? '',
            'pickup_phone'      => $order->pickup_phone      ?? '',
            'pickup_lat'        => $order->pickup_lat        ? (float) $order->pickup_lat  : null,
            'pickup_lng'        => $order->pickup_lng        ? (float) $order->pickup_lng  : null,
            'delivery_address'  => $order->delivery_address  ?? '',
            'delivery_phone'    => $order->delivery_phone    ?? '',
            'receiver_name'     => $order->receiver_name     ?? '',
            'delivery_lat'      => $order->delivery_lat      ? (float) $order->delivery_lat : null,
            'delivery_lng'      => $order->delivery_lng      ? (float) $order->delivery_lng : null,
            'order_note'        => $order->order_note        ?? '',
            'store_name'        => $order->store_name        ?? '',
            'platform'          => $order->platform          ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type ?? null,
            'cargo_type'        => $order->cargo_type        ?? null,
            'cargo_note'        => $order->cargo_note        ?? null,
            'cargo_weight'      => $order->cargo_weight      ? (float) $order->cargo_weight : null,
            'is_batch'          => (bool) ($order->is_batch  ?? false),
            'stops_count'       => $order->is_batch ? count($order->stops ?? []) : 0,
            'stops'             => $order->is_batch ? ($order->stops ?? []) : [],
            'shipping_fee'      => (int) ($order->shipping_fee    ?? 0),
            'discount_amount'   => (int) ($order->discount_amount ?? 0),
            'voucher_code'      => $order->voucher_code      ?? null,
            'bonus_fee'         => (int) ($order->bonus_fee  ?? 0),
            'payment_method'    => $order->payment_method    ?? 'prepaid',
            'cod_amount'        => (int) ($order->cod_amount ?? 0),
            'customer_phone'    => $order->sender?->phone    ?? '',
        ]);

        if (!$rtdbOk) {
            // QUAN TRỌNG: phải ghi nhận đã "hỏi" driver này trước khi trả về
            // — nếu không, getCandidates() ở lượt sau vẫn coi driver này là
            // ứng viên hợp lệ và có thể chọn lại chính họ, gây đệ quy vô hạn
            // nếu Firebase sập hẳn và họ là ứng viên duy nhất (đúng lỗi đã
            // gặp và sửa ở Chặn B trước đây). Tái dùng 'expired' vì ENUM chỉ
            // có 4 giá trị cố định — không gọi handleTimeout() nên không phạt
            // điểm/tính bỏ lỡ cho tài xế, đúng tinh thần sửa lỗi này.
            $failedAt = now();
            OrderDispatchLog::create([
                'order_id'     => $order->id,
                'driver_id'    => $driver->id,
                'offered_at'   => $failedAt,
                'responded_at' => $failedAt,
                'result'       => 'expired',
                'created_at'   => $failedAt,
                'updated_at'   => $failedAt,
            ]);
            return false;
        }

        Log::debug("     → RTDB offer ghi thành công (expires_at: {$expiresAt})");

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
            'offer_viewed_at'          => null,
            'updated_at'               => $now,
        ]);
        $order->offer_viewed_at = null;

        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverWakeUp($driver->fcm_token, $order->id, $order->code, $order->pickup_address ?? '', $expiresAt);
                Log::debug("     → FCM wake-up gửi thành công");
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(self::DRIVER_OFFER_SECS));

        broadcast(new DispatchStateChanged());

        return true;
    }

    /**
     * Tổng đài gán CỨNG 1 tài xế cụ thể cho đơn — KHÔNG qua bước offer/chờ
     * xác nhận trong app, đơn chuyển thẳng sang "assigned" (vào mục "Đã
     * nhận" của tài xế ngay). Dùng khi tổng đài đã xác nhận trực tiếp với
     * tài xế qua điện thoại, không cần hỏi lại lần nữa qua app.
     *
     * Vẫn kiểm tra các điều kiện an toàn cơ bản (đúng thành phố, không nợ
     * quá hạn, đủ bằng lái, không bận ≥2 đơn) trước khi gán — chỉ bỏ qua
     * bước "chờ tài xế bấm nhận", không bỏ qua kiểm tra hợp lệ.
     *
     * @return array{success: bool, message: string}
     */
    public function assignDriverDirectly(Order $order, int $driverId): array
    {
        $driver = User::with('debts')->find($driverId);

        if (!$driver || $driver->user_type !== 'driver') {
            return ['success' => false, 'message' => 'Không tìm thấy tài xế.'];
        }
        if ($driver->city_id !== $order->city_id) {
            return ['success' => false, 'message' => 'Tài xế không thuộc thành phố của đơn này.'];
        }
        if ($this->hasBlockedDebt($driver)) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} đang nợ quá hạn, không thể nhận đơn."];
        }
        if ($order->service_type === 'car' && !$driver->has_car_license) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} chưa có bằng lái ô tô."];
        }

        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        if ($activeCount >= 2) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} đang chạy {$activeCount} đơn, không nhận thêm được."];
        }

        $now      = now();
        $affected = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'status'                   => 'assigned',
                'delivery_man_id'          => $driver->id,
                'dispatching_to_driver_id' => null,
                'updated_at'               => $now,
            ]);

        if (!$affected) {
            return ['success' => false, 'message' => 'Đơn không còn ở trạng thái chờ (có thể vừa được xử lý).'];
        }

        // Chụp lại NGAY LÚC GÁN xem thành phố có đang bật chế độ trời mưa
        // không — khoá giá trị cho đơn, không đổi theo trạng thái mưa hiện
        // tại nữa dù sau đó tắt/bật lại giữa chừng.
        if (\Modules\Core\Models\City::where('id', $order->city_id)->value('is_rain_mode')) {
            DB::table('orders')->where('id', $order->id)->update(['rain_bonus_eligible' => true]);
        }

        // Ghi lại như 1 dòng "accepted" bình thường — để lên báo cáo/lịch sử
        // dispatch không bị thiếu, dù đây là gán tay bỏ qua bước offer.
        OrderDispatchLog::create([
            'order_id'     => $order->id,
            'driver_id'    => $driver->id,
            'offered_at'   => $now,
            'responded_at' => $now,
            'result'       => 'accepted',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Redis::del("dispatch:lock:driver:{$driver->id}");
        DB::table('users')->where('id', $driver->id)->update([
            'last_order_accepted_at'    => $now,
            'consecutive_missed_offers' => 0,
        ]);

        // RTDB + FCM đồng bộ khách hàng — giống hệt luồng accept bình thường
        // (đơn call-center thường không có tài khoản khách nên các bước này
        // tự no-op nếu sender_platform_id null).
        RTDBService::updateOrderStatus($order->code, 'assigned');
        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $order->code, 'assigned');
        }
        if ($customer) {
            CustomerNotificationController::create(
                $customer->id,
                "Đơn #{$order->code}",
                'Tài xế đã nhận đơn và đang trên đường đến',
                'order_status',
                $order->code
            );
        }

        // Báo cho tài xế biết họ vừa được gán đơn — không phải offer chờ
        // bấm nhận, chỉ là thông báo để họ mở app thấy đơn trong mục "Đã
        // nhận" ngay.
        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverNotice(
                    $driver->fcm_token,
                    "Bạn được gán đơn mới #{$order->code}",
                    $order->pickup_address ?? '',
                    ['type' => 'order_assigned_direct', 'order_id' => (string) $order->id]
                );
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM assign-notice failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        broadcast(new DispatchStateChanged());

        return ['success' => true, 'message' => "Đã gán đơn cho {$driver->name} — vào mục \"Đã nhận\" ngay, không cần chờ xác nhận."];
    }

    public function getCandidates(Order $order, array $excludeIds = []): Collection
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
        // Không lọc theo last_location_at có "còn mới nói chung" hay không (app
        // chỉ gửi GPS khi di chuyển ≥10m qua kênh RTDB, tài xế ngồi yên sẽ stale
        // theo kiểu đó) — lọc theo HEARTBEAT: app sống thì đập 30s/lần kể cả đứng
        // yên, mất nhịp quá 10 phút nghĩa là app bị kill/máy tắt nguồn.
        $hbCutoff = $now->copy()->subMinutes(10);
        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->whereNotIn('id', $excludeIds)
            ->whereNotIn('id', $unavailableIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('status', 1)
            ->where('is_online', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', $now);
            })
            ->where(function ($q) use ($hbCutoff) {
                $q->whereNull('last_heartbeat_at')
                  ->orWhere('last_heartbeat_at', '>=', $hbCutoff);
            })
            // Chặn A — toạ độ chỉ đáng tin nếu đã được xác nhận mới KỂ TỪ KHI
            // bật online phiên này (khác với check heartbeat ở trên — heartbeat
            // chỉ chứng minh "app còn sống", không chứng minh "toạ độ đang lưu
            // đúng vị trí hiện tại"). App gửi /update-location mỗi 30s vô điều
            // kiện (bất kể có di chuyển) nên chỉ cần 1 lần cập nhật sau khi bật
            // online là đủ tin mãi cho phiên đó — không phạt oan tài xế đứng yên
            // lâu. Van an toàn: quá 3 phút chưa có vị trí mới (GPS lỗi) thì vẫn
            // cho vào lại, tránh khoá tài xế vĩnh viễn vì lý do họ không biết.
            ->where(function ($q) use ($now) {
                $q->whereNull('online_since')
                  ->orWhereRaw(
                      'last_location_at >= DATE_SUB(online_since, INTERVAL ? SECOND)',
                      [self::LOCATION_FRESHNESS_GRACE_SECS]
                  )
                  ->orWhere('online_since', '<=', $now->copy()->subMinutes(self::LOCATION_FRESHNESS_MAX_WAIT_MINS));
            })
            ->with(['debts', 'driverLicenses'])
            ->get();

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

            $pickupToPickup = $this->haversineKm(
                (float) $order->pickup_lat, (float) $order->pickup_lng,
                (float) $active->pickup_lat, (float) $active->pickup_lng
            );
            $deliveryToDelivery = $this->haversineKm(
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
        $driverIds   = $afterDetour->pluck('id')->toArray();
        $ratingStats = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('COUNT(*) as rating_count'))
            ->groupBy('delivery_man_id')
            ->pluck('rating_count', 'delivery_man_id');

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
            ->sortByDesc(function (User $d) use ($ratingStats) {
                return $this->compositeScore($d, (int) ($ratingStats[$d->id] ?? 0), $d->_road_km ?? self::MAX_ROAD_DISTANCE_KM);
            })
            ->take(self::MAX_DRIVERS)
            ->values();

        if ($sorted->isNotEmpty()) {
            Log::debug("     [Candidates] Top " . min(5, $sorted->count()) . " tài xế:");
            foreach ($sorted->take(5) as $i => $d) {
                $km    = $d->_road_km !== null ? round($d->_road_km, 2) . 'km' : 'lỗi API';
                $cnt   = (int) ($ratingStats[$d->id] ?? 0);
                $score = round($this->compositeScore($d, $cnt, $d->_road_km ?? self::MAX_ROAD_DISTANCE_KM), 1);
                $wait  = round($this->waitTimeScore($d), 1);
                Log::debug("       " . ($i + 1) . ". #{$d->id} {$d->name} | đường thật: {$km} | điểm={$score} | driver_score=" . ($d->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | so_dg={$cnt} | wait={$wait}");
            }
        }

        return $sorted;
    }

    private function notifyCustomer(Order $order, string $type): void
    {
        $customer = User::find($order->sender_platform_id);
        if (!$customer?->fcm_token) return;

        try {
            $fcm = FCMService::getInstance();
            match ($type) {
                'searching' => $fcm->sendSearchingDriver($customer->fcm_token, $order->code),
                'expanding' => $fcm->sendExpandingSearch($customer->fcm_token, $order->code),
                default     => null,
            };
        } catch (\Throwable $e) {
            Log::error("[Dispatch] notifyCustomer {$type} failed: " . $e->getMessage());
        }
    }

    private function compositeScore(User $driver, int $ratingCount, float $distanceKm = 0.0): float
    {
        $scoreScore     = ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE;
        $ratingCntScore = min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT;
        $waitScore      = $this->waitTimeScore($driver);
        $distScore      = (1 - min($distanceKm, self::MAX_ROAD_DISTANCE_KM) / self::MAX_ROAD_DISTANCE_KM) * self::W_DISTANCE;
        return $scoreScore + $ratingCntScore + $waitScore + $distScore;
    }

    private function waitTimeScore(User $driver): float
    {
        $since = $driver->last_order_completed_at ?? $driver->online_since;
        if (!$since) return 0;
        $waitMins = min(self::WAIT_TIME_CAP_MINS, abs(now()->diffInMinutes(Carbon::parse($since))));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
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
