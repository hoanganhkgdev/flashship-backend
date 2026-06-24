<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\FCMService;
use Modules\Driver\Models\DriverDebt;

class MarkOverdueDebtsCommand extends Command
{
    protected $signature   = 'driver:mark-overdue-debts';
    protected $description = 'Đánh dấu quá hạn các khoản phí tuần chưa đóng sau Chủ nhật';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subHours(24);

        $debts = DriverDebt::with('driver')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;
        $fcm   = FCMService::getInstance();

        foreach ($debts as $debt) {
            $debt->update(['status' => 'overdue']);
            $count++;

            // Gửi push notification cho driver
            $driver = $debt->driver;
            if ($driver && $driver->fcm_token) {
                try {
                    $amount = number_format($debt->amount_due - $debt->amount_paid, 0, ',', '.');
                    $fcm->sendOverdueDebt($driver->fcm_token, $amount);
                } catch (\Throwable $e) {
                    Log::warning("[OverdueDebts] FCM failed for driver {$driver->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Đã đánh dấu {$count} khoản phí tuần quá hạn.");
        Log::info("[OverdueDebts] Marked {$count} debts as overdue (today={$today})");

        return self::SUCCESS;
    }
}
