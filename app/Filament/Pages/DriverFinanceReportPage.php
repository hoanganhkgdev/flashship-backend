<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use App\Support\AdminAccess;
use App\Support\Csv;
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

    private const SORTABLE = ['name', 'fee_due', 'penalty', 'other', 'total_due', 'total_paid', 'waived', 'remaining', 'cod_remaining', 'admin_paid', 'withdrawn'];

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

    /** File Excel có tên, SĐT và công nợ từng tài xế: cần quyền xuất dữ liệu, không chỉ quyền xem. */
    public function canExport(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::EXPORT_DATA);
    }

    public function mount(): void
    {
        $this->date = now()->startOfWeek()->toDateString();
    }

    /**
     * Thuộc tính public do trình duyệt gửi lên: chế độ, kỳ (chỉ kỳ có trong dropdown) và số dòng phải hợp lệ,
     * nếu không ngày sai định dạng làm lỗi 500, kỳ tương lai/1970 mở ra ngoài dropdown, limit âm làm hỏng danh sách.
     */
    private function normalize(): void
    {
        if (! in_array($this->mode, ['week', 'month'], true)) {
            $this->mode = 'week';
        }
        if (! array_key_exists($this->date, $this->periods)) {
            $this->date = (string) array_key_first($this->periods);
        }
        $this->limit = max(self::PAGE_SIZE, min($this->limit, 5000));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function getRangeProperty(): array
    {
        $this->normalize();
        $anchor = Carbon::parse($this->date);

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

        $all = $this->driverRows($from, $to);
        $totals = $this->totals($all);
        $visible = $this->filterAndSort($all);
        $cash = $this->cashCollected($from, $to);

        return [
            'from' => $from, 'to' => $to,
            'totals' => $totals,
            'cash' => $cash,
            // Từ toàn bộ kỳ (không phụ thuộc bộ lọc, sắp xếp hay số dòng đang hiện).
            'top_debtor' => collect($all)->where('remaining', '>', 0)->sortByDesc('remaining')->first(),
            'overall' => $this->outstanding(),
            'pending_withdraw' => $this->pendingWithdraw(),
            'adjust' => $this->walletAdjustments($from, $to),
            'chart' => $this->trend($from),
            'drivers' => array_slice($visible, 0, $this->limit),
            'visible_count' => count($visible),
            'has_more' => count($visible) > $this->limit,
        ];
    }

    public function export(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);
        [$from, $to] = $this->range;
        // Một lần đọc cho cả dòng chi tiết lẫn dòng tổng: tổng luôn khớp các dòng trong cùng file.
        $all = $this->driverRows($from, $to);
        $rows = $this->filterAndSort($all);
        $totals = $this->totals($all);

        $cols = ['fee_due', 'penalty', 'other', 'total_due', 'total_paid', 'waived', 'remaining', 'cod_due', 'cod_remaining',
            'score_bonus', 'order_paid', 'referral', 'admin_paid', 'withdrawn'];
        $data = [[
            'Mã TX', 'Tài xế', 'SĐT',
            'Phí (đ)', 'Phạt (đ)', 'Khoản khác (đ)', 'Phải thu gốc (đ)', 'Đã thu (đ)', 'Miễn giảm (đ)', 'Còn nợ (đ)',
            'Thu hộ COD (đ)', 'COD còn giữ (đ)',
            'Thưởng điểm (đ)', 'Bù theo đơn: freeship/bonus/voucher/mưa (đ)', 'Thưởng giới thiệu (đ)', 'Công ty đã chi (đ)', 'Đã rút (đ)',
        ]];
        foreach ($rows as $r) {
            $data[] = Csv::row(array_merge([$r['id'], $r['name'], $r['phone']], array_map(fn ($c) => $r[$c], $cols)));
        }
        $data[] = array_merge(['', 'TỔNG CỘNG (toàn khu vực)', ''], array_map(fn ($c) => $totals[$c], $cols));

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
    private const DEBT_PERIOD_SQL = 'COALESCE(dd.week_start, dd.date, DATE(dd.created_at))';

    /** Phân loại theo loại nợ và mã tham chiếu (không theo chữ ghi chú). COD là tiền của khách tài xế giữ hộ, không phải phí. */
    // debt_type có thể NULL ở dữ liệu cũ (từng có migration bỏ cột): coi như phí tuần, và không để NULL lọt khỏi mọi nhóm.
    private const IS_COD = "COALESCE(dd.debt_type, 'weekly') = 'cod'";

    // COALESCE: ref_id NULL (phí tuần) phải cho ra FALSE, không phải NULL — NOT NULL trong CASE sẽ loại luôn khoản phí.
    private const IS_PENALTY = "(COALESCE(dd.ref_id, '') LIKE 'score\\_penalty%' OR COALESCE(dd.ref_id, '') LIKE 'shift\\_offline\\_%')";

    private const IS_FEE = "(COALESCE(dd.debt_type, 'weekly') IN ('weekly', 'commission') AND (dd.ref_id IS NULL OR dd.ref_id LIKE 'daily\\_commission\\_%'))";

    /** Số đã miễn của một khoản nợ (waive() hạ amount_due về số đã thu và ghi phần miễn vào nhật ký). */
    private const WAIVED_SQL = 'COALESCE(wv.amount, 0)';

    private function cityId(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    /**
     * Nợ phát sinh ở khu vực (driver_debts.city_id — tài xế đổi khu vực thì nợ cũ vẫn thuộc khu vực cũ). Khoản chưa có
     * city_id thì dựa vào khu vực hiện tại của tài xế. Không có tenant thì rỗng — không bao giờ "không lọc".
     * Gồm cả tài khoản đã khóa/ngừng (status 0) vì nợ vẫn phải thu.
     */
    private function debtQuery()
    {
        $city = $this->cityId();

        return DB::table('driver_debts as dd')
            ->join('users as u', 'u.id', '=', 'dd.driver_id')
            ->where('u.user_type', 'driver')
            ->where(fn ($q) => $q->where('dd.city_id', $city)->orWhere(fn ($q) => $q->whereNull('dd.city_id')->where('u.city_id', $city)))
            ->leftJoinSub(
                DB::table('driver_debt_logs')->where('action', 'waived')->groupBy('debt_id')->selectRaw('debt_id, SUM(amount) as amount'),
                'wv', 'wv.debt_id', '=', 'dd.id'
            );
    }

    private function periodDebts(Carbon $from, Carbon $to)
    {
        return $this->debtQuery()->whereRaw(self::DEBT_PERIOD_SQL.' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()]);
    }

    private function walletQuery()
    {
        return DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.driver_id')
            ->where('u.city_id', $this->cityId());
    }

    private function driverRows(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString().' 00:00:00';
        $toStr = $to->toDateString().' 23:59:59';
        $orig = '(dd.amount_due + '.self::WAIVED_SQL.')';
        $notCod = 'NOT '.self::IS_COD;

        // Phải thu gốc = đã thu + miễn giảm + còn nợ (miễn giảm tách riêng để không che mất khoản thất thu).
        $debts = $this->periodDebts($from, $to)
            ->groupBy('dd.driver_id')
            ->selectRaw('dd.driver_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod AND ".self::IS_FEE." AND NOT ".self::IS_PENALTY." THEN $orig ELSE 0 END), 0) as fee_due")
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod AND ".self::IS_PENALTY." THEN $orig ELSE 0 END), 0) as penalty")
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod THEN $orig ELSE 0 END), 0) as total_due")
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod THEN dd.amount_paid ELSE 0 END), 0) as total_paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod THEN ".self::WAIVED_SQL." ELSE 0 END), 0) as waived")
            ->selectRaw("COALESCE(SUM(CASE WHEN $notCod THEN GREATEST(dd.amount_due - dd.amount_paid, 0) ELSE 0 END), 0) as remaining")
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::IS_COD.' THEN dd.amount_due ELSE 0 END), 0) as cod_due')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::IS_COD.' THEN GREATEST(dd.amount_due - dd.amount_paid, 0) ELSE 0 END), 0) as cod_remaining')
            ->get()->keyBy('driver_id');

        // Tiền công ty ghi có cho tài xế trong kỳ: mọi khoản theo đơn (freeship, bonus, voucher, mưa), thưởng điểm, thưởng giới thiệu.
        // Điều chỉnh tay (admin_adj_) không tính — hiện riêng bên dưới.
        $paid = $this->walletQuery()
            ->where('t.type', 'credit')
            ->whereBetween('t.created_at', [$fromStr, $toStr])
            ->where(fn ($q) => $q->where('t.reference', 'like', 'order\_%')->orWhere('t.reference', 'like', 'score\_bonus\_%')->orWhere('t.reference', 'like', 'referral\_%'))
            ->groupBy('w.driver_id')
            ->selectRaw("w.driver_id,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'score\\\\_bonus\\\\_%' THEN t.amount ELSE 0 END), 0) as score_bonus,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'order\\\\_%' THEN t.amount ELSE 0 END), 0) as order_paid,
                COALESCE(SUM(CASE WHEN t.reference LIKE 'referral\\\\_%' THEN t.amount ELSE 0 END), 0) as referral")
            ->get()->keyBy('driver_id');

        $withdrawals = DB::table('withdraw_requests as wr')
            ->join('users as u', 'u.id', '=', 'wr.driver_id')->where('u.city_id', $this->cityId())
            ->where('wr.status', 'approved')
            ->whereBetween(DB::raw('COALESCE(wr.processed_at, wr.updated_at)'), [$fromStr, $toStr])
            ->groupBy('wr.driver_id')->selectRaw('wr.driver_id, COALESCE(SUM(wr.amount), 0) as total')->pluck('total', 'driver_id');

        $ids = collect([$debts->keys(), $paid->keys(), $withdrawals->keys()])->flatten()->unique()->values()->all();
        if (! $ids) {
            return [];
        }
        $drivers = DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'phone']);

        $rows = [];
        foreach ($drivers as $d) {
            $debt = $debts->get($d->id);
            $pay = $paid->get($d->id);
            $n = fn ($v) => (int) round((float) ($v ?? 0));
            $feeDue = $n($debt->fee_due ?? 0);
            $penalty = $n($debt->penalty ?? 0);
            $totalDue = $n($debt->total_due ?? 0);
            $score = $n($pay->score_bonus ?? 0);
            $orderPaid = $n($pay->order_paid ?? 0);
            $referral = $n($pay->referral ?? 0);

            $rows[] = [
                'id' => $d->id, 'name' => (string) $d->name, 'phone' => (string) $d->phone,
                'fee_due' => $feeDue, 'penalty' => $penalty, 'other' => max(0, $totalDue - $feeDue - $penalty),
                'total_due' => $totalDue, 'total_paid' => $n($debt->total_paid ?? 0), 'waived' => $n($debt->waived ?? 0),
                'remaining' => $n($debt->remaining ?? 0),
                'cod_due' => $n($debt->cod_due ?? 0), 'cod_remaining' => $n($debt->cod_remaining ?? 0),
                'score_bonus' => $score, 'order_paid' => $orderPaid, 'referral' => $referral,
                'admin_paid' => $score + $orderPaid + $referral,
                'withdrawn' => $n($withdrawals[$d->id] ?? 0),
            ];
        }

        return $rows;
    }

    private function totals(array $rows): array
    {
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $due = $sum('total_due');

        return [
            // Chỉ tài xế có khoản phải thu trong kỳ (không tính người chỉ có rút tiền/được thưởng).
            'drivers' => count(array_filter($rows, fn ($r) => $r['total_due'] > 0)),
            'debtors' => count(array_filter($rows, fn ($r) => $r['remaining'] > 0)),
            'fee_due' => $sum('fee_due'), 'penalty' => $sum('penalty'), 'other' => $sum('other'),
            'total_due' => $due, 'total_paid' => $sum('total_paid'), 'waived' => $sum('waived'), 'remaining' => $sum('remaining'),
            'collect_rate' => $due > 0 ? round(min($sum('total_paid'), $due) / $due * 100, 1) : null,
            'cod_due' => $sum('cod_due'), 'cod_remaining' => $sum('cod_remaining'),
            'score_bonus' => $sum('score_bonus'), 'order_paid' => $sum('order_paid'), 'referral' => $sum('referral'),
            'admin_paid' => $sum('admin_paid'),
            'withdrawn' => $sum('withdrawn'),
        ];
    }

    /**
     * Tiền thật sự thu trong kỳ (kể cả nợ của kỳ trước), từ nhật ký thu nợ — để đối soát với tiền về tài khoản.
     * Nhật ký chỉ có từ khi tính năng được thêm: kỳ bắt đầu trước lần thu đầu tiên có log thì đánh dấu chưa đủ dữ liệu.
     */
    private function cashCollected(Carbon $from, Carbon $to): array
    {
        $paidActions = ['paid_payos', 'paid_wallet', 'paid_manual'];
        $first = DB::table('driver_debt_logs')->whereIn('action', $paidActions)->min('created_at');
        $city = $this->cityId();
        $amount = DB::table('driver_debt_logs as l')
            ->join('driver_debts as dd', 'dd.id', '=', 'l.debt_id')
            ->join('users as u', 'u.id', '=', 'dd.driver_id')
            ->where(fn ($q) => $q->where('dd.city_id', $city)->orWhere(fn ($q) => $q->whereNull('dd.city_id')->where('u.city_id', $city)))
            ->whereRaw('NOT '.self::IS_COD)
            ->whereIn('l.action', $paidActions)
            ->whereBetween('l.created_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])
            ->sum('l.amount');

        return [
            'amount' => (int) round((float) $amount),
            'partial' => $first === null || Carbon::parse($first)->gt($from),
            'since' => $first ? Carbon::parse($first) : null,
        ];
    }

    /** Nợ chưa thu của MỌI kỳ (kể cả kỳ cũ không nằm trong khoảng đang xem), không gồm COD. */
    private function outstanding(): array
    {
        $row = $this->debtQuery()->whereRaw('NOT '.self::IS_COD)->whereColumn('dd.amount_due', '>', 'dd.amount_paid')
            ->selectRaw('COALESCE(SUM(dd.amount_due - dd.amount_paid), 0) as amount, COUNT(*) as items, COUNT(DISTINCT dd.driver_id) as drivers, MIN('.self::DEBT_PERIOD_SQL.') as oldest')->first();

        return [
            'amount' => (int) round((float) ($row->amount ?? 0)), 'items' => (int) ($row->items ?? 0),
            'drivers' => (int) ($row->drivers ?? 0), 'oldest' => ($row->oldest ?? null) ? Carbon::parse($row->oldest) : null,
        ];
    }

    /** Tiền tài xế đã yêu cầu rút nhưng chưa duyệt (mọi thời điểm) — việc đang chờ admin. */
    private function pendingWithdraw(): array
    {
        $row = DB::table('withdraw_requests as wr')->join('users as u', 'u.id', '=', 'wr.driver_id')
            ->where('u.city_id', $this->cityId())->where('wr.status', 'pending')
            ->selectRaw('COALESCE(SUM(wr.amount), 0) as amount, COUNT(*) as items')->first();

        return ['amount' => (int) ($row->amount ?? 0), 'items' => (int) ($row->items ?? 0)];
    }

    /** Điều chỉnh ví thủ công của admin: chỉ hiển thị tham khảo, không tính vào "Công ty đã chi". */
    private function walletAdjustments(Carbon $from, Carbon $to): array
    {
        $rows = $this->walletQuery()->where('t.reference', 'like', 'admin\_adj%')
            ->whereBetween('t.created_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])
            ->groupBy('t.type')->selectRaw('t.type, COALESCE(SUM(t.amount), 0) as amount')->pluck('amount', 'type');

        return ['credit' => (int) ($rows['credit'] ?? 0), 'debit' => (int) ($rows['debit'] ?? 0)];
    }

    /** Xu hướng thu phí: 8 kỳ gần nhất kết thúc ở kỳ đang xem. Cột = đã thu, nét đứt = phải thu gốc, đường = còn nợ. */
    private function trend(Carbon $anchorFrom): array
    {
        $gran = $this->mode === 'month' ? 'month' : 'week';
        $start = ($gran === 'month' ? $anchorFrom->copy()->subMonthsNoOverflow(self::TREND_PERIODS - 1)->startOfMonth() : $anchorFrom->copy()->subWeeks(self::TREND_PERIODS - 1));
        $end = $this->range[1];
        $buckets = $this->buckets($start, $end, $gran);

        $data = $this->periodDebts($start, $end)->whereRaw('NOT '.self::IS_COD)
            ->selectRaw($this->bucketSql(self::DEBT_PERIOD_SQL, $gran).' as k')
            ->selectRaw('COALESCE(SUM(dd.amount_due + '.self::WAIVED_SQL.'), 0) as due, COALESCE(SUM(dd.amount_paid), 0) as paid, COALESCE(SUM(GREATEST(dd.amount_due - dd.amount_paid, 0)), 0) as remaining')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k);

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
            // "Đã hoàn tất" = có khoản phải thu trong kỳ và đã hết nợ (không gồm người chỉ có rút tiền/được thưởng).
            if ($this->debtStatus === 'settled' && ($row['remaining'] > 0 || $row['total_due'] <= 0)) {
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
