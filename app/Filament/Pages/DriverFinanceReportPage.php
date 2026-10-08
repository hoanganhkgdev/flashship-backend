<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use App\Support\AdminAccess;
use App\Support\SimpleXlsxWriter;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverFinanceReportPage extends Page
{
    use BuildsReportSeries;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Báo cáo';

    protected static ?string $navigationLabel = 'Thu phí tài xế';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Thu phí tài xế';

    protected static string $view = 'filament.pages.driver-finance-report';

    /** Biểu đồ xu hướng hiển thị bấy nhiêu kỳ, kết thúc ở kỳ đang chọn. */
    private const TREND_PERIODS = 8;

    private const PAGE_SIZE = 25;

    private const SORTABLE = ['name', 'fee_due', 'penalty', 'other', 'total_due', 'total_paid', 'remaining', 'admin_paid', 'withdrawn'];

    public string $mode = 'week';   // week | month

    public string $date = '';

    public string $search = '';

    public string $debtStatus = 'all';

    public string $sortBy = 'remaining';

    public string $sortDir = 'desc';

    public int $limit = self::PAGE_SIZE;

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::FINANCE_VIEW);
    }

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

    /** Danh sách kỳ cho dropdown: 16 tuần hoặc 12 tháng gần nhất. value = ngày đầu kỳ. */
    public function getPeriodsProperty(): array
    {
        $periods = [];

        if ($this->mode === 'month') {
            $m = now()->startOfMonth();
            for ($i = 0; $i < 12; $i++) {
                $periods[$m->toDateString()] = ($i === 0 ? 'Tháng này: ' : '').'Tháng '.$m->format('m/Y');
                $m = $m->subMonth();
            }
        } else {
            $w = now()->startOfWeek();
            for ($i = 0; $i < 16; $i++) {
                $periods[$w->toDateString()] = ($i === 0 ? 'Tuần này: ' : '').$w->format('d/m').' – '.$w->copy()->endOfWeek()->format('d/m/Y');
                $w = $w->subWeek();
            }
        }

        return $periods;
    }

    /** Đổi chế độ tuần/tháng → đưa kỳ đang chọn về đầu kỳ hiện tại cho khớp dropdown. */
    public function updatedMode(): void
    {
        $this->date = $this->mode === 'month' ? now()->startOfMonth()->toDateString() : now()->startOfWeek()->toDateString();
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedDate(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    /** Về kỳ hiện tại / lùi / tiến một kỳ — chỉ trong phạm vi dropdown cho phép. */
    public function shiftPeriod(int $direction): void
    {
        $periods = array_keys($this->periods);
        $i = array_search($this->date, $periods, true);
        $i = $i === false ? 0 : $i;
        $next = $i - $direction; // dropdown xếp từ mới → cũ
        if (isset($periods[$next])) {
            $this->date = $periods[$next];
            $this->limit = self::PAGE_SIZE;
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'desc' ? 'asc' : ($column === 'name' && $this->sortBy !== 'name' ? 'asc' : 'desc');
        $this->sortBy = $column;
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function getReportData(): array
    {
        [$from, $to] = $this->range;
        $driverIds = $this->driverIds();

        $all = $this->driverRows($driverIds, $from, $to);
        $totals = $this->totals($all);
        $visible = $this->filterAndSort($all);

        return [
            'from' => $from, 'to' => $to,
            'totals' => $totals,
            'overall' => $this->outstanding($driverIds),
            'pending_withdraw' => $this->pendingWithdraw($driverIds),
            'adjust' => $this->walletAdjustments($driverIds, $from, $to),
            'chart' => $this->trend($driverIds, $from),
            'drivers' => array_slice($visible, 0, $this->limit),
            'visible_count' => count($visible),
            'has_more' => count($visible) > $this->limit,
        ];
    }

    public function export(): StreamedResponse
    {
        [$from, $to] = $this->range;
        $rows = $this->filterAndSort($this->driverRows($this->driverIds(), $from, $to));
        $totals = $this->totals($this->driverRows($this->driverIds(), $from, $to));

        $data = [[
            'Mã TX', 'Tài xế', 'SĐT',
            'Phí tuần (đ)', 'Phạt tuần (đ)', 'Khoản khác (đ)', 'Tổng phải thu (đ)', 'Đã thu (đ)', 'Còn nợ (đ)',
            'Thưởng điểm (đ)', 'Bù giảm giá (đ)', 'Thưởng mưa (đ)', 'Đã rút (đ)',
        ]];
        foreach ($rows as $r) {
            $data[] = [$r['id'], $r['name'], $r['phone'], $r['fee_due'], $r['penalty'], $r['other'], $r['total_due'], $r['total_paid'], $r['remaining'], $r['bonus'], $r['voucher'], $r['rain'], $r['withdrawn']];
        }
        $data[] = ['', 'TỔNG CỘNG (toàn khu vực)', '', $totals['fee_due'], $totals['penalty'], $totals['other'], $totals['total_due'], $totals['total_paid'], $totals['remaining'], $totals['bonus'], $totals['voucher'], $totals['rain'], $totals['withdrawn']];

        $suffix = $this->mode === 'month' ? $from->format('Y-m') : $from->format('Y-m-d').'_'.$to->format('Y-m-d');
        $path = SimpleXlsxWriter::write($data, 'Thu phí tài xế');

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, "thu-phi-tai-xe_{$suffix}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Kỳ của một khoản nợ: tuần của khoản phí, nếu không có thì ngày, rồi ngày tạo. */
    private const DEBT_PERIOD_SQL = 'COALESCE(week_start, date, DATE(created_at))';

    private function driverIds(): array
    {
        return DB::table('users')
            ->where('user_type', 'driver')->where('status', '>=', 1)
            ->when(Filament::getTenant()?->id, fn ($q, $id) => $q->where('city_id', $id))
            ->pluck('id')->all();
    }

    private function driverRows(array $driverIds, Carbon $from, Carbon $to): array
    {
        if (! $driverIds) {
            return [];
        }
        $fromStr = $from->toDateString().' 00:00:00';
        $toStr = $to->toDateString().' 23:59:59';

        $drivers = DB::table('users')->whereIn('id', $driverIds)->orderBy('name')->get(['id', 'name', 'phone']);

        // Công nợ trong kỳ: phí tuần / phạt điểm / khoản khác. "Còn nợ" gộp tất cả để ra đúng số tiền phải thu.
        $debts = DB::table('driver_debts')
            ->whereIn('driver_id', $driverIds)
            ->whereRaw(self::DEBT_PERIOD_SQL.' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->groupBy('driver_id')
            ->selectRaw("driver_id,
                COALESCE(SUM(CASE WHEN ref_id IS NULL AND note LIKE 'Phí tuần%' THEN amount_due  ELSE 0 END), 0) as fee_due,
                COALESCE(SUM(CASE WHEN ref_id LIKE 'score_penalty%' THEN amount_due ELSE 0 END), 0) as penalty,
                COALESCE(SUM(amount_due), 0) as total_due,
                COALESCE(SUM(amount_paid), 0) as total_paid,
                COALESCE(SUM(GREATEST(amount_due - amount_paid, 0)), 0) as remaining")
            ->get()->keyBy('driver_id');

        // Tiền công ty ghi có cho tài xế trong kỳ: thưởng điểm, bù giảm giá, thưởng mưa.
        $paid = DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $driverIds)->where('t.type', 'credit')
            ->whereBetween('t.created_at', [$fromStr, $toStr])
            ->where(fn ($q) => $q->where('t.reference', 'like', 'score\_bonus\_%')->orWhere('t.reference', 'like', 'order\_%\_discount')->orWhere('t.reference', 'like', 'order\_%\_rain'))
            ->groupBy('w.driver_id')
            ->selectRaw("w.driver_id,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'score\\_bonus\\_%' THEN t.amount ELSE 0 END), 0) as bonus,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'order\\_%\\_discount' THEN t.amount ELSE 0 END), 0) as voucher,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'order\\_%\\_rain' THEN t.amount ELSE 0 END), 0) as rain")
            ->get()->keyBy('driver_id');

        $withdrawals = DB::table('withdraw_requests')
            ->whereIn('driver_id', $driverIds)->where('status', 'approved')
            ->whereBetween(DB::raw('COALESCE(processed_at, updated_at)'), [$fromStr, $toStr])
            ->groupBy('driver_id')->selectRaw('driver_id, COALESCE(SUM(amount), 0) as total')->pluck('total', 'driver_id');

        $rows = [];
        foreach ($drivers as $d) {
            $debt = $debts->get($d->id);
            $pay = $paid->get($d->id);
            $feeDue = (int) ($debt->fee_due ?? 0);
            $penalty = (int) ($debt->penalty ?? 0);
            $totalDue = (int) ($debt->total_due ?? 0);
            $bonus = (int) ($pay->bonus ?? 0);
            $voucher = (int) ($pay->voucher ?? 0);
            $rain = (int) ($pay->rain ?? 0);
            $withdrawn = (int) ($withdrawals[$d->id] ?? 0);
            $totalPaid = (int) ($debt->total_paid ?? 0);

            if (! $totalDue && ! $totalPaid && ! $bonus && ! $voucher && ! $rain && ! $withdrawn) {
                continue;
            }

            $rows[] = [
                'id' => $d->id, 'name' => (string) $d->name, 'phone' => (string) $d->phone,
                'fee_due' => $feeDue, 'penalty' => $penalty, 'other' => max(0, $totalDue - $feeDue - $penalty),
                'total_due' => $totalDue, 'total_paid' => $totalPaid, 'remaining' => (int) ($debt->remaining ?? 0),
                'bonus' => $bonus, 'voucher' => $voucher, 'rain' => $rain, 'admin_paid' => $bonus + $voucher + $rain,
                'withdrawn' => $withdrawn,
            ];
        }

        return $rows;
    }

    private function totals(array $rows): array
    {
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $due = $sum('total_due');

        return [
            'drivers' => count($rows),
            'debtors' => count(array_filter($rows, fn ($r) => $r['remaining'] > 0)),
            'fee_due' => $sum('fee_due'), 'penalty' => $sum('penalty'), 'other' => $sum('other'),
            'total_due' => $due, 'total_paid' => $sum('total_paid'), 'remaining' => $sum('remaining'),
            'collect_rate' => $due > 0 ? round(min($sum('total_paid'), $due) / $due * 100, 1) : null,
            'bonus' => $sum('bonus'), 'voucher' => $sum('voucher'), 'rain' => $sum('rain'), 'admin_paid' => $sum('admin_paid'),
            'withdrawn' => $sum('withdrawn'),
        ];
    }

    /** Nợ chưa thu của MỌI kỳ (kể cả kỳ cũ không nằm trong khoảng đang xem). */
    private function outstanding(array $driverIds): array
    {
        $row = $driverIds ? DB::table('driver_debts')->whereIn('driver_id', $driverIds)->whereColumn('amount_due', '>', 'amount_paid')
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as amount, COUNT(*) as items, COUNT(DISTINCT driver_id) as drivers, MIN('.self::DEBT_PERIOD_SQL.') as oldest')->first() : null;

        return [
            'amount' => (int) ($row->amount ?? 0), 'items' => (int) ($row->items ?? 0),
            'drivers' => (int) ($row->drivers ?? 0), 'oldest' => ($row->oldest ?? null) ? Carbon::parse($row->oldest) : null,
        ];
    }

    /** Tiền tài xế đã yêu cầu rút nhưng chưa duyệt (mọi thời điểm) — việc đang chờ admin. */
    private function pendingWithdraw(array $driverIds): array
    {
        $row = $driverIds ? DB::table('withdraw_requests')->whereIn('driver_id', $driverIds)->where('status', 'pending')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount, COUNT(*) as items')->first() : null;

        return ['amount' => (int) ($row->amount ?? 0), 'items' => (int) ($row->items ?? 0)];
    }

    /** Điều chỉnh ví thủ công của admin: chỉ hiển thị tham khảo, không tính vào "Admin đã chi". */
    private function walletAdjustments(array $driverIds, Carbon $from, Carbon $to): array
    {
        $rows = $driverIds ? DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $driverIds)->where('t.reference', 'like', 'admin\_adj%')
            ->whereBetween('t.created_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])
            ->groupBy('t.type')->selectRaw('t.type, COALESCE(SUM(t.amount), 0) as amount')->pluck('amount', 'type') : collect();

        return ['credit' => (int) ($rows['credit'] ?? 0), 'debit' => (int) ($rows['debit'] ?? 0)];
    }

    /** Xu hướng thu nợ: 8 kỳ gần nhất kết thúc ở kỳ đang xem. Cột = đã thu, nét đứt = phải thu, đường = còn nợ. */
    private function trend(array $driverIds, Carbon $anchorFrom): array
    {
        $gran = $this->mode === 'month' ? 'month' : 'week';
        $start = ($gran === 'month' ? $anchorFrom->copy()->subMonthsNoOverflow(self::TREND_PERIODS - 1)->startOfMonth() : $anchorFrom->copy()->subWeeks(self::TREND_PERIODS - 1));
        $end = $this->range[1];
        $buckets = $this->buckets($start, $end, $gran);

        $data = $driverIds ? DB::table('driver_debts')->whereIn('driver_id', $driverIds)
            ->whereRaw(self::DEBT_PERIOD_SQL.' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->selectRaw($this->bucketSql(self::DEBT_PERIOD_SQL, $gran).' as k, COALESCE(SUM(amount_due), 0) as due, COALESCE(SUM(amount_paid), 0) as paid, COALESCE(SUM(GREATEST(amount_due - amount_paid, 0)), 0) as remaining')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k) : collect();

        $rows = [];
        foreach ($buckets as $key => $bucket) {
            $r = $data->get((string) $key);
            $rows[] = $bucket + [
                'revenue' => (int) ($r->paid ?? 0),          // cột: đã thu
                'prev_revenue' => (int) ($r->due ?? 0),      // nét đứt: phải thu
                'completed' => (int) ($r->remaining ?? 0),   // đường: còn nợ
                'due' => (int) ($r->due ?? 0), 'paid' => (int) ($r->paid ?? 0), 'remaining' => (int) ($r->remaining ?? 0),
            ];
        }

        return $this->chartData($rows);
    }

    private function filterAndSort(array $rows): array
    {
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

        $by = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'remaining';
        $dir = $this->sortDir === 'asc' ? 1 : -1;
        usort($rows, fn ($a, $b) => $by === 'name'
            ? $dir * strcasecmp($a['name'], $b['name'])
            : ($dir * ($a[$by] <=> $b[$by]) ?: strcasecmp($a['name'], $b['name'])));

        return $rows;
    }
}
