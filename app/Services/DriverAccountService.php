<?php

namespace App\Services;

use App\Models\DriverStatusLog;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Order\Models\Order;

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
     * Duyệt bước đầu chỉ cho tài xế vào được app; KYC (CCCD, bằng lái) làm sau. CCCD chưa được duyệt chỉ CẢNH BÁO —
     * việc chặn nhận đơn nằm ở bước bật online (DriverController::toggleOnline).
     *
     * @return array{ok: bool, blockers: string[], warnings: string[]}
     */
    public function approvalCheck(User $driver): array
    {
        $blockers = $warnings = [];

        $cccd = $this->latestDocStatus('driver_cccd_images', $driver->id);
        if ($cccd !== 'approved') {
            $warnings[] = 'CCCD '.(self::DOC_LABELS[$cccd] ?? 'chưa được tải lên');
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
        self::notify($driver->id, 'Tài khoản đã được duyệt', 'Bạn đã có thể đăng nhập FlashShip Driver. Bạn cần được duyệt CCCD để bật online nhận đơn.', 'account_approved');

        return ['ok' => true, 'message' => 'Đã duyệt tài xế '.$driver->name, 'warnings' => $check['warnings']];
    }

    /**
     * Khóa tài xế. Không khóa khi đang giữ đơn.
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

            DriverShiftSession::where('driver_id', $locked->id)->whereNull('ended_at')->lockForUpdate()->update(['ended_at' => now()]);

            $locked->update(['status' => self::STATUS_LOCKED, 'is_online' => false, 'online_since' => null, 'fcm_token' => null]);

            return ['blocked' => true];
        });

        if (! $result['blocked']) {
            return ['ok' => false, 'message' => "Không thể khóa: tài xế đang giữ đơn #{$result['active_order']->code}. Hãy hoàn tất hoặc điều phối đơn trước."];
        }

        $this->log($driver->id, 'locked', $reason, $by);
        $this->afterLock($driver);

        return ['ok' => true, 'message' => 'Đã khóa tài xế '.$driver->name];
    }

    /** @return array{ok: bool, message: string} */
    public function unlock(User $driver, ?string $reason = null, ?int $by = null): array
    {
        // Chỉ mở tài khoản đang bị khóa: hồ sơ chờ duyệt không được "mở khóa" thành hoạt động mà bỏ qua bước duyệt.
        $changed = User::whereKey($driver->id)->where('user_type', 'driver')->where('status', self::STATUS_LOCKED)
            ->update(['status' => self::STATUS_ACTIVE, 'is_online' => false, 'online_since' => null]);
        if (! $changed) {
            return ['ok' => false, 'message' => 'Tài xế không còn ở trạng thái bị khóa.'];
        }

        $this->log($driver->id, 'unlocked', $reason, $by);
        $this->afterUnlock($driver);

        return ['ok' => true, 'message' => 'Đã mở khóa tài xế '.$driver->name];
    }

    /**
     * Lý do chưa xóa được tài xế (rỗng = xóa được). Xóa tài xế đã có lịch sử đơn vẫn được (quyết định 24/10), nhưng
     * không được xóa khi còn tiền hoặc nghĩa vụ chưa xong: ví, công nợ, yêu cầu rút tiền và các giao dịch của chúng bị
     * xóa theo, mất dấu vết công ty còn nợ tài xế bao nhiêu hay tài xế còn nợ công ty bao nhiêu. Cùng luật với khi tài xế
     * tự xin xóa tài khoản trên app.
     *
     * @return string[]
     */
    public function deleteBlockers(User $driver): array
    {
        $blockers = [];
        $busy = Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->first(['id', 'code']);
        if ($busy) {
            $blockers[] = 'đang giữ đơn #'.($busy->code ?: $busy->id);
        }
        $balance = (float) DB::table('driver_wallets')->where('driver_id', $driver->id)->value('balance');
        if ($balance != 0.0) {
            $blockers[] = 'ví còn '.number_format($balance, 0, ',', '.').'₫';
        }
        $debt = (float) DB::table('driver_debts')->where('driver_id', $driver->id)->where('status', '<>', 'paid')
            ->whereColumn('amount_due', '>', 'amount_paid')->sum(DB::raw('amount_due - amount_paid'));
        if ($debt > 0) {
            $blockers[] = 'còn nợ '.number_format($debt, 0, ',', '.').'₫';
        }
        $withdraw = (float) DB::table('withdraw_requests')->where('driver_id', $driver->id)->where('status', 'pending')->sum('amount');
        if ($withdraw > 0) {
            $blockers[] = 'có yêu cầu rút '.number_format($withdraw, 0, ',', '.').'₫ chờ chuyển tiền';
        }

        return $blockers;
    }

    /**
     * Ghi lịch sử các thay đổi nhạy cảm trong form sửa tài xế, và gỡ ca của khu vực cũ khi đổi khu vực
     * (ca không đi theo tài xế; để lại thì app báo "đã đăng ký ca" nên tài xế không tự chọn được ca ở khu vực mới).
     *
     * @param  array{city_id:?int, phone:?string, debt_enabled:bool}  $before
     */
    public function recordProfileChanges(User $driver, array $before, ?int $by): void
    {
        if ((int) $before['city_id'] !== (int) $driver->city_id) {
            $foreign = $driver->registeredShifts()->where('shifts.city_id', '!=', (int) $driver->city_id)->pluck('shifts.name', 'shifts.id');
            if ($foreign->isNotEmpty()) {
                $driver->registeredShifts()->detach($foreign->keys()->all());
            }
            $from = DB::table('cities')->where('id', $before['city_id'])->value('name') ?? '—';
            $to = DB::table('cities')->where('id', $driver->city_id)->value('name') ?? '—';
            $this->log($driver->id, 'city_change', $from.' → '.$to.($foreign->isNotEmpty() ? '. Gỡ ca: '.$foreign->implode(', ') : ''), $by);
        }
        if ((string) $before['phone'] !== (string) $driver->phone) {
            $this->log($driver->id, 'phone_change', $before['phone'].' → '.$driver->phone, $by);
        }
        if ((bool) $before['debt_enabled'] !== (bool) $driver->debt_enabled) {
            $this->log($driver->id, $driver->debt_enabled ? 'debt_on' : 'debt_off', null, $by);
        }
    }

    /** Báo tài xế kết quả xét duyệt CCCD / bằng lái. */
    public static function notifyDocumentReviewed(int $driverId, string $document, string $status, ?string $reason): void
    {
        $approved = $status === 'approved';
        self::notify(
            $driverId,
            $approved ? 'Đã xác minh '.$document : ucfirst($document).' chưa được duyệt',
            $approved ? 'Ảnh '.$document.' của bạn đã được duyệt.' : 'Lý do: '.($reason ?: 'không hợp lệ').'. Vui lòng tải lại ảnh trong mục Xác minh.',
            'kyc_reviewed'
        );
    }

    private static function notify(int $driverId, string $title, string $body, string $type): void
    {
        $token = DB::table('users')->where('id', $driverId)->value('fcm_token');
        if (! $token) {
            return;
        }
        try {
            \Modules\Core\Services\FCMService::getInstance()->sendDriverNotice($token, $title, $body, ['type' => $type]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[DriverAccount] notify failed', ['driver' => $driverId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Báo admin (chuông thông báo trong trang quản trị) khi tài xế yêu cầu xoá tài khoản — trước đây yêu cầu chỉ
     * được ghi vào DB, không trang nào hiển thị nên không ai xử lý.
     */
    public function notifyAdminsDeleteRequest(User $driver): void
    {
        try {
            $city = $driver->city;
            $admins = User::whereIn('user_type', \App\Support\AdminAccess::adminTypes())->where('status', 1)->get()
                ->filter(fn (User $u) => \App\Support\AdminAccess::allows($u, \App\Support\AdminAccess::OPERATIONS_MANAGE)
                    && (! $city || $u->canAccessTenant($city)));
            if ($admins->isEmpty()) {
                return;
            }
            $url = $city ? \App\Filament\Resources\DriverResource::getUrl('view', ['record' => $driver->id], tenant: $city) : null;
            \Filament\Notifications\Notification::make()
                ->title('Yêu cầu xoá tài khoản: '.$driver->name)
                ->body(($driver->phone ?? '').($city ? ' · '.$city->name : '').'. Kiểm tra và xoá hoặc liên hệ tài xế.')
                ->icon('heroicon-o-user-minus')->warning()
                ->actions($url ? [\Filament\Notifications\Actions\Action::make('view')->label('Mở hồ sơ')->url($url)] : [])
                ->sendToDatabase($admins);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[DriverAccount] notify delete request failed', ['driver' => $driver->id, 'error' => $e->getMessage()]);
        }
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

    /** Dọn phiên đăng nhập và vị trí của tài xế vừa bị khóa. */
    protected function afterLock(User $driver): void
    {
        $driver->tokens()->delete();
        RTDBService::removeDriverLocation($driver->id);
        RTDBService::setAccountLocked($driver->id, true);
        RTDBService::revokeDriverSessions($driver->id);
    }

    protected function afterUnlock(User $driver): void
    {
        RTDBService::setAccountLocked($driver->id, false);
    }
}
