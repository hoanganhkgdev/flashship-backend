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

        // Công nợ trong kỳ, tách 3 loại: phí tuần / phạt điểm tuần / khoản khác.
        // "Còn nợ" gộp tất cả cho đúng số tiền thực tế phải thu.
        $debts = DB::table('driver_debts')
            ->whereIn('driver_id', $driverIds)
            ->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
            ->groupBy('driver_id')
            ->selectRaw("driver_id,
                COALESCE(SUM(CASE WHEN ref_id IS NULL AND note LIKE 'Phí tuần%' THEN amount_due  ELSE 0 END), 0) as fee_due,
                COALESCE(SUM(CASE WHEN ref_id IS NULL AND note LIKE 'Phí tuần%' THEN amount_paid ELSE 0 END), 0) as fee_paid,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN amount_due ELSE 0 END), 0) as penalty,
                COALESCE(SUM(amount_due - amount_paid), 0) as remaining")
            ->get()->keyBy('driver_id');

        // Tiền admin trả cho tài xế trong kỳ: thưởng điểm tuần + bù áp mã giảm giá
        $adminPaid = DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $driverIds)
            ->where('t.type', 'credit')
            ->whereBetween('t.created_at', [$fromStr, $toStr])
            ->where(function ($q) {
                $q->where('t.reference', 'like', 'score_bonus_%')
                  ->orWhere('t.reference', 'like', 'order_%_discount');
            })
            ->groupBy('w.driver_id')
            ->selectRaw("w.driver_id,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'score_bonus_%' THEN t.amount ELSE 0 END), 0) as bonus,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'order_%_discount' THEN t.amount ELSE 0 END), 0) as voucher")
            ->get()->keyBy('driver_id');

        // Tiền đã rút (duyệt trong kỳ)
        $withdrawals = DB::table('withdraw_requests')
            ->whereIn('driver_id', $driverIds)
            ->where('status', 'approved')
            ->whereBetween(DB::raw('COALESCE(processed_at, updated_at)'), [$fromStr, $toStr])
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COALESCE(SUM(amount), 0) as total')
            ->pluck('total', 'driver_id');

        $rows = [];
        foreach ($drivers as $d) {
            $debt = $debts->get($d->id);
            $pay  = $adminPaid->get($d->id);

            $feeDue    = (int) ($debt->fee_due ?? 0);
            $feePaid   = (int) ($debt->fee_paid ?? 0);
            $penalty   = (int) ($debt->penalty ?? 0);
            $remaining = (int) max(0, (int) ($debt->remaining ?? 0));
            $bonus     = (int) ($pay->bonus ?? 0);
            $voucher   = (int) ($pay->voucher ?? 0);
            $withdrawn = (int) ($withdrawals[$d->id] ?? 0);

            if (!$feeDue && !$feePaid && !$penalty && !$bonus && !$voucher && !$withdrawn && !$remaining) {
                continue;
            }

            $rows[] = [
                'id'        => $d->id,
                'name'      => $d->name,
                'phone'     => $d->phone,
                'penalty'   => $penalty,
                'bonus'     => $bonus,
                'voucher'   => $voucher,
                'fee_due'   => $feeDue,
                'fee_paid'  => $feePaid,
                'remaining' => $remaining,
                'withdrawn' => $withdrawn,
            ];
        }

        usort($rows, fn ($a, $b) => $b['remaining'] <=> $a['remaining']);

        return $rows;
    }

    public function getTotalsProperty(): array
    {
        $rows = $this->rows;
        $sum  = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'drivers'   => count($rows),
            'penalty'   => $sum('penalty'),
            'bonus'     => $sum('bonus'),
            'voucher'   => $sum('voucher'),
            'fee_due'   => $sum('fee_due'),
            'fee_paid'  => $sum('fee_paid'),
            'remaining' => $sum('remaining'),
            'withdrawn' => $sum('withdrawn'),
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
            'Mã TX', 'Tài xế', 'SĐT',
            'Phạt tuần (đ)', 'Thưởng điểm (đ)', 'Tiền áp mã (đ)',
            'Phí tuần (đ)', 'Đã đóng (đ)', 'Còn nợ (đ)', 'Đã rút (đ)',
        ]];

        foreach ($rows as $r) {
            $data[] = [
                $r['id'], $r['name'], $r['phone'],
                $r['penalty'], $r['bonus'], $r['voucher'],
                $r['fee_due'], $r['fee_paid'], $r['remaining'], $r['withdrawn'],
            ];
        }

        $data[] = [
            '', 'TỔNG CỘNG', '',
            $totals['penalty'], $totals['bonus'], $totals['voucher'],
            $totals['fee_due'], $totals['fee_paid'], $totals['remaining'], $totals['withdrawn'],
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
