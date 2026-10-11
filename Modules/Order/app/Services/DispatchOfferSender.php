<?php

namespace Modules\Order\Services;

use App\Events\DispatchStateChanged;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\CheckDriverOfferReceiptJob;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

/**
 * Gửi offer thật cho 1 tài xế cụ thể (RTDB + OrderDispatchLog + FCM + lên
 * lịch timeout job). Không tự quyết định "thử ứng viên kế" — chỉ trả về
 * true/false, orchestrator (DispatchService::offerToNext()) là nơi duy nhất
 * quyết định cascade sang ứng viên tiếp theo khi send() trả về false.
 */
class DispatchOfferSender
{
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
        // Lock tài xế — chặn 2 dispatch gán cùng lúc. LƯU Ý thứ tự tham số:
        // chữ ký thật của Illuminate\Redis\Connections\PhpRedisConnection::set()
        // là (key, value, $expireResolution, $expireTTL, $flag) — phải truyền
        // 'EX', giây, 'NX' ĐÚNG THỨ TỰ NÀY. Gọi 'NX','EX',giây (thứ tự trực
        // giác theo cú pháp Redis CLI) bị hiểu sai thành $expireResolution='NX'/
        // $expireTTL='EX'/$flag=giây → build options rác, lock KHÔNG khoá được
        // gì cả (mọi request đều "acquire" thành công, ghi đè, mất luôn TTL) —
        // phát hiện khi tự verify chức năng thật (không phải chỉ đọc code).
        $lockKey = "dispatch:lock:driver:{$driver->id}";
        if (! Redis::set($lockKey, $order->id, 'EX', 60, 'NX')) {
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đang nhận offer từ dispatch khác");

            return false;
        }

        // Verify lại lần cuối — trạng thái tài xế có thể đổi giữa lúc quét
        // ứng viên và lúc lấy được lock (offline, bị khóa, phát sinh nợ hoặc
        // bắt đầu nghỉ phép). Không gửi offer rồi chờ timeout oan.
        $freshDriver = User::find($driver->id);
        $unavailable = ! $freshDriver
            || (int) $freshDriver->status !== 1
            || ! $freshDriver->is_online
            || ($freshDriver->score_suspended_until && $freshDriver->score_suspended_until > now())
            || $freshDriver->debts()->where('status', 'overdue')->exists()
            || DB::table('driver_leave_requests')->where('driver_id', $driver->id)->whereDate('leave_date', today())->exists();
        if ($unavailable) {
            Redis::del($lockKey);
            Log::debug("│  Skip #{$driver->id} {$driver->name}: trạng thái vừa đổi, không còn đủ điều kiện");

            return false;
        }

        // Verify lại đơn active sau khi đã lấy lock tài xế.
        $activeOrders = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing'])
            ->get();
        $activeCount = $activeOrders->count();
        if ($activeCount > 0) {
            Redis::del($lockKey);
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đã có đơn active");

            return false;
        }
        // Không cần kiểm tra lại trần khoảng cách ở đây — DispatchCandidateFinder::find()
        // đã tính khoảng cách đường thật và lọc theo trần cấu hình cho MỌI ứng
        // viên trước khi trả về, không chỉ một nhóm nhỏ như cách cũ.
        $now = now();
        $dist = $driver->_road_km !== null
            ? round($driver->_road_km, 2)
            : round(GoogleMapService::haversineKm(
                (float) ($driver->latitude ?? 0), (float) ($driver->longitude ?? 0),
                (float) $order->pickup_lat, (float) $order->pickup_lng
            ), 2);

        $scoreScore = round($this->scoringCalculator->scoreComponent($driver, $order->city_id), 1);
        $waitScore = round($this->scoringCalculator->waitTimeScore($driver, $order->city_id), 1);
        $distanceCap = (float) ($driver->_distance_cap_km ?? DispatchRadiusPolicy::radiusForElapsedSeconds(0, $order->city_id));
        $distScore = round($this->scoringCalculator->distanceComponent(
            $driver->_road_km ?? $distanceCap,
            $distanceCap,
            $order->city_id,
        ), 1);
        $total = round($scoreScore + $waitScore + $distScore, 1);

