<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use App\Events\DispatchStateChanged;

/**
 * Gửi offer thật cho 1 tài xế cụ thể (RTDB + OrderDispatchLog + FCM + lên
 * lịch timeout job). Không tự quyết định "thử ứng viên kế" — chỉ trả về
 * true/false, orchestrator (DispatchService::offerToNext()) là nơi duy nhất
 * quyết định cascade sang ứng viên tiếp theo khi send() trả về false.
 */
class DispatchOfferSender
{
    const DRIVER_OFFER_SECS  = 25;   // giây để mở app (trước khi offer_viewed_at set)
    const APP_DECISION_SECS  = 30;   // giây để đọc & quyết định SAU KHI mở app (như ShopeeFood)
    const FCM_TTL_SECS       = 25;

    public function __construct(
        private readonly DispatchScoringCalculator $scoringCalculator,
    ) {}

    /**
     * @return bool true nếu offer đã thật sự tới tay tài xế (ghi RTDB thành
     *              công); false nếu bị skip/lỗi ở bất kỳ bước nào — lock đã
     *              được giải phóng trước khi trả về false trong mọi trường
     *              hợp, để orchestrator có thể thử ứng viên kế ngay.
     */
    public function send(Order $order, User $driver): bool
    {
        // Lock tài xế — chặn 2 dispatch gán cùng lúc
        $lockKey = "dispatch:lock:driver:{$driver->id}";
        if (!Redis::set($lockKey, $order->id, 'NX', 'EX', 60)) {
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đang nhận offer từ dispatch khác");
            return false;
        }

        // Verify lại lần cuối — tài xế có thể nhận đơn giữa lúc pop và lock
        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        if ($activeCount >= 2) {
            Redis::del($lockKey);
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đã có {$activeCount} đơn active");
            return false;
        }

        // Không cần kiểm tra lại trần khoảng cách ở đây — DispatchCandidateFinder::find()
        // đã tính khoảng cách đường thật và lọc ≤ MAX_ROAD_DISTANCE_KM cho MỌI ứng
        // viên trước khi trả về, không chỉ một nhóm nhỏ như cách cũ.
        $now  = now();
        $dist = $driver->_road_km !== null
            ? round($driver->_road_km, 2)
            : round(GoogleMapService::haversineKm(
                (float) ($driver->latitude ?? 0), (float) ($driver->longitude ?? 0),
                (float) $order->pickup_lat, (float) $order->pickup_lng
            ), 2);

        $scoreScore = round($this->scoringCalculator->scoreComponent($driver), 1);
        $waitScore  = round($this->scoringCalculator->waitTimeScore($driver), 1);
        $distScore  = round($this->scoringCalculator->distanceComponent(
            $driver->_road_km ?? DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM,
            DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM
        ), 1);
        $total      = round($scoreScore + $waitScore + $distScore, 1);

        Log::info("│");
        Log::info("└→ [Dispatch] GỬI ĐƠN #{$order->id}");
        Log::info("     Tài xế     : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("     Khoảng cách (đường thật): {$dist} km");
        Log::info("     Điểm tổng  : {$total} = score({$scoreScore}) + wait({$waitScore}) + distance({$distScore})");
        Log::info("     driver_score: " . ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE));
        Log::info("     FCM token  : " . ($driver->fcm_token ? 'có' : 'KHÔNG CÓ'));

        $ok = $this->commitOffer($order, $driver, $now);

        if (!$ok) {
            Redis::del($lockKey);
            Log::warning("│  Skip #{$driver->id} {$driver->name}: ghi RTDB thất bại — chuyển ứng viên kế ngay, không đợi hết hạn oan");
        }

        return $ok;
    }

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
            // — nếu không, DispatchCandidateFinder::find() ở lượt sau vẫn coi
            // driver này là ứng viên hợp lệ và có thể chọn lại chính họ, gây
            // đệ quy vô hạn nếu Firebase sập hẳn và họ là ứng viên duy nhất
            // (đúng lỗi đã gặp và sửa ở Chặn B trước đây). Tái dùng 'expired'
            // vì ENUM chỉ có 4 giá trị cố định — không gọi handleTimeout() nên
            // không phạt điểm/tính bỏ lỡ cho tài xế, đúng tinh thần sửa lỗi này.
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
}
