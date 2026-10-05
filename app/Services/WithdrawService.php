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

    private static function lock(WithdrawRequest $r)
    {
        return Cache::lock("withdraw:payout:{$r->id}", 300);
    }

    /** @return array{ok:bool,message:string} */
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
            if ((int) $fresh->driver?->status !== 1) {
                return ['ok' => false, 'message' => 'Không thể duyệt: tài khoản tài xế đang tạm ngưng.'];
            }
            if (! $fresh->bank_code || ! $fresh->account_number) {
                return ['ok' => false, 'message' => 'Yêu cầu cũ chưa có bản lưu tài khoản ngân hàng. Hãy chuyển thủ công.'];
            }
            if (! self::payosConfigured()) {
                return ['ok' => false, 'message' => 'PayOS chưa được cấu hình. Hãy chuyển thủ công rồi nhập mã giao dịch.'];
            }

            // Không ghi approved trước khi gọi ra ngoài. Nếu process chết sau khi PayOS nhận lệnh,
            // lần thử lại dùng cùng referenceId và PayOS trả cùng giao dịch.
            $refId = 'WD'.$fresh->id;
            $result = PayOSPayoutService::createPayout(
                referenceId: $refId,
                amount: (int) $fresh->amount,
                description: 'Rut tien TX '.($fresh->driver?->name ?? $fresh->driver_id),
                bankCode: $fresh->bank_code,
                accountNumber: $fresh->account_number,
            );

            if (! $result['success']) {
                $fresh->update(['last_payout_error' => $result['message'], 'last_payout_attempt_at' => now()]);

                return ['ok' => false, 'message' => 'Chuyển khoản thất bại: '.$result['message']];
            }

            return self::markApproved($fresh, 'payos', $refId, $note, $by);
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
            if (WithdrawRequest::where('payout_reference', $txRef)->whereKeyNot($fresh->id)->exists()) {
                return ['ok' => false, 'message' => 'Mã giao dịch này đã dùng cho một yêu cầu khác.'];
            }

            return self::markApproved($fresh, 'manual', $txRef, $note, $by);
        } finally {
            $lock->release();
        }
    }

    private static function markApproved(WithdrawRequest $r, string $method, string $ref, ?string $note, ?int $by): array
    {
        $ok = WithdrawRequest::whereKey($r->id)->where('status', 'pending')->update([
            'status' => 'approved',
            'admin_note' => $note,
            'payout_reference' => $ref,
            'payout_method' => $method,
            'last_payout_error' => null,
            'processed_by' => $by,
            'processed_at' => now(),
        ]) > 0;

        if (! $ok) {
            return ['ok' => false, 'message' => 'Giao dịch đã được phiên khác cập nhật.'];
        }

        self::notify($r->driver_id, 'Yêu cầu rút tiền đã được duyệt', 'Bạn được chuyển '.number_format($r->amount, 0, ',', '.').'đ về tài khoản ngân hàng.', $r->id);

        return ['ok' => true, 'message' => $method === 'payos' ? 'Đã duyệt và chuyển khoản thành công.' : 'Đã ghi nhận chuyển khoản thủ công.'];
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

                DriverWalletService::adjust($locked->driver_id, $locked->amount, 'credit', 'Hoàn tiền yêu cầu rút #'.$locked->id, 'withdraw_refund_'.$locked->id, false, $by);
                $locked->update(['status' => 'rejected', 'reject_reason' => $reason, 'admin_note' => $text, 'processed_by' => $by, 'processed_at' => now()]);

                return true;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi: '.$e->getMessage()];
        } finally {
            $lock->release();
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
        return DB::table('withdraw_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId));
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
        ];
    }
}
