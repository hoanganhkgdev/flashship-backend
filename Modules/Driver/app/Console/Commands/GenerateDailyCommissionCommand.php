<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;

class GenerateDailyCommissionCommand extends Command
{
    protected $signature   = 'driver:generate-daily-commission {--date= : Ngày tính phí ship (YYYY-MM-DD), mặc định hôm qua}';
    protected $description = 'Tạo công nợ % phí ship của một ngày cho tài xế theo từng khu vực';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        $day  = $date->toDateString();

        $this->info("Tạo công nợ % phí ship ngày {$day}");

        $cities = City::active()->get()->filter(fn (City $city) => OperationalSettings::debtEnabled($city->id)
            && OperationalSettings::debtCollectionMode($city->id) === 'daily_percent'
            && OperationalSettings::dailyCommissionPercent($city->id) > 0);

        $created = 0;
        $skipped = 0;

        foreach ($cities as $city) {
            $percent = OperationalSettings::dailyCommissionPercent($city->id);

            $totals = DB::table('orders as o')
                ->join('users as u', 'u.id', '=', 'o.delivery_man_id')
                ->where('o.city_id', $city->id)
                ->where('o.status', 'completed')
                ->whereDate('o.completed_at', $day)
                ->where('u.debt_enabled', true)
                ->groupBy('o.delivery_man_id')
                ->selectRaw('o.delivery_man_id as driver_id, SUM(o.shipping_fee) as shipping')
                ->get();

            $existing = DB::table('driver_debts')
                ->where('ref_id', 'like', 'daily_commission_%_'.$day)
                ->pluck('ref_id')->flip();

            $now  = now()->toDateTimeString();
            $rows = [];

            foreach ($totals as $row) {
                $amount = (int) round($row->shipping * $percent / 100);
                $refId  = "daily_commission_{$row->driver_id}_{$day}";

                if ($amount <= 0) {
                    continue;
                }
                if ($existing->has($refId)) {
                    $skipped++;
                    continue;
                }

                $rows[] = [
                    'driver_id'   => $row->driver_id,
                    'debt_type'   => 'commission',
                    'status'      => 'pending',
                    'amount_due'  => $amount,
                    'amount_paid' => 0,
                    'week_start'  => $day,
                    'week_end'    => $day,
                    'ref_id'      => $refId,
                    'note'        => 'Thu '.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% phí ship ngày '.$date->format('d/m/Y')
                        .' ('.number_format((int) $row->shipping, 0, ',', '.')." đ) — {$city->name}",
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                $created++;
            }

            if ($rows) {
                DB::table('driver_debts')->insert($rows);
            }
            $this->line("  [{$city->name}] {$percent}% — {$totals->count()} tài xế có đơn");
        }

        $this->info("Hoàn thành: tạo {$created}, bỏ qua (đã có) {$skipped}");
        Log::info("[DailyCommission] Ngày {$day}: tạo={$created}, skip={$skipped}");

        return self::SUCCESS;
    }
}
