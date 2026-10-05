<?php

namespace App\Services;

use App\Models\DriverStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;

/**
 * Duyệt, khóa và mở khóa tài khoản tài xế — dùng chung cho bảng danh sách, trang hồ sơ và thao tác hàng loạt,
 * để mọi nơi cùng một luật và cùng ghi lịch sử.
 */
class DriverAccountService
{
    public const STATUS_PENDING = 0;

    public const STATUS_ACTIVE = 1;

    public const STATUS_LOCKED = 2;

    private const DOC_LABELS = ['approved' => 'đã duyệt', 'pending' => 'đang chờ duyệt', 'rejected' => 'bị từ chối'];

    private function latestDocStatus(string $table, int $driverId): ?string
    {
        return DB::table($table)->where('user_id', $driverId)->orderByDesc('id')->value('status');
    }

    /**
     * Có duyệt được không? CCCD chưa được duyệt thì CHẶN; bằng lái chưa được duyệt chỉ CẢNH BÁO.
     *
     * @return array{ok: bool, blockers: string[], warnings: string[]}
     */
    public function approvalCheck(User $driver): array
    {
        $blockers = $warnings = [];

        $cccd = $this->latestDocStatus('driver_cccd_images', $driver->id);
        if ($cccd !== 'approved') {
            $blockers[] = 'CCCD '.(self::DOC_LABELS[$cccd] ?? 'chưa được tải lên');
        }

        $license = $this->latestDocStatus('driver_licenses', $driver->id);
        if ($license !== 'approved') {
            $warnings[] = 'Bằng lái '.(self::DOC_LABELS[$license] ?? 'chưa được tải lên');
        }

        return ['ok' => ! $blockers, 'blockers' => $blockers, 'warnings' => $warnings];
    }

    /** @return array{ok: bool, message: string, warnings: string[]} */
    public function approve(User $driver, ?int $by = null): array
    {
        $check = $this->approvalCheck($driver);
        if (! $check['ok']) {
            return ['ok' => false, 'message' => 'Chưa thể duyệt: '.implode('; ', $check['blockers']), 'warnings' => $check['warnings']];
        }

        // Chỉ duyệt khi vẫn đang chờ duyệt, tránh ghi đè trạng thái nếu người khác vừa khóa/duyệt.
        $changed = User::whereKey($driver->id)->where('user_type', 'driver')->where('status', self::STATUS_PENDING)->update(['status' => self::STATUS_ACTIVE]);
        if (! $changed) {
            return ['ok' => false, 'message' => 'Tài xế không còn ở trạng thái chờ duyệt.', 'warnings' => []];
        }

        $this->log($driver->id, 'approved', null, $by);

        return ['ok' => true, 'message' => 'Đã duyệt tài xế '.$driver->name, 'warnings' => $check['warnings']];
    }

    /**
     * Khóa tài xế. Không khóa khi đang giữ đơn; các đề nghị đơn đang chờ được trả lại để phát cho tài xế khác.
     *
     * @return array{ok: bool, message: string}
     */
    public function lock(User $driver, string $reason, ?int $by = null): array
    {
        $result = DB::transaction(function () use ($driver) {
            $locked = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();

            $activeOrder = Order::where('delivery_man_id', $locked->id)
                ->whereIn('status', ['assigned', 'processing'])
                ->lockForUpdate()
                ->first(['id', 'code']);

            if ($activeOrder) {
                return ['blocked' => false, 'active_order' => $activeOrder];
            }

            $offers = Order::where('dispatching_to_driver_id', $locked->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($offers as $offer) {
                $offer->update(['dispatching_to_driver_id' => null, 'offer_viewed_at' => null]);
                OrderDispatchLog::where('order_id', $offer->id)
                    ->where('driver_id', $locked->id)
                    ->where('result', 'pending')
                    ->update(['result' => 'expired', 'responded_at' => now()]);
            }

            DriverShiftSession::where('driver_id', $locked->id)->whereNull('ended_at')->lockForUpdate()->update(['ended_at' => now()]);

            $locked->update(['status' => self::STATUS_LOCKED, 'is_online' => false, 'online_since' => null, 'fcm_token' => null]);

            return ['blocked' => true, 'offers' => $offers];
        });

        if (! $result['blocked']) {
            return ['ok' => false, 'message' => "Không thể khóa: tài xế đang giữ đơn #{$result['active_order']->code}. Hãy hoàn tất hoặc điều phối đơn trước."];
        }

        $this->log($driver->id, 'locked', $reason, $by);
        $this->afterLock($driver, $result['offers']);

        return ['ok' => true, 'message' => 'Đã khóa tài xế '.$driver->name];
    }

    /** @return array{ok: bool, message: string} */
    public function unlock(User $driver, ?string $reason = null, ?int $by = null): array
    {
        DB::transaction(function () use ($driver) {
            User::whereKey($driver->id)->lockForUpdate()->update(['status' => self::STATUS_ACTIVE, 'is_online' => false, 'online_since' => null]);
        });

        $this->log($driver->id, 'unlocked', $reason, $by);
        $this->afterUnlock($driver);

        return ['ok' => true, 'message' => 'Đã mở khóa tài xế '.$driver->name];
    }

    /** Có được xóa hẳn không? Tài xế đã có đơn, ví hoặc công nợ thì chỉ nên khóa để giữ lịch sử tài chính. */
    public function hasHistory(User $driver): bool
    {
        return Order::where('delivery_man_id', $driver->id)->exists()
            || DB::table('driver_wallets')->where('driver_id', $driver->id)->where('balance', '!=', 0)->exists()
            || DB::table('driver_debts')->where('driver_id', $driver->id)->exists()
            || DB::table('driver_wallet_transactions as t')->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')->where('w.driver_id', $driver->id)->exists();
    }

    private function log(int $driverId, string $action, ?string $reason, ?int $by): void
    {
        DriverStatusLog::create(['driver_id' => $driverId, 'action' => $action, 'reason' => $reason ?: null, 'performed_by' => $by, 'created_at' => now()]);
    }

    /** Dọn phiên đăng nhập, vị trí, khóa điều phối và phát lại các đơn đang đề nghị cho tài xế này. */
    protected function afterLock(User $driver, $offers): void
    {
        $driver->tokens()->delete();
        RTDBService::removeDriverLocation($driver->id);
        RTDBService::setAccountLocked($driver->id, true);
        Redis::del("dispatch:lock:driver:{$driver->id}");

        foreach ($offers as $offer) {
            RTDBService::clearDriverOffer($driver->id, $offer->id);
            app(DispatchService::class)->sendToNextDriver($offer->fresh());
        }
    }

    protected function afterUnlock(User $driver): void
    {
        RTDBService::setAccountLocked($driver->id, false);
    }
}
