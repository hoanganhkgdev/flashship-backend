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

        // Phí tuần phát sinh trong kỳ (không tính phạt điểm)
        $fees = DB::table('driver_debts')
            ->whereIn('driver_id', $driverIds)
            ->where(function ($q) {
                $q->whereNull('ref_id')->orWhere('ref_id', 'not like', 'score_penalty%');
            })
            ->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
            ->groupBy('driver_id')
            ->selectRaw('driver_id,
                COALESCE(SUM(amount_due), 0)  as due,
                COALESCE(SUM(amount_paid), 0) as paid')
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
            $fee       = $fees->get($d->id);
            $due       = (int) ($fee->due ?? 0);
            $paid      = (int) ($fee->paid ?? 0);
            $withdrawn = (int) ($withdrawals[$d->id] ?? 0);

            if ($due === 0 && $paid === 0 && $withdrawn === 0) {
                continue;
            }

            $rows[] = [
                'id'        => $d->id,
                'name'      => $d->name,
                'phone'     => $d->phone,
                'due'       => $due,
                'paid'      => $paid,
                'remaining' => max(0, $due - $paid),
                'withdrawn' => $withdrawn,
            ];
        }

        usort($rows, fn ($a, $b) => $b['paid'] <=> $a['paid']);

        return $rows;
    }

    public function getTotalsProperty(): array
    {
        $rows = $this->rows;
        $sum  = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'drivers'   => count($rows),
            'due'       => $sum('due'),
            'paid'      => $sum('paid'),
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
            'Phí tuần phải đóng (đ)', 'Đã đóng (đ)', 'Còn nợ (đ)', 'Tiền đã rút (đ)',
        ]];

        foreach ($rows as $r) {
            $data[] = [
                $r['id'], $r['name'], $r['phone'],
                $r['due'], $r['paid'], $r['remaining'], $r['withdrawn'],
            ];
        }

        $data[] = [
            '', 'TỔNG CỘNG', '',
            $totals['due'], $totals['paid'], $totals['remaining'], $totals['withdrawn'],
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
