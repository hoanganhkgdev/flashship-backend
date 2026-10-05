<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\FCMService;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Models\DriverDebtLog;
use Modules\Driver\Services\DriverWalletService;

/** Mọi thay đổi công nợ đều đi qua đây để luôn có nhật ký (ai, bao nhiêu, vì sao). Không có sửa tự do và không xóa. */
class DriverDebtService
{
    public const ACTIONS = [
        'created' => 'Tạo khoản nợ',
        'adjusted' => 'Điều chỉnh số nợ',
        'paid_wallet' => 'Thu từ ví',
        'paid_payos' => 'Thu qua QR PayOS',
        'paid_manual' => 'Thu ngoài hệ thống',
        'waived' => 'Miễn nợ',
        'overdue' => 'Đánh dấu quá hạn',
        'reminded' => 'Nhắc nợ',
    ];

    public const PAID_VIA = ['wallet' => 'Ví', 'payos' => 'QR PayOS', 'manual' => 'Ngoài hệ thống', 'waived' => 'Miễn nợ'];

    public const TYPES = ['weekly' => 'Phí tuần', 'commission' => 'Phí hoa hồng', 'cod' => 'Thu hộ (COD)'];

    /** Mỗi khoản chỉ nhắc lại sau bấy nhiêu giờ. */
    public const REMIND_COOLDOWN_HOURS = 12;

    public static function remaining(DriverDebt $d): float
    {
        return max(0, (float) $d->amount_due - (float) $d->amount_paid);
    }

    public static function log(int $debtId, string $action, float $amount = 0, ?int $by = null, ?string $note = null): void
    {
        DriverDebtLog::create(['debt_id' => $debtId, 'action' => $action, 'amount' => $amount, 'performed_by' => $by, 'note' => $note]);
    }

    /** @return array{ok:bool,message:string} */
    public static function create(int $driverId, string $type, float $amount, string $reason, ?int $by): array
    {
        if (! isset(self::TYPES[$type]) || $amount < 1000 || mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'message' => 'Cần loại nợ hợp lệ, số tiền từ 1.000₫ và lý do ít nhất 5 ký tự.'];
        }

        $debt = DB::transaction(function () use ($driverId, $type, $amount, $reason, $by) {
            $debt = DriverDebt::create([
                'driver_id' => $driverId, 'debt_type' => $type, 'status' => 'pending',
                'amount_due' => $amount, 'amount_paid' => 0, 'date' => now()->toDateString(), 'note' => $reason,
            ]);
            self::log($debt->id, 'created', $amount, $by, $reason);

            return $debt;
        });

