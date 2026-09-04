<?php

namespace App\Filament\Pages;

use App\Support\SimpleXlsxWriter;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverFinanceReportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Tài chính tài xế';

    protected static ?string $navigationLabel = 'Báo cáo thu chi tài xế';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.driver-finance-report';

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin']);
    }

    public string $mode = 'week';   // week | month

    public string $date = '';

    public string $search = '';

    public string $debtStatus = 'all';

    public string $sortBy = 'remaining';

    public function mount(): void
    {
        $this->date = now()->startOfWeek()->toDateString();
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
            ? 'Tháng '.$from->format('m/Y')
            : "Tuần {$from->format('d/m')} – {$to->format('d/m/Y')}";
    }

    public function getRowsProperty(): array
    {
        [$from, $to] = $this->range;
        $fromStr = $from->toDateString().' 00:00:00';
        $toStr = $to->toDateString().' 23:59:59';
        $cityId = Filament::getTenant()?->id;

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->where('status', '>=', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        $driverIds = $drivers->pluck('id')->toArray();
        if (empty($driverIds)) {
            return [];
        }

        // Công nợ trong kỳ, tách 3 loại: phí tuần / phạt điểm tuần / khoản khác.
        // "Còn nợ" gộp tất cả cho đúng số tiền thực tế phải thu.
        $debts = DB::table('driver_debts')
            ->whereIn('driver_id', $driverIds)
            ->where(function ($query) use ($from, $to, $fromStr, $toStr) {
                $query->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
                    ->orWhere(function ($dateQuery) use ($from, $to) {
                        $dateQuery->whereNull('week_start')
                            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
                    })
                    ->orWhere(function ($createdQuery) use ($fromStr, $toStr) {
                        $createdQuery->whereNull('week_start')
                            ->whereNull('date')
                            ->whereBetween('created_at', [$fromStr, $toStr]);
                    });
            })
            ->groupBy('driver_id')
            ->selectRaw("driver_id,
                COALESCE(SUM(CASE WHEN ref_id IS NULL AND note LIKE 'Phí tuần%' THEN amount_due  ELSE 0 END), 0) as fee_due,
                COALESCE(SUM(CASE WHEN ref_id IS NULL AND note LIKE 'Phí tuần%' THEN amount_paid ELSE 0 END), 0) as fee_paid,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN amount_due ELSE 0 END), 0) as penalty,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN amount_paid ELSE 0 END), 0) as penalty_paid,
                COALESCE(SUM(amount_due), 0) as total_due,
                COALESCE(SUM(amount_paid), 0) as total_paid,
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
            $pay = $adminPaid->get($d->id);

            $feeDue = (int) ($debt->fee_due ?? 0);
            $feePaid = (int) ($debt->fee_paid ?? 0);
            $penalty = (int) ($debt->penalty ?? 0);
            $penaltyPaid = (int) ($debt->penalty_paid ?? 0);
            $totalDue = (int) ($debt->total_due ?? 0);
            $totalPaid = (int) ($debt->total_paid ?? 0);
            $remaining = (int) max(0, (int) ($debt->remaining ?? 0));
            $bonus = (int) ($pay->bonus ?? 0);
            $voucher = (int) ($pay->voucher ?? 0);
            $withdrawn = (int) ($withdrawals[$d->id] ?? 0);

            if (! $totalDue && ! $totalPaid && ! $bonus && ! $voucher && ! $withdrawn) {
                continue;
            }

            $rows[] = [
                'id' => $d->id,
                'name' => $d->name,
                'phone' => $d->phone,
                'penalty' => $penalty,
                'penalty_paid' => $penaltyPaid,
                'bonus' => $bonus,
                'voucher' => $voucher,
                'fee_due' => $feeDue,
                'fee_paid' => $feePaid,
                'total_due' => $totalDue,
                'total_paid' => $totalPaid,
                'remaining' => $remaining,
                'withdrawn' => $withdrawn,
            ];
        }

        $search = mb_strtolower(trim($this->search));
        $rows = array_values(array_filter($rows, function (array $row) use ($search) {
            if ($search !== '' && ! str_contains(mb_strtolower($row['name'].' '.$row['phone']), $search)) {
                return false;
            }
            if ($this->debtStatus === 'outstanding' && $row['remaining'] <= 0) {
                return false;
            }
            if ($this->debtStatus === 'settled' && $row['remaining'] > 0) {
                return false;
            }

            return true;
        }));

        $sortKey = match ($this->sortBy) {
            'paid' => 'total_paid',
            'admin_paid' => 'admin_paid',
            'withdrawn' => 'withdrawn',
            default => 'remaining',
        };
        foreach ($rows as &$row) {
            $row['admin_paid'] = $row['bonus'] + $row['voucher'];
        }
        unset($row);
        usort($rows, fn ($a, $b) => $b[$sortKey] <=> $a[$sortKey] ?: strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    public function getTotalsProperty(): array
    {
        $rows = $this->rows;
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'drivers' => count($rows),
            'penalty' => $sum('penalty'),
            'penalty_paid' => $sum('penalty_paid'),
            'bonus' => $sum('bonus'),
            'voucher' => $sum('voucher'),
            'fee_due' => $sum('fee_due'),
            'fee_paid' => $sum('fee_paid'),
            'total_due' => $sum('total_due'),
            'total_paid' => $sum('total_paid'),
            'admin_paid' => $sum('admin_paid'),
            'remaining' => $sum('remaining'),
            'withdrawn' => $sum('withdrawn'),
        ];
    }

    /**
     * Danh sách kỳ cho dropdown: 16 tuần hoặc 12 tháng gần nhất,
     * value = ngày đầu kỳ, label ghi rõ từ ngày đến ngày.
     */
    public function getPeriodsProperty(): array
    {
        $periods = [];

        if ($this->mode === 'month') {
            $m = now()->startOfMonth();
            for ($i = 0; $i < 12; $i++) {
                $periods[$m->toDateString()] = 'Tháng '.$m->format('m/Y');
                $m = $m->subMonth();
            }
        } else {
            $w = now()->startOfWeek();
            for ($i = 0; $i < 16; $i++) {
                $label = ($i === 0 ? 'Tuần này: ' : '')
                    .$w->format('d/m').' – '.$w->copy()->endOfWeek()->format('d/m/Y');
                $periods[$w->toDateString()] = $label;
                $w = $w->subWeek();
            }
        }

        return $periods;
    }

    /** Đổi chế độ tuần/tháng → đưa kỳ đang chọn về đầu kỳ hiện tại cho khớp dropdown. */
    public function updatedMode(): void
    {
        $this->date = $this->mode === 'month'
            ? now()->startOfMonth()->toDateString()
            : now()->startOfWeek()->toDateString();
    }

    public function export(): StreamedResponse
    {
        $rows = $this->rows;
        $totals = $this->totals;

        $data = [[
            'Mã TX', 'Tài xế', 'SĐT',
            'Phạt tuần (đ)', 'Thưởng điểm (đ)', 'Tiền áp mã (đ)',
            'Phí tuần (đ)', 'Tổng phải thu (đ)', 'Đã thu (đ)', 'Còn nợ (đ)', 'Đã rút (đ)',
        ]];

        foreach ($rows as $r) {
            $data[] = [
                $r['id'], $r['name'], $r['phone'],
                $r['penalty'], $r['bonus'], $r['voucher'],
                $r['fee_due'], $r['total_due'], $r['total_paid'], $r['remaining'], $r['withdrawn'],
            ];
        }

        $data[] = [
            '', 'TỔNG CỘNG', '',
            $totals['penalty'], $totals['bonus'], $totals['voucher'],
            $totals['fee_due'], $totals['total_due'], $totals['total_paid'], $totals['remaining'], $totals['withdrawn'],
        ];

        [$from, $to] = $this->range;
        $suffix = $this->mode === 'month' ? $from->format('Y-m') : $from->format('Y-m-d').'_'.$to->format('Y-m-d');
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
