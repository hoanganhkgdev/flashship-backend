<?php

namespace App\Filament\Pages;

use App\Support\SimpleXlsxWriter;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverFinanceReportPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationGroup = 'Tổng quan';
    protected static ?string $navigationLabel = 'Báo cáo thu chi tài xế';
    protected static ?int    $navigationSort  = 3;
    protected static string  $view            = 'filament.pages.driver-finance-report';

    public function getHeading(): string { return ''; }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin']);
    }

    public string $city_id = '2';
    public string $mode    = 'week';   // week | month
    public string $date    = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function getRangeProperty(): array
    {
        $anchor = Carbon::parse($this->date ?: now()->toDateString());

        return $this->mode === 'month'
            ? [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()]
            : [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()];
    }

    public function getRangeLabelProperty(): string
    {
        [$from, $to] = $this->range;

        return $this->mode === 'month'
            ? 'Tháng ' . $from->format('m/Y')
            : "Tuần {$from->format('d/m')} – {$to->format('d/m/Y')}";
    }

    public function getRowsProperty(): array
    {
        [$from, $to] = $this->range;
        $fromStr = $from->toDateString() . ' 00:00:00';
        $toStr   = $to->toDateString() . ' 23:59:59';
        $cityId  = $this->city_id ?: 2;

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->where('status', '>=', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        $driverIds = $drivers->pluck('id')->toArray();
        if (empty($driverIds)) return [];

        // ── THU: đơn hoàn thành trong kỳ ─────────────────────────────────────
        $orderStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$fromStr, $toStr])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt, COALESCE(SUM(shipping_fee + bonus_fee + COALESCE(night_surcharge,0)), 0) as earnings')
            ->get()->keyBy('delivery_man_id');

        // ── THU: thưởng điểm tuần cộng vào ví trong kỳ ───────────────────────
        $bonuses = DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $driverIds)
            ->where('t.type', 'credit')
            ->where('t.reference', 'like', 'score_bonus_%')
            ->whereBetween('t.created_at', [$fromStr, $toStr])
            ->groupBy('w.driver_id')
            ->selectRaw('w.driver_id, COALESCE(SUM(t.amount), 0) as total')
            ->pluck('total', 'driver_id');

        // ── CHI: công nợ phát sinh trong kỳ (phí tuần + phạt điểm) ───────────
        $debts = DB::table('driver_debts')
            ->whereIn('driver_id', $driverIds)
            ->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
            ->groupBy('driver_id')
            ->selectRaw("driver_id,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN amount_due ELSE 0 END), 0) as penalty,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN 0 ELSE amount_due END), 0) as weekly_fee,
                COALESCE(SUM(amount_paid), 0) as paid,
                COALESCE(SUM(amount_due - amount_paid), 0) as remaining")
            ->get()->keyBy('driver_id');

        $rows = [];
        foreach ($drivers as $d) {
            $o    = $orderStats->get($d->id);
            $debt = $debts->get($d->id);

            $orders    = (int) ($o->cnt ?? 0);
            $earnings  = (int) ($o->earnings ?? 0);
            $bonus     = (int) ($bonuses[$d->id] ?? 0);
            $weeklyFee = (int) ($debt->weekly_fee ?? 0);
            $penalty   = (int) ($debt->penalty ?? 0);
            $paid      = (int) ($debt->paid ?? 0);
            $remaining = (int) ($debt->remaining ?? 0);

            // Bỏ dòng trắng hoàn toàn cho gọn báo cáo
            if ($orders === 0 && $earnings === 0 && $bonus === 0 && $weeklyFee === 0 && $penalty === 0) {
                continue;
            }

            $rows[] = [
                'id'         => $d->id,
                'name'       => $d->name,
                'phone'      => $d->phone,
                'orders'     => $orders,
                'earnings'   => $earnings,
                'bonus'      => $bonus,
                'total_in'   => $earnings + $bonus,
                'weekly_fee' => $weeklyFee,
                'penalty'    => $penalty,
                'total_out'  => $weeklyFee + $penalty,
                'paid'       => $paid,
                'remaining'  => $remaining,
                'net'        => $earnings + $bonus - $weeklyFee - $penalty,
            ];
        }

        usort($rows, fn ($a, $b) => $b['net'] <=> $a['net']);

        return $rows;
    }

    public function getTotalsProperty(): array
    {
        $rows = $this->rows;
        $sum  = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'drivers'   => count($rows),
            'orders'    => $sum('orders'),
            'total_in'  => $sum('total_in'),
            'total_out' => $sum('total_out'),
            'paid'      => $sum('paid'),
            'remaining' => $sum('remaining'),
            'net'       => $sum('net'),
        ];
    }

    public function getCitiesProperty(): array
    {
        return DB::table('cities')->orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function export(): StreamedResponse
    {
        $rows   = $this->rows;
        $totals = $this->totals;

        $data = [[
            'Mã TX', 'Tài xế', 'SĐT', 'Số đơn',
            'Thu phí ship (đ)', 'Thưởng điểm (đ)', 'Tổng thu (đ)',
            'Phí tuần (đ)', 'Phạt điểm (đ)', 'Tổng chi (đ)',
            'Đã trả (đ)', 'Còn nợ (đ)', 'Thực nhận (đ)',
        ]];

        foreach ($rows as $r) {
            $data[] = [
                $r['id'], $r['name'], $r['phone'], $r['orders'],
                $r['earnings'], $r['bonus'], $r['total_in'],
                $r['weekly_fee'], $r['penalty'], $r['total_out'],
                $r['paid'], $r['remaining'], $r['net'],
            ];
        }

        $data[] = [
            '', 'TỔNG CỘNG', '', $totals['orders'],
            '', '', $totals['total_in'],
            '', '', $totals['total_out'],
            $totals['paid'], $totals['remaining'], $totals['net'],
        ];

        [$from, $to] = $this->range;
        $suffix   = $this->mode === 'month' ? $from->format('Y-m') : $from->format('Y-m-d') . '_' . $to->format('Y-m-d');
        $filename = "thu-chi-tai-xe_{$suffix}.xlsx";

        $path = SimpleXlsxWriter::write($data, 'Thu chi tài xế');

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