        return ['ok' => true, 'message' => 'Đã tạo khoản nợ #'.$debt->id.'.'];
    }

    /** Ghi nhận một khoản thu đã xảy ra: tăng amount_paid, đóng nợ nếu đủ. Phải gọi trong transaction đã khóa dòng nợ. */
    private static function applyPayment(DriverDebt $d, float $amount, string $via): void
    {
        $paid = min((float) $d->amount_due, (float) $d->amount_paid + $amount);
        $done = $paid >= (float) $d->amount_due;
        $d->update([
            'amount_paid' => $paid,
            'status' => $done ? 'paid' : $d->status,
            'paid_at' => $done ? now() : $d->paid_at,
            'paid_via' => $done ? $via : $d->paid_via,
        ]);
    }

    /** Thu từ ví tài xế, được thu một phần (tối đa bằng số còn lại và số dư ví). */
    public static function payFromWallet(DriverDebt $debt, float $amount, ?int $by): array
    {
        if ($amount < 1) {
            return ['ok' => false, 'message' => 'Số tiền không hợp lệ.'];
        }

        try {
            return DB::transaction(function () use ($debt, $amount, $by) {
                $d = DriverDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
                $remaining = self::remaining($d);
                if ($d->status === 'paid' || $remaining <= 0) {
                    return ['ok' => false, 'message' => 'Khoản nợ này đã thanh toán xong.'];
                }
                if ($amount > $remaining) {
                    return ['ok' => false, 'message' => 'Số tiền vượt phần còn lại ('.number_format($remaining, 0, ',', '.').'₫).'];
                }

                // Ném lỗi nếu ví không đủ (không cho âm ví).
                DriverWalletService::adjust($d->driver_id, $amount, 'debit', 'Thanh toán công nợ #'.$d->id, 'debt_admin_'.$d->id.'_'.str_replace('.', '', uniqid('', true)), false, $by);
                self::applyPayment($d, $amount, 'wallet');
                self::log($d->id, 'paid_wallet', $amount, $by);

                return ['ok' => true, 'message' => 'Đã thu '.number_format($amount, 0, ',', '.').'₫ từ ví.'.($d->fresh()->status === 'paid' ? ' Khoản nợ đã thanh toán xong.' : '')];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Admin đã thu ngoài hệ thống (tiền mặt, chuyển khoản tay): chỉ ghi nhận. */
    public static function collectManual(DriverDebt $debt, float $amount, string $note, ?int $by): array
    {
        if ($amount < 1 || mb_strlen(trim($note)) < 3) {
            return ['ok' => false, 'message' => 'Cần số tiền và ghi chú (hình thức/mã giao dịch).'];
        }

        return DB::transaction(function () use ($debt, $amount, $note, $by) {
            $d = DriverDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $remaining = self::remaining($d);
            if ($d->status === 'paid' || $remaining <= 0) {
                return ['ok' => false, 'message' => 'Khoản nợ này đã thanh toán xong.'];
            }
            if ($amount > $remaining) {
                return ['ok' => false, 'message' => 'Số tiền vượt phần còn lại ('.number_format($remaining, 0, ',', '.').'₫).'];
            }
            self::applyPayment($d, $amount, 'manual');
            self::log($d->id, 'paid_manual', $amount, $by, $note);

            return ['ok' => true, 'message' => 'Đã ghi nhận thu '.number_format($amount, 0, ',', '.').'₫.'];
        });
    }

    /** Miễn phần còn lại: hạ số phải thu về phần đã thu, nên báo cáo không tính nhầm là đã thu. */
    public static function waive(DriverDebt $debt, string $reason, ?int $by): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'message' => 'Hãy ghi lý do miễn nợ (ít nhất 5 ký tự).'];
        }

        return DB::transaction(function () use ($debt, $reason, $by) {
            $d = DriverDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $remaining = self::remaining($d);
            if ($d->status === 'paid' || $remaining <= 0) {
                return ['ok' => false, 'message' => 'Khoản nợ này đã thanh toán xong.'];
            }
            $d->update(['amount_due' => $d->amount_paid, 'status' => 'paid', 'paid_at' => now(), 'paid_via' => 'waived']);
            self::log($d->id, 'waived', $remaining, $by, $reason);

            return ['ok' => true, 'message' => 'Đã miễn '.number_format($remaining, 0, ',', '.').'₫.'];
        });
    }

    /** Sửa số phải thu (tạo nhầm...). Không được thấp hơn phần đã thu. */
    public static function adjustAmount(DriverDebt $debt, float $newDue, string $reason, ?int $by): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'message' => 'Hãy ghi lý do điều chỉnh (ít nhất 5 ký tự).'];
        }

        return DB::transaction(function () use ($debt, $newDue, $reason, $by) {
            $d = DriverDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ($d->status === 'paid') {
                return ['ok' => false, 'message' => 'Khoản nợ đã thanh toán xong, không điều chỉnh được.'];
            }
            if ($newDue < (float) $d->amount_paid || $newDue < 1000 && $newDue != (float) $d->amount_paid) {
                return ['ok' => false, 'message' => 'Số nợ mới không được thấp hơn phần đã thu ('.number_format($d->amount_paid, 0, ',', '.').'₫).'];
            }
            $old = (float) $d->amount_due;
            $closes = $newDue <= (float) $d->amount_paid;
            $d->update(['amount_due' => $newDue] + ($closes ? ['status' => 'paid', 'paid_at' => now(), 'paid_via' => $d->paid_via ?: 'manual'] : []));
            self::log($d->id, 'adjusted', $newDue - $old, $by, $old.' → '.$newDue.': '.$reason);

            return ['ok' => true, 'message' => 'Đã điều chỉnh số nợ thành '.number_format($newDue, 0, ',', '.').'₫.'];
        });
    }

    public static function markOverdue(DriverDebt $debt, ?int $by): array
    {
        $ok = DriverDebt::whereKey($debt->id)->where('status', 'pending')->update(['status' => 'overdue']) > 0;
        if ($ok) {
            self::log($debt->id, 'overdue', 0, $by);
        }

        return ['ok' => $ok, 'message' => $ok ? 'Đã đánh dấu quá hạn.' : 'Khoản nợ không còn ở trạng thái chờ thanh toán.'];
    }

    /** Nhắc tài xế bằng thông báo đẩy; không nhắc lại một khoản trong thời gian chờ. */
    public static function remind(DriverDebt $debt, ?int $by): array
    {
        $remaining = self::remaining($debt);
        if ($debt->status === 'paid' || $remaining <= 0) {
            return ['ok' => false, 'message' => 'Khoản nợ đã thanh toán xong.'];
        }
        $last = DriverDebtLog::where('debt_id', $debt->id)->where('action', 'reminded')->latest('id')->first();
        if ($last && $last->created_at->gt(now()->subHours(self::REMIND_COOLDOWN_HOURS))) {
            return ['ok' => false, 'message' => 'Đã nhắc lúc '.$last->created_at->format('H:i d/m').'. Chờ '.self::REMIND_COOLDOWN_HOURS.' giờ giữa hai lần nhắc.'];
        }

        $token = $debt->driver?->fcm_token;
        if (! $token) {
            return ['ok' => false, 'message' => 'Tài xế chưa có thiết bị nhận thông báo.'];
        }
        try {
            FCMService::getInstance()->sendOverdueDebt($token, number_format($remaining, 0, ',', '.'));
        } catch (\Throwable $e) {
            Log::warning('[Debt] remind failed', ['debt' => $debt->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Không gửi được thông báo.'];
        }
        self::log($debt->id, 'reminded', 0, $by);

        return ['ok' => true, 'message' => 'Đã gửi nhắc nợ cho tài xế.'];
    }

    // ─── Số liệu ────────────────────────────────────────────────────────────────

    private static function debts(?int $cityId)
    {
        return DB::table('driver_debts as d')->join('users as u', 'u.id', '=', 'd.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId));
    }

    public static function summary(?int $cityId): array
    {
        $open = self::debts($cityId)->where('d.status', '<>', 'paid')->whereColumn('d.amount_due', '>', 'd.amount_paid')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(d.amount_due - d.amount_paid),0) s')->first();
        $overdue = self::debts($cityId)->where('d.status', 'overdue')->whereColumn('d.amount_due', '>', 'd.amount_paid')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(d.amount_due - d.amount_paid),0) s')->first();
        $blocked = self::debts($cityId)->where('d.status', 'overdue')->where('u.status', 1)
            ->selectRaw('COUNT(DISTINCT d.driver_id) c, COALESCE(SUM(d.amount_due - d.amount_paid),0) s')->first();
        $locked = self::debts($cityId)->where('u.status', 2)->where('d.status', '<>', 'paid')->whereColumn('d.amount_due', '>', 'd.amount_paid')
            ->selectRaw('COUNT(DISTINCT d.driver_id) c, COALESCE(SUM(d.amount_due - d.amount_paid),0) s')->first();
        $rate = self::debts($cityId)->where('d.created_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(SUM(d.amount_paid),0) p, COALESCE(SUM(d.amount_due),0) t')->first();

        return [
            'openCount' => (int) $open->c, 'openMoney' => (int) $open->s,
            'overdueCount' => (int) $overdue->c, 'overdueMoney' => (int) $overdue->s,
            'blockedDrivers' => (int) $blocked->c, 'blockedMoney' => (int) $blocked->s,
            'lockedDrivers' => (int) $locked->c, 'lockedMoney' => (int) $locked->s,
            'collectRate' => $rate->t > 0 ? (int) round($rate->p / $rate->t * 100) : null,
        ];
    }

    /** Tài xế đang hoạt động nhưng bị chặn nhận đơn vì nợ quá hạn, nợ lớn nhất trước. */
    public static function blockedDrivers(?int $cityId, int $limit = 5): Collection
    {
        return self::debts($cityId)->leftJoin('driver_wallets as w', 'w.driver_id', '=', 'u.id')
            ->where('d.status', 'overdue')->where('u.status', 1)
            ->groupBy('u.id', 'u.name', 'w.balance')
            ->selectRaw('u.id, u.name, COALESCE(w.balance,0) wallet, COUNT(*) items, SUM(d.amount_due - d.amount_paid) remaining, MIN(COALESCE(d.week_end, DATE(d.created_at))) since')
            ->orderByDesc('remaining')->limit($limit)->get();
    }
}
