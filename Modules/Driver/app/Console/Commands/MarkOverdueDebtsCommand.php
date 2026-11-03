<?php
namespace Modules\Driver\Console\Commands;

use App\Services\DriverDebtService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverDebt;

class MarkOverdueDebtsCommand extends Command
{
    protected $signature   = 'driver:mark-overdue-debts';
    protected $description = 'Đánh dấu quá hạn các khoản công nợ chưa đóng khi qua hạn';

    public function handle(): int
    {
        $today  = Carbon::today()->toDateString();
        $debts = DriverDebt::with('driver')
            ->where('status', 'pending')
            ->whereColumn('amount_due', '>', 'amount_paid')
            ->where(function ($q) use ($today) {
                // Công nợ theo kỳ (phí tuần): hạn hết ngày week_end, quá hạn từ 0h ngày hôm sau.
                $q->whereNull('ref_id')->where('week_end', '<', $today);
                // Công nợ phạt (điểm cũ, Offline quá giờ): lọc hạn theo khu vực của tài xế bên dưới.
                $q->orWhere('ref_id', 'like', 'score\_penalty\_%')
                    ->orWhere('ref_id', 'like', 'shift\_offline\_%')
                    // Công nợ % phí ship theo ngày: hạn riêng theo khu vực.
                    ->orWhere('ref_id', 'like', 'daily\_commission\_%');
            })
            ->get()
            ->filter(fn (DriverDebt $debt) => $debt->driver === null || $debt->driver->debtApplies())
            ->filter(fn (DriverDebt $debt) => $debt->ref_id === null
                || $debt->created_at->lte(Carbon::now()->subHours(
                    str_starts_with($debt->ref_id, 'daily_commission_')
                        ? OperationalSettings::dailyCommissionOverdueHours($debt->city_id ?? $debt->driver?->city_id)
                        : OperationalSettings::penaltyDebtOverdueHours($debt->city_id ?? $debt->driver?->city_id)
                )));

        $count = 0;
        $fcm   = FCMService::getInstance();

        foreach ($debts as $debt) {
            // Cập nhật có điều kiện: khoản vừa được thu xong (ví/QR/admin) giữa lúc đọc và lúc ghi
            // không bị ghi đè thành quá hạn.
            $updated = DriverDebt::whereKey($debt->id)
                ->where('status', 'pending')
                ->whereColumn('amount_due', '>', 'amount_paid')
                ->update(['status' => 'overdue', 'updated_at' => now()]);
            if ($updated === 0) {
                continue;
            }
            DriverDebtService::log($debt->id, 'overdue', 0, null, 'Tự động khi qua hạn');
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

        $this->info("Đã đánh dấu {$count} khoản công nợ quá hạn.");
        Log::info("[OverdueDebts] Marked {$count} debts as overdue (today={$today})");

        return self::SUCCESS;
    }
}