        Log::info('│');
        Log::info("└→ [Dispatch] GỬI ĐƠN #{$order->id}");
        Log::info("     Tài xế     : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("     Khoảng cách (đường thật): {$dist} km");
        Log::info("     Điểm tổng  : {$total} = score({$scoreScore}) + wait({$waitScore}) + distance({$distScore})");
        Log::info('     driver_score: '.($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE));
        Log::info('     FCM token  : '.($driver->fcm_token ? 'có' : 'KHÔNG CÓ'));

        $ok = $this->commitOffer($order, $driver, $now);

        if (! $ok) {
            Redis::del($lockKey);
            Log::warning("│  Skip #{$driver->id} {$driver->name}: ghi RTDB thất bại — chuyển ứng viên kế ngay, không đợi hết hạn oan");
        }

        return $ok;
    }

    private function commitOffer(Order $order, User $driver, Carbon $now): bool
    {
        $offerSeconds = OperationalSettings::offerOpenSeconds($order->city_id);
        $expiresAt = $now->copy()->addSeconds($offerSeconds);

        // Khoá dòng đơn để nhiều tài xế trong cùng một wave có thể cùng giữ
        // offer, nhưng không vượt quá kích thước wave và không lọt offer ma
        // sau khi đơn đã được nhận/hủy.
        $dispatchLog = DB::transaction(function () use ($order, $driver, $now, $expiresAt) {
            // Cùng thứ tự lock với accept/gán tay/reconciliation:
            // tài xế → đơn → dispatch logs, tránh deadlock khi app vừa sync
            // đúng lúc backend đang phát offer mới.
            User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending') {
                return null;
            }

            $pending = OrderDispatchLog::where('order_id', $order->id)
                ->where('result', 'pending')->lockForUpdate()->get();
            if ($pending->count() >= OperationalSettings::dispatchWaveSize($order->city_id)
                || $pending->contains('driver_id', $driver->id)) {
                return null;
            }

            $fresh->forceFill([
                // Cột cũ chỉ còn là con trỏ tương thích cho màn admin. Quyền
                // nhận đơn được xác thực bằng log pending của từng tài xế.
                'dispatching_to_driver_id' => $fresh->dispatching_to_driver_id ?? $driver->id,
                'dispatch_attempts' => (int) $fresh->dispatch_attempts + 1,
                'offer_viewed_at' => null,
                'updated_at' => $now,
            ])->save();

            return OrderDispatchLog::create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'offered_at' => $now,
                'expires_at' => $expiresAt,
                'result' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if (! $dispatchLog) {
            Log::debug("│  Skip #{$driver->id}: đơn #{$order->id} không còn trống để giữ offer");

            return false;
        }

        $payload = DispatchOfferPayload::make($order, $dispatchLog);
        $rtdbOk = RTDBService::writeDriverOffer($driver->id, $payload);

        if (! $rtdbOk) {
            // Giữ log expired để không chọn lại ngay
            // cùng tài xế và không tính lỗi hạ tầng là bỏ lỡ đơn.
            DB::transaction(function () use ($order, $driver, $dispatchLog) {
                $failedAt = now();
                OrderDispatchLog::where('id', $dispatchLog->id)
                    ->where('result', 'pending')
                    ->update([
                        'result' => 'expired',
                        'responded_at' => $failedAt,
                        'close_reason' => OrderDispatchLog::CLOSE_DELIVERY_FAILED,
                        'updated_at' => $failedAt,
                    ]);
                $replacement = OrderDispatchLog::where('order_id', $order->id)
                    ->where('result', 'pending')->value('driver_id');
                DB::table('orders')->where('id', $order->id)->where('status', 'pending')
                    ->update(['dispatching_to_driver_id' => $replacement, 'updated_at' => $failedAt]);
            });

            return false;
        }

        Log::debug("     → RTDB offer ghi thành công (expires_at: {$expiresAt->timestamp})");

        $order->offer_viewed_at = null;

        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverWakeUp(
                    $driver->fcm_token,
                    $order->id,
                    $order->code,
                    $order->pickup_address ?? '',
                    $expiresAt->timestamp,
                    $payload['receipt_url'],
                    $payload['view_url'],
                );
                Log::debug('     → FCM wake-up gửi thành công');
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: ".$e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay($expiresAt);
        CheckDriverOfferReceiptJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(OperationalSettings::offerReceiptSeconds($order->city_id)));

        broadcast(new DispatchStateChanged);

        return true;
    }
}
