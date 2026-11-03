<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;

/** Xử lý yêu cầu rút tiền của tài xế: duyệt (PayOS hoặc chuyển thủ công), từ chối và hoàn tiền, báo tài xế. */
class WithdrawService
{
    public const REJECT_REASONS = [
        'below_min' => 'Dưới mức rút tối thiểu',
        'bad_bank' => 'Sai thông tin tài khoản nhận',
        'suspicious' => 'Tài khoản có dấu hiệu bất thường',
        'other' => 'Lý do khác',
    ];

    /** Yêu cầu chờ quá bấy nhiêu giờ thì bị coi là quá hạn. */
    public const STALE_HOURS = 24;

    public static function payosConfigured(): bool
    {
        return (bool) (config('services.payos_payout.client_id') && config('services.payos_payout.api_key') && config('services.payos_payout.checksum_key'));
    }

    /** Số dư tài khoản chi PayOS; cache ngắn để mở trang không gọi API liên tục. Null nếu chưa cấu hình hoặc lỗi. */
    public static function payosBalance(): ?int
    {
        if (! self::payosConfigured()) {
            return null;
        }

        $v = Cache::remember('payos_payout_balance', 120, function () {
            $b = PayOSPayoutService::getBalance();

            return isset($b['balance']) ? (int) $b['balance'] : (isset($b['availableBalance']) ? (int) $b['availableBalance'] : -1);
        });

        return $v >= 0 ? $v : null;
    }

    /** Tên chủ tài khoản có khớp tên tài xế không (bỏ dấu, không phân biệt hoa thường/khoảng trắng). */
    public static function nameMatches(?string $account, ?string $driver): bool
    {
        $norm = fn (?string $s) => preg_replace('/[^a-z]/', '', strtolower(Str::ascii((string) $s)));
        $a = $norm($account);
        $d = $norm($driver);

        return $a !== '' && $d !== '' && ($a === $d || str_contains($a, $d) || str_contains($d, $a));
    }

    /** Lệnh PayOS chưa có kết quả cuối: không được từ chối (hoàn tiền) hay chuyển theo cách khác, vì PayOS có thể đã chuyển. */
    public const PAYOS_IN_FLIGHT = ['sending', 'unknown', 'processing'];

    private static function lock(WithdrawRequest $r)
    {
        return Cache::lock("withdraw:payout:{$r->id}", 300);
    }

    public static function payosInFlight(WithdrawRequest $r): bool
    {
        return $r->status === 'pending' && in_array($r->payout_state, self::PAYOS_IN_FLIGHT, true);
    }

    private static function inFlightMessage(): string
    {
        return 'Yêu cầu đang có lệnh PayOS chưa rõ kết quả (PayOS có thể đã chuyển tiền). Bấm "Kiểm tra PayOS" trước khi xử lý cách khác.';
    }

    /**
     * Mã tham chiếu cho lần gọi PayOS: lệnh còn dở thì dùng lại đúng mã cũ (PayOS chống lặp theo mã, gọi lại không
     * chuyển hai lần); lần trước PayOS đã từ chối thì dùng mã mới WD{id}-2, -3...
     */
    private static function payosReference(WithdrawRequest $r): string
    {
        $base = 'WD'.$r->id;
        $current = (string) $r->payout_reference;
        if (! str_starts_with($current, $base)) {
            return $base;
        }
        if ($r->payout_state !== 'failed') {
            return $current;
        }
        $n = $current === $base ? 1 : (int) substr($current, strlen($base) + 1);

        return $base.'-'.($n + 1);
    }

    /** @return array{ok:bool,message:string,processing?:bool} */
    public static function approvePayos(WithdrawRequest $record, ?string $note, ?int $by): array
    {
        $lock = self::lock($record);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Yêu cầu đang được xử lý ở một phiên khác.'];
        }

