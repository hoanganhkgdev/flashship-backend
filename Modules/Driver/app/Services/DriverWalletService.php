<?php
namespace Modules\Driver\Services;

use Modules\Core\Models\User;
use Modules\Driver\Models\DriverWallet;
use Illuminate\Support\Facades\DB;

class DriverWalletService
{
    /** Mức điều chỉnh một lần trở lên phải nhập lại số tiền để xác nhận. */
    public const ADJUST_CONFIRM_ABOVE = 5_000_000;

    /** Trần một lần điều chỉnh tay và một lần rút (đồng). */
    public const ADJUST_MAX = 100_000_000;

    public const MAX_WITHDRAW = 50_000_000;

    /** Ví của tài xế; tạo nếu chưa có. Hai request tạo cùng lúc không làm nhau lỗi (khóa duy nhất theo tài xế). */
    public static function ensureWallet(int|string $driverId): DriverWallet
    {
        DB::table('driver_wallets')->insertOrIgnore(['driver_id' => $driverId, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DriverWallet::where('driver_id', $driverId)->firstOrFail();
    }

    /** Nợ quá hạn còn phải thu của tài xế; 0 nếu công nợ không áp dụng cho tài xế này (đã tắt công nợ). */
    public static function overdueDebt(User $driver): float
    {
        if (! $driver->debtApplies()) {
            return 0.0;
        }

        return (float) DB::table('driver_debts')->where('driver_id', $driver->id)->where('status', 'overdue')
            ->whereColumn('amount_due', '>', 'amount_paid')->sum(DB::raw('amount_due - amount_paid'));
    }

    /** Số tiền được rút: phần ví vượt quá nợ quá hạn, để còn tiền "Thu từ ví" khi tài xế nợ. */
    public static function withdrawable(User $driver, float $balance): float
    {
        return max(0.0, $balance - self::overdueDebt($driver));
    }

    public static function adjust(
        int|string $driverId,
        float $amount,
        string $type = 'credit',
        ?string $desc = null,
        ?string $ref = null,
        bool $allowNegative = false,
        ?int $by = null
    ) {
        return DB::transaction(function () use ($driverId, $amount, $type, $desc, $ref, $allowNegative, $by) {
            // lockForUpdate() để khoá đúng dòng ví của tài xế này trong suốt
            // transaction — tránh 2 lệnh cộng/trừ tiền gần như đồng thời (đơn
            // hoàn thành cộng tiền, rút tiền, hoàn công nợ...) cùng đọc
            // balance cũ rồi ghi đè nhau, làm mất 1 giao dịch (lost update).
            $wallet = DriverWallet::where('driver_id', $driverId)->lockForUpdate()->first();
            if (!$wallet) {
                self::ensureWallet($driverId);
                $wallet = DriverWallet::where('driver_id', $driverId)->lockForUpdate()->first();
            }

            if ($ref && $wallet->transactions()->where('reference', $ref)->exists()) {
                return $wallet->transactions()->where('reference', $ref)->first();
            }

            if ($type === 'debit' && !$allowNegative && $wallet->balance < $amount) {
                throw new \Exception("Số dư không đủ (hiện có: " . number_format($wallet->balance, 0, ',', '.') . "₫)");
            }

            $wallet->balance += $type === 'credit' ? $amount : -$amount;
            $wallet->save();

            return $wallet->transactions()->create([
                'type'        => $type,
                'amount'      => $amount,
                'description' => $desc,
                'reference'   => $ref,
                'performed_by' => $by,
            ]);
        });
    }

    /**
     * Admin cộng/trừ tay. Mã tham chiếu luôn duy nhất để hai lần điều chỉnh sát nhau
     * không bị coi là trùng (và bị bỏ qua âm thầm), người thực hiện được lưu lại.
     * Tiền đồng không có phần lẻ; ngoài khoảng cho phép thì từ chối ngay ở đây, không chỉ ở form.
     */
    public static function adminAdjust(int $driverId, float $amount, string $type, string $reason, ?int $by)
    {
        $amount = (float) round($amount);
        if ($amount < 1000 || $amount > self::ADJUST_MAX) {
            throw new \Exception('Số tiền điều chỉnh phải từ 1.000₫ đến '.number_format(self::ADJUST_MAX, 0, ',', '.').'₫.');
        }

        return self::adjust($driverId, $amount, $type, $reason, 'admin_adj_'.$driverId.'_'.str_replace('.', '', uniqid('', true)), false, $by);
    }
}
