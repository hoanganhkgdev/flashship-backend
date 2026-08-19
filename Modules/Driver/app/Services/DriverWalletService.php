<?php
namespace Modules\Driver\Services;

use Modules\Driver\Models\DriverWallet;
use Illuminate\Support\Facades\DB;

class DriverWalletService
{
    public static function adjust(
        int|string $driverId,
        float $amount,
        string $type = 'credit',
        ?string $desc = null,
        ?string $ref = null,
        bool $allowNegative = false
    ) {
        return DB::transaction(function () use ($driverId, $amount, $type, $desc, $ref, $allowNegative) {
            // lockForUpdate() để khoá đúng dòng ví của tài xế này trong suốt
            // transaction — tránh 2 lệnh cộng/trừ tiền gần như đồng thời (đơn
            // hoàn thành cộng tiền, rút tiền, hoàn công nợ...) cùng đọc
            // balance cũ rồi ghi đè nhau, làm mất 1 giao dịch (lost update).
            $wallet = DriverWallet::where('driver_id', $driverId)->lockForUpdate()->first();
            if (!$wallet) {
                DriverWallet::firstOrCreate(['driver_id' => $driverId]);
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
            ]);
        });
    }
}
