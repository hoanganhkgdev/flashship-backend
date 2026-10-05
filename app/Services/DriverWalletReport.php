<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Số liệu và phân loại cho trang Ví tài xế. */
class DriverWalletReport
{
    /** Nhóm giao dịch theo mã tham chiếu (reference): [nhãn, mẫu LIKE]. Thứ tự quan trọng, khớp đầu tiên thắng. */
    private const CATEGORIES = [
        'rain' => ['Thưởng trời mưa', 'order\_%\_rain'],
        'discount' => ['Bù giảm giá', 'order\_%\_discount'],
        'shipping' => ['Phí giao (freeship)', 'order\_%\_shipping'],
        'bonus' => ['Thưởng đơn', 'order\_%\_bonus'],
        'score_bonus' => ['Thưởng điểm tuần', 'score\_bonus\_%'],
        'score_penalty' => ['Phạt điểm tuần', 'score\_penalty\_%'],
        'withdraw_hold' => ['Giữ tiền rút', 'withdraw\_hold\_%'],
        'withdraw_refund' => ['Hoàn tiền rút', 'withdraw\_refund\_%'],
        'withdraw' => ['Rút tiền', 'withdraw\_%'],
        'debt' => ['Thanh toán công nợ', 'debt\_%'],
        'referral' => ['Thưởng giới thiệu', 'referral\_%'],
        'refund_weekly' => ['Hoàn phí tuần', 'refund\_weekly\_%'],
        'payos' => ['Nạp qua PayOS', 'payos\_%'],
        'admin' => ['Admin điều chỉnh', 'admin\_adj\_%'],
    ];

    public static function categoryLabels(): array
    {
        return collect(self::CATEGORIES)->map(fn ($c) => $c[0])->all() + ['other' => 'Khác'];
    }

    /** Biểu thức SQL trả về khoá nhóm của một giao dịch (cột $col chứa reference). */
    public static function categorySql(string $col = 'reference'): string
    {
        $cases = collect(self::CATEGORIES)->map(fn ($c, $k) => "WHEN {$col} LIKE '{$c[1]}' THEN '{$k}'")->implode(' ');

        return "CASE {$cases} ELSE 'other' END";
    }

    public static function categoryOf(?string $reference): string
    {
        foreach (self::CATEGORIES as $key => [, $like]) {
            $regex = '/^'.str_replace('%', '.*', preg_quote(str_replace('\\_', '_', $like), '/')).'$/';
            if ($reference !== null && preg_match($regex, $reference)) {
                return $key;
            }
        }

        return 'other';
    }

    private static function wallets(?int $cityId)
    {
        return DB::table('driver_wallets as w')->join('users as u', 'u.id', '=', 'w.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId));
    }

    public static function summary(?int $cityId): array
    {
        $byStatus = self::wallets($cityId)->selectRaw('u.status, COUNT(*) c, COALESCE(SUM(w.balance),0) s')->groupBy('u.status')->get()->keyBy('status');

        $today = DB::table('driver_wallet_transactions as t')->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.driver_id')->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))
            ->whereDate('t.created_at', now()->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type='credit' THEN t.amount END),0) cin, COALESCE(SUM(CASE WHEN t.type='debit' THEN t.amount END),0) cout")->first();

        $pending = DB::table('withdraw_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))->where('r.status', 'pending')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(r.amount),0) s')->first();

        $recon = self::reconciliation($cityId);

        return [
            'activeCount' => (int) ($byStatus[1]->c ?? 0), 'activeMoney' => (int) ($byStatus[1]->s ?? 0),
            'lockedCount' => (int) ($byStatus[2]->c ?? 0), 'lockedMoney' => (int) ($byStatus[2]->s ?? 0),
            'lockedWithMoney' => (int) self::wallets($cityId)->where('u.status', 2)->where('w.balance', '>', 0)->count(),
            'pendingCount' => (int) $pending->c, 'pendingMoney' => (int) $pending->s,
            'in' => (int) $today->cin, 'out' => (int) $today->cout,
            'recon' => $recon,
        ];
    }

    /** Số dư từng ví phải bằng tổng cộng − tổng trừ của giao dịch. */
    public static function reconciliation(?int $cityId): array
    {
        $rows = self::wallets($cityId)
            ->leftJoin('driver_wallet_transactions as t', 't.wallet_id', '=', 'w.id')
            ->groupBy('w.id', 'w.balance', 'w.driver_id')
            ->selectRaw("w.id, w.driver_id, w.balance, COALESCE(SUM(CASE WHEN t.type='credit' THEN t.amount ELSE -t.amount END),0) calc")
            ->get();

        $bad = $rows->filter(fn ($r) => abs($r->balance - $r->calc) > 0.5)->values();

        return ['total' => $rows->count(), 'bad' => $bad->count(), 'sample' => $bad->take(5)->pluck('driver_id')->all()];
    }

    /** Dòng tiền theo nguồn trong N ngày. */
    public static function cashflow(?int $cityId, int $days = 30): Collection
    {
        $labels = self::categoryLabels();

        return DB::table('driver_wallet_transactions as t')->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.driver_id')->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))
            ->where('t.created_at', '>=', now()->subDays($days))
            ->selectRaw(self::categorySql('t.reference')." k, COALESCE(SUM(CASE WHEN t.type='credit' THEN t.amount END),0) cin, COALESCE(SUM(CASE WHEN t.type='debit' THEN t.amount END),0) cout, COUNT(*) n")
            ->groupBy('k')->get()
            ->map(fn ($r) => ['key' => $r->k, 'label' => $labels[$r->k] ?? 'Khác', 'in' => (int) $r->cin, 'out' => (int) $r->cout, 'n' => (int) $r->n])
            ->sortByDesc(fn ($r) => $r['in'] + $r['out'])->values();
    }

    public static function recentAdminAdjustments(?int $cityId, int $limit = 8): Collection
    {
        return DB::table('driver_wallet_transactions as t')->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.driver_id')->leftJoin('users as a', 'a.id', '=', 't.performed_by')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))
            ->where('t.reference', 'like', 'admin\_adj\_%')->orderByDesc('t.id')->limit($limit)
            ->get(['t.type', 't.amount', 't.description', 't.created_at', 'u.name as driver', 'a.name as admin', 'w.driver_id']);
    }
}
