<?php
namespace Modules\Order\Services;

use Modules\Driver\Services\DriverLocationService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\DispatchOrderRetryJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use App\Events\DispatchStateChanged;
use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DispatchService
{
    public function __construct(
        private readonly DispatchScoringCalculator $scoringCalculator,
        private readonly DispatchCandidateFinder $candidateFinder,
        private readonly DispatchOfferSender $offerSender,
        private readonly DispatchManualAssignment $manualAssignment,
    ) {}

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
            DriverScoreService::onViewedTimeout($driverId);
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} xem đơn nhưng không nhận → " . DriverScoreService::SCORE_VIEWED_TIMEOUT . " điểm, pop tiếp");
        } elseif (\Modules\Core\Models\City::where('id', $order->city_id)->value('is_rain_mode')) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} không xem đơn lúc trời mưa → miễn tính, pop tiếp");
        } elseif (empty(app(DriverLocationService::class)->freshLocationsFor([$driverId]))) {
            // Backend không có cách nào biết chắc thông báo có thực sự tới
            // máy tài xế hay không (FCM "gửi thành công" chỉ nghĩa là Firebase
            // nhận yêu cầu, không đảm bảo hiển thị được — vd thiếu
            // interruption-level trên iOS, mất mạng, app bị hệ điều hành
            // đóng...). Dùng lại đúng ngưỡng GPS-tươi 10 phút mà dispatch
            // dùng để lọc ứng viên (DriverLocationService::POS_MAX_AGE_SECS)
            // làm tín hiệu gián tiếp: GPS đã chết ngay lúc offer hết hạn thì
            // rất có thể app/kết nối cũng đã chết trước hoặc trong lúc gửi
            // offer — miễn tính thay vì trừ oan. GPS còn tươi mà vẫn không
            // xem thì mới coi là bỏ qua đơn thật.
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} không xem đơn, GPS đã chết lúc hết hạn → miễn tính (không chắc đã nhận được thông báo), pop tiếp");
        } else {
            DriverScoreService::onOfferUnviewed($driverId);
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} không xem đơn → cộng dồn bộ đếm (đủ 3 lần trừ 1 điểm), pop tiếp");
        }

        RTDBService::clearDriverOffer($driverId);

        $this->sendToNextDriver($order->fresh());
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

        Log::info("┌─ [Dispatch] Đơn #{$order->id} | Quét toàn thành phố (≤" . DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM . "km đường thật) | Đã hỏi: " . count($alreadyOffered));

        $candidates = $this->candidateFinder->find($order, $alreadyOffered);

        if ($candidates->isEmpty()) {
            Log::info("└─ [Dispatch] Đơn #{$order->id}: không có ứng viên nào trong " . DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM . "km đường thật → chờ quét lại");
            $this->scheduleRetryOrGiveUp($order);
            return;
        }

        $driver = $candidates->first();
        Log::info("│  Chọn: #{$driver->id} {$driver->name} | " . count($alreadyOffered) . " đã hỏi trước");

        if (!$this->offerSender->send($order, $driver)) {
            $this->sendToNextDriver($order);
        }
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

        // Chống tích lũy RetryJob: chỉ schedule 1 retry tại 1 thời điểm. Thứ
        // tự tham số 'EX', giây, 'NX' — xem chú thích chi tiết ở
        // DispatchOfferSender::send() (thứ tự 'NX','EX',giây trực giác nhưng
        // sai chữ ký thật, khoá không hoạt động).
        if (!Redis::set($this->retryKey($order->id), 1, 'EX', 20, 'NX')) {
            Log::debug("╟── [Dispatch] Đơn #{$order->id}: Retry đã được lên lịch, bỏ qua");
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Chưa có ai → quét lại sau 15s");
        DispatchOrderRetryJob::dispatch($order->id)->delay(now()->addSeconds(15));
    }


    /**
     * Tổng đài gán CỨNG 1 tài xế cụ thể cho đơn, bỏ qua bước offer — xem
     * DispatchManualAssignment::assign() để biết chi tiết điều kiện/hành vi.
     *
     * @return array{success: bool, message: string}
     */
    public function assignDriverDirectly(Order $order, int $driverId): array
    {
        return $this->manualAssignment->assign($order, $driverId);
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

}
