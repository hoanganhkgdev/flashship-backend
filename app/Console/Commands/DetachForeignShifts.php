<?php

namespace App\Console\Commands;

use App\Models\DriverStatusLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ các ca thuộc khu vực khác với khu vực hiện tại của tài xế (để lại từ những lần đổi khu vực trước khi form sửa tài
 * xế tự gỡ). Còn giữ thì app báo "đã đăng ký ca" nên tài xế không tự chọn được ca ở khu vực mới. Mỗi tài xế được ghi
 * một dòng lịch sử tài khoản.
 */
class DetachForeignShifts extends Command
{
    protected $signature = 'drivers:detach-foreign-shifts {--dry-run : Chỉ liệt kê, không gỡ}';

    protected $description = 'Gỡ ca của khu vực cũ khỏi tài xế đã đổi khu vực';

    public function handle(): int
    {
        $rows = DB::table('shift_user as su')->join('shifts as s', 's.id', '=', 'su.shift_id')->join('users as u', 'u.id', '=', 'su.user_id')
            ->where('u.user_type', 'driver')->whereColumn('s.city_id', '!=', 'u.city_id')
            ->get(['su.user_id', 'su.shift_id', 'u.name as driver', 's.name as shift', 's.city_id']);

        foreach ($rows->groupBy('user_id') as $driverId => $items) {
            $names = $items->map(fn ($r) => $r->shift.' (khu vực #'.$r->city_id.')')->implode(', ');
            $this->line("#{$driverId} {$items->first()->driver}: {$names}");
            if ($this->option('dry-run')) {
                continue;
            }
            DB::transaction(function () use ($driverId, $items, $names) {
                DB::table('shift_user')->where('user_id', $driverId)->whereIn('shift_id', $items->pluck('shift_id'))->delete();
                DriverStatusLog::create(['driver_id' => $driverId, 'action' => 'city_change', 'reason' => 'Dọn ca của khu vực cũ: '.$names, 'performed_by' => null, 'created_at' => now()]);
            });
        }

        $this->info(($this->option('dry-run') ? '[thử] ' : '').'Ca khu vực cũ: '.$rows->count().' dòng của '.$rows->pluck('user_id')->unique()->count().' tài xế.');

        return self::SUCCESS;
    }
}