        try {
            $fresh = WithdrawRequest::find($record->id);
            if (! $fresh || $fresh->status !== 'pending') {
                return ['ok' => false, 'message' => 'Yêu cầu này đã được xử lý.'];
            }
            if ($fresh->payout_state === 'processing') {
                return ['ok' => false, 'message' => 'PayOS đã nhận lệnh và đang chờ ngân hàng. Bấm "Kiểm tra PayOS" để cập nhật.'];
            }
            if ((int) $fresh->driver?->status !== 1) {
                return ['ok' => false, 'message' => 'Không thể duyệt: tài khoản tài xế đang tạm ngưng.'];
            }
            if (! $fresh->bank_code || ! $fresh->account_number) {
                return ['ok' => false, 'message' => 'Yêu cầu cũ chưa có bản lưu tài khoản ngân hàng. Hãy chuyển thủ công.'];
            }
            if (! self::payosConfigured()) {
                return ['ok' => false, 'message' => 'PayOS chưa được cấu hình. Hãy chuyển thủ công rồi nhập mã giao dịch.'];
            }

            // Đánh dấu "đang gửi" TRƯỚC khi gọi ra ngoài: process chết sau khi PayOS nhận lệnh thì yêu cầu vẫn bị khóa
            // khỏi Từ chối / chuyển tay cho tới khi kiểm tra lại PayOS.
            $refId = self::payosReference($fresh);
            // Ghi người bấm duyệt ngay lúc gửi: nếu PayOS xử lý xong sau (lịch chạy tự cập nhật) vẫn biết ai đã duyệt.
            $fresh->update(['payout_state' => 'sending', 'payout_reference' => $refId, 'payout_method' => 'payos', 'last_payout_attempt_at' => now(), 'processed_by' => $by]);

            $result = PayOSPayoutService::createPayout(
                referenceId: $refId,
                amount: (int) round($fresh->amount),
                // Nội dung chuyển khoản không dấu: nhiều ngân hàng không nhận ký tự có dấu.
                description: 'Rut tien TX '.Str::ascii((string) ($fresh->driver?->name ?? $fresh->driver_id)),
                bankCode: $fresh->bank_code,
                accountNumber: $fresh->account_number,
            );

            if (! $result['success']) {
                $ambiguous = (bool) ($result['ambiguous'] ?? false);
                $fresh->update(['payout_state' => $ambiguous ? 'unknown' : 'failed', 'last_payout_error' => $result['message']]);

                return ['ok' => false, 'message' => $ambiguous
                    ? 'Không rõ PayOS đã nhận lệnh chưa ('.$result['message'].'). KHÔNG chuyển tay hay từ chối; bấm "Kiểm tra PayOS" sau ít phút.'
                    : 'Chuyển khoản thất bại: '.$result['message']];
            }

            return self::applyPayosState($fresh, strtoupper((string) ($result['data']['approvalState'] ?? 'PROCESSING')), $result['data']['id'] ?? null, $note, $by);
        } finally {
            $lock->release();
        }
    }

    /** Kết quả PayOS cho một lệnh đã tạo: xong thì duyệt, đang xử lý thì chờ, trạng thái khác coi như không thành công. */
    private static function applyPayosState(WithdrawRequest $r, string $state, $payoutId, ?string $note, ?int $by): array
    {
        $payoutId = $payoutId !== null ? (string) $payoutId : $r->payout_id;
        if ($state === 'SUCCEEDED') {
            return self::markApproved($r, 'payos', (string) $r->payout_reference, $note, $by, $payoutId);
        }
        if ($state === 'PROCESSING') {
            $r->update(['payout_state' => 'processing', 'payout_id' => $payoutId, 'last_payout_error' => null]);

            return ['ok' => true, 'processing' => true, 'message' => 'PayOS đã nhận lệnh, đang chờ ngân hàng xác nhận. Hệ thống tự kiểm tra mỗi 5 phút và báo tài xế khi tiền đã chuyển.'];
        }
        $r->update(['payout_state' => 'failed', 'payout_id' => $payoutId, 'last_payout_error' => 'PayOS báo trạng thái '.$state]);

        return ['ok' => false, 'message' => 'PayOS báo lệnh chi không thành công (trạng thái '.$state.'). Có thể thử lại hoặc xử lý cách khác.'];
    }

    /** Hỏi lại PayOS kết quả của lệnh còn dở (nút "Kiểm tra PayOS" và lịch chạy 5 phút). */
    public static function syncPayos(WithdrawRequest $record, ?int $by = null): array
    {
        $lock = self::lock($record);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Yêu cầu đang được xử lý ở một phiên khác.'];
        }

        try {
            $fresh = WithdrawRequest::find($record->id);
            if (! $fresh || ! self::payosInFlight($fresh)) {
                return ['ok' => false, 'message' => 'Yêu cầu không có lệnh PayOS nào đang chờ.'];
            }
            $found = PayOSPayoutService::findByReference((string) $fresh->payout_reference);
            if ($found === null) {
                return ['ok' => false, 'message' => 'Chưa hỏi được PayOS, thử lại sau.'];
            }
            if (! $found['found']) {
                // PayOS chắc chắn không có lệnh này: tiền chưa đi, được thử lại hoặc xử lý cách khác.
                $fresh->update(['payout_state' => 'failed', 'last_payout_error' => 'PayOS không có lệnh '.$fresh->payout_reference.' (chưa được tạo)']);

                return ['ok' => true, 'message' => 'PayOS không có lệnh này: tiền chưa được chuyển. Có thể chuyển lại hoặc từ chối.'];
            }

            return self::applyPayosState($fresh, (string) ($found['state'] ?? 'PROCESSING'), $found['id'] ?? null, null, $by ?? $fresh->processed_by);
        } finally {
            $lock->release();
        }
    }

    /** Admin đã tự chuyển khoản ngoài hệ thống: chỉ ghi nhận, không gọi PayOS. */
    public static function approveManual(WithdrawRequest $record, string $txRef, ?string $note, ?int $by): array
    {
        $txRef = trim($txRef);
        if (mb_strlen($txRef) < 4) {
            return ['ok' => false, 'message' => 'Hãy nhập mã giao dịch ngân hàng (ít nhất 4 ký tự).'];
        }

        $lock = self::lock($record);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Yêu cầu đang được xử lý ở một phiên khác.'];
        }

        try {
            $fresh = WithdrawRequest::find($record->id);
            if (! $fresh || $fresh->status !== 'pending') {
                return ['ok' => false, 'message' => 'Yêu cầu này đã được xử lý.'];
            }
            if (self::payosInFlight($fresh)) {
                return ['ok' => false, 'message' => self::inFlightMessage()];
            }
            if (WithdrawRequest::where('payout_reference', $txRef)->whereKeyNot($fresh->id)->exists()) {
                return ['ok' => false, 'message' => 'Mã giao dịch này đã dùng cho một yêu cầu khác.'];
            }

            return self::markApproved($fresh, 'manual', $txRef, $note, $by);
        } finally {
            $lock->release();
        }
    }

    /**
     * Đã trả tài xế bằng tiền mặt (hoặc cách khác không có mã giao dịch). Trước đây không có lựa chọn này nên admin
     * phải Từ chối (hoàn tiền vào ví) rồi trừ ví tay: báo cáo ghi sai là bị từ chối và dễ lệch tiền.
     */
    public static function approveCash(WithdrawRequest $record, string $note, ?int $by): array
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            return ['ok' => false, 'message' => 'Hãy ghi rõ ai trả, trả lúc nào (ít nhất 5 ký tự).'];
        }

        $lock = self::lock($record);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Yêu cầu đang được xử lý ở một phiên khác.'];
        }

        try {
            $fresh = WithdrawRequest::find($record->id);
            if (! $fresh || $fresh->status !== 'pending') {
                return ['ok' => false, 'message' => 'Yêu cầu này đã được xử lý.'];
            }
            if (self::payosInFlight($fresh)) {
                return ['ok' => false, 'message' => self::inFlightMessage()];
            }

            return self::markApproved($fresh, 'cash', 'CASH-'.$fresh->id, $note, $by);
        } finally {
            $lock->release();
        }
    }

    private static function markApproved(WithdrawRequest $r, string $method, string $ref, ?string $note, ?int $by, ?string $payoutId = null): array
    {
        $ok = WithdrawRequest::whereKey($r->id)->where('status', 'pending')->update([
            'status' => 'approved',
            'admin_note' => $note,
            'payout_reference' => $ref,
            'payout_method' => $method,
            'payout_state' => $method === 'payos' ? 'succeeded' : null,
            'payout_id' => $payoutId,
            'last_payout_error' => null,
            'processed_by' => $by,
            'processed_at' => now(),
        ]) > 0;

        if (! $ok) {
            return ['ok' => false, 'message' => 'Giao dịch đã được phiên khác cập nhật.'];
        }

        $money = number_format($r->amount, 0, ',', '.').'đ';
        self::notify($r->driver_id, 'Yêu cầu rút tiền đã được duyệt', $method === 'cash'
            ? 'Yêu cầu rút '.$money.' đã được thanh toán trực tiếp cho bạn.'
            : 'Bạn được chuyển '.$money.' về tài khoản ngân hàng.', $r->id);

        return ['ok' => true, 'message' => match ($method) {
            'payos' => 'Đã duyệt: PayOS báo tiền đã chuyển thành công.',
            'cash' => 'Đã ghi nhận đã trả tiền mặt cho tài xế.',
            default => 'Đã ghi nhận chuyển khoản thủ công.',
        }];
    }

    /** Từ chối và hoàn tiền đang giữ vào ví. $reason là khoá trong REJECT_REASONS. */
    public static function reject(WithdrawRequest $record, string $reason, ?string $note, ?int $by): array
    {
        $label = self::REJECT_REASONS[$reason] ?? null;
        $note = trim((string) $note);
        if (! $label) {
            return ['ok' => false, 'message' => 'Hãy chọn lý do từ chối.'];
        }
        if ($reason === 'other' && mb_strlen($note) < 5) {
            return ['ok' => false, 'message' => 'Với lý do khác, hãy ghi rõ lý do (ít nhất 5 ký tự).'];
        }
        $text = $note !== '' ? $label.': '.$note : $label;

        // Cùng khoá với luồng duyệt: tránh vừa chuyển khoản vừa hoàn tiền vào ví.
        $lock = self::lock($record);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Yêu cầu đang được xử lý ở một phiên khác.'];
        }

        try {
            $done = DB::transaction(function () use ($record, $reason, $text, $by) {
                $locked = WithdrawRequest::where('id', $record->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    return false;
                }
                if (self::payosInFlight($locked)) {
                    return 'in_flight';
                }

                DriverWalletService::adjust($locked->driver_id, $locked->amount, 'credit', 'Hoàn tiền yêu cầu rút #'.$locked->id, 'withdraw_refund_'.$locked->id, false, $by);
                $locked->update(['status' => 'rejected', 'reject_reason' => $reason, 'admin_note' => $text, 'processed_by' => $by, 'processed_at' => now()]);

                return true;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi: '.$e->getMessage()];
        } finally {
            $lock->release();
        }

        if ($done === 'in_flight') {
            return ['ok' => false, 'message' => self::inFlightMessage()];
        }
        if (! $done) {
            return ['ok' => false, 'message' => 'Yêu cầu đã được xử lý, không hoàn tiền lại.'];
        }

        self::notify($record->driver_id, 'Yêu cầu rút tiền bị từ chối', $text.'. Số tiền '.number_format($record->amount, 0, ',', '.').'đ đã được hoàn lại vào ví.', $record->id);

        return ['ok' => true, 'message' => 'Đã từ chối và hoàn tiền cho tài xế.'];
    }

    private static function notify(int $driverId, string $title, string $body, int $requestId): void
    {
        try {
            $token = User::whereKey($driverId)->value('fcm_token');
            if ($token) {
                FCMService::getInstance()->sendDriverNotice($token, $title, $body, ['type' => 'withdraw_processed', 'request_id' => (string) $requestId]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Withdraw] notify failed', ['driver_id' => $driverId, 'error' => $e->getMessage()]);
        }
    }

    // ─── Số liệu ────────────────────────────────────────────────────────────────

    private static function scoped(?int $cityId)
    {
        // Không có khu vực thì không có yêu cầu nào, không bao giờ là "mọi khu vực".
        return DB::table('withdraw_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
            ->where('u.city_id', (int) $cityId);
    }

    public static function summary(?int $cityId): array
    {
        $pending = self::scoped($cityId)->where('r.status', 'pending')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(r.amount),0) s, MIN(r.created_at) oldest')->first();

        $since = now()->subDays(30);
        $done = self::scoped($cityId)->where('r.processed_at', '>=', $since)
            ->selectRaw("SUM(r.status='approved') ac, COALESCE(SUM(CASE WHEN r.status='approved' THEN r.amount END),0) asum, SUM(r.status='rejected') rc")->first();

        $stale = self::scoped($cityId)->where('r.status', 'pending')->where('r.created_at', '<', now()->subHours(self::STALE_HOURS))->count();
        $processed = (int) $done->ac + (int) $done->rc;

        return [
            'pendingCount' => (int) $pending->c,
            'pendingMoney' => (int) $pending->s,
            'oldestHours' => $pending->oldest ? (int) \Carbon\Carbon::parse($pending->oldest)->diffInHours(now()) : 0,
            'stale' => $stale,
            'paidCount' => (int) $done->ac,
            'paidMoney' => (int) $done->asum,
            'rejectedCount' => (int) $done->rc,
            'rejectRate' => $processed ? round($done->rc / $processed * 100) : 0,
            'payos' => self::payosConfigured(),
            'payosBalance' => self::payosBalance(),
            'inFlight' => self::scoped($cityId)->where('r.status', 'pending')->whereIn('r.payout_state', self::PAYOS_IN_FLIGHT)->count(),
            // Đã chi 30 ngày theo cách trả (yêu cầu cũ chưa ghi cách trả tính là "khác").
            'byMethod' => self::scoped($cityId)->where('r.status', 'approved')->where('r.processed_at', '>=', $since)
                ->selectRaw("COALESCE(r.payout_method, 'other') m, COUNT(*) c, COALESCE(SUM(r.amount),0) s")->groupBy('m')->get()
                ->mapWithKeys(fn ($x) => [$x->m => ['count' => (int) $x->c, 'money' => (int) $x->s]])->all(),
        ];
    }
}
