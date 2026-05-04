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
            $wallet = DriverWallet::firstOrCreate(['driver_id' => $driverId]);

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
