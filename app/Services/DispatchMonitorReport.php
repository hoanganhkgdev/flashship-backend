<?php

namespace App\Services;

use App\Support\OrderListPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

/**
 * Số liệu cho màn Theo dõi phát đơn. Mọi truy vấn gom về một chỗ để test được
 * và để trang chỉ lo hiển thị.
 */
class DispatchMonitorReport
{
    /** Số lượt hỏi tối thiểu để một tài xế được xét vào bảng "hay từ chối". */
    public const MIN_OFFERS_FOR_RANKING = 3;

    /**
     * Đơn đang trong quá trình phát, tách làm hai nhóm:
     *  - attention: hệ thống đã dừng tìm (no_driver) hoặc đã quá giới hạn mà chưa dừng — tổng đài phải xử lý;
     *  - active: đang phát bình thường.
     * Cả hai xếp đơn chờ lâu nhất lên trước.
     *
     * @return array{attention: array<int, array>, active: array<int, array>}
     */
    public function orders(?int $cityId, int $timeoutSecs, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $orders = Order::query()
            ->whereNotNull('dispatch_started_at')
            ->whereNull('delivery_man_id')
            ->where('status', 'pending')
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->with('sender:id,name,phone')
            ->orderBy('dispatch_started_at')
            ->limit(200)
            ->get();

        $driverNames = DB::table('users')
            ->whereIn('id', $orders->pluck('dispatching_to_driver_id')->filter())
            ->pluck('name', 'id');
        $services = ServiceType::pluck('label', 'key');

        $attention = [];
        $active = [];

        foreach ($orders as $o) {
            $elapsed = max(0, $now->getTimestamp() - $o->dispatch_started_at->getTimestamp());
            $kind = $o->cancel_reason === 'no_driver' ? 'no_driver' : ($elapsed > $timeoutSecs ? 'timeout' : 'active');
            $offeringTo = $o->dispatching_to_driver_id;

            $row = [
                'id' => $o->id,
                'code' => $o->code ?: $o->id,
                'service' => $services[$o->service_type] ?? $o->service_type,
                'pickup' => trim((string) $o->pickup_address) ?: '—',
                'delivery' => trim((string) $o->delivery_address) ?: 'Chưa có điểm giao',
                'note' => trim((string) $o->order_note),
                'fee' => (int) $o->shipping_fee,
                'customer' => OrderListPresenter::customerName($o),
                'phone' => OrderListPresenter::contactPhone($o),
                'elapsed' => $elapsed,
                'started_at' => $o->dispatch_started_at->getTimestamp(),
                'attempts' => (int) $o->dispatch_attempts,
                'offering_id' => $offeringTo,
                'offering_to' => $offeringTo ? ($driverNames[$offeringTo] ?? null) : null,
                'kind' => $kind,
            ];

            if ($kind === 'active') {
                $active[] = $row;
            } else {
                $attention[] = $row;
            }
        }

        return compact('attention', 'active');
    }

    /**
     * Thống kê phát đơn trong khoảng [$from, $to]. Dùng cùng một khoảng giờ cho hôm nay
     * và hôm qua để so sánh công bằng.
     *
     * @return array{total:int, accepted:int, accept_rate:int, first_try:int, first_try_rate:int, no_driver:int, avg_attempts:float, avg_wait_secs:int, max_wait_secs:int, avg_timeout_overshoot_secs:int, max_timeout_overshoot_secs:int, offer_fairness_rate:int}
     */
    public function window(CarbonInterface $from, CarbonInterface $to, ?int $cityId): array
    {
        $agg = DB::table('orders')
            ->whereBetween('dispatch_started_at', [$from, $to])
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->selectRaw('COUNT(*) as total,
                SUM(delivery_man_id IS NOT NULL) as accepted,
                SUM(delivery_man_id IS NOT NULL AND dispatch_attempts = 1) as first_try,
                SUM(cancel_reason = "no_driver") as no_driver,
                AVG(CASE WHEN delivery_man_id IS NOT NULL THEN dispatch_attempts END) as avg_attempts')
            ->first();

        // Thời gian chờ thật: từ lúc bắt đầu phát đến lúc tài xế bấm nhận (log accepted) —
        // không dùng orders.updated_at vì nó đổi theo mọi cập nhật.
        $wait = DB::table('orders as o')
            ->join('order_dispatch_logs as l', function ($j) {
                $j->on('l.order_id', '=', 'o.id')
                    ->on('l.driver_id', '=', 'o.delivery_man_id')
                    ->where('l.result', 'accepted');
            })
            ->whereBetween('o.dispatch_started_at', [$from, $to])
            ->when($cityId, fn ($query) => $query->where('o.city_id', $cityId))
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, o.dispatch_started_at, l.responded_at)) as avg_w, MAX(TIMESTAMPDIFF(SECOND, o.dispatch_started_at, l.responded_at)) as max_w')
            ->first();

        $timeout = DB::table('order_dispatch_logs as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereBetween('l.offered_at', [$from, $to])
            ->where('l.result', 'expired')
            ->whereNotNull('l.expires_at')
            ->whereNotNull('l.responded_at')
            ->when($cityId, fn ($query) => $query->where('o.city_id', $cityId))
            ->selectRaw('AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, l.expires_at, l.responded_at))) as avg_o,
                MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, l.expires_at, l.responded_at))) as max_o')
            ->first();

        $offersByDriver = DB::table('order_dispatch_logs as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereBetween('l.offered_at', [$from, $to])
            ->when($cityId, fn ($query) => $query->where('o.city_id', $cityId))
            ->selectRaw('l.driver_id, COUNT(*) as offers')
            ->groupBy('l.driver_id')
            ->pluck('offers');
        $offerSum = (int) $offersByDriver->sum();
        $offerSquares = (int) $offersByDriver->sum(fn ($offers) => $offers * $offers);
        $fairness = $offersByDriver->isEmpty() || $offerSquares === 0
            ? 100
            : (int) round(($offerSum * $offerSum) / ($offersByDriver->count() * $offerSquares) * 100);

        $total = (int) ($agg->total ?? 0);
        $accepted = (int) ($agg->accepted ?? 0);

        return [
            'total' => $total,
            'accepted' => $accepted,
            'accept_rate' => $total > 0 ? (int) round($accepted / $total * 100) : 0,
            'first_try' => (int) ($agg->first_try ?? 0),
            'first_try_rate' => $accepted > 0 ? (int) round(($agg->first_try ?? 0) / $accepted * 100) : 0,
            'no_driver' => (int) ($agg->no_driver ?? 0),
            'avg_attempts' => round((float) ($agg->avg_attempts ?? 0), 1),
            'avg_wait_secs' => (int) round((float) ($wait->avg_w ?? 0)),
            'max_wait_secs' => max(0, (int) ($wait->max_w ?? 0)),
            'avg_timeout_overshoot_secs' => max(0, (int) round((float) ($timeout->avg_o ?? 0))),
            'max_timeout_overshoot_secs' => max(0, (int) ($timeout->max_o ?? 0)),
            // Chỉ số Jain trên số lượt offer; dùng để so sánh trước/sau trong
            // cùng khu vực và khung giờ, không thay thế chuẩn hoá theo giờ online.
            'offer_fairness_rate' => $fairness,
        ];
    }

    /** Hôm nay đến giờ này, và hôm qua đến đúng giờ này. */
    public function todayVsYesterday(?int $cityId, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'today' => $this->window($now->copy()->startOfDay(), $now, $cityId),
            'yesterday' => $this->window($now->copy()->subDay()->startOfDay(), $now->copy()->subDay(), $cityId),
        ];
    }

    /**
     * Lượt offer gần đây, lọc theo kết quả (all|accepted|declined|expired|pending).
     *
     * @return array<int, array>
     */
    public function recentOffers(?int $cityId, string $result = 'all', int $limit = 30): array
    {
        return OrderDispatchLog::query()
            ->join('users', 'users.id', '=', 'order_dispatch_logs.driver_id')
            ->join('orders', 'orders.id', '=', 'order_dispatch_logs.order_id')
            ->when($cityId, fn ($query) => $query->where('orders.city_id', $cityId))
            ->when($result !== 'all', fn ($query) => $query->where('order_dispatch_logs.result', $result))
            ->orderByDesc('order_dispatch_logs.id')
            ->limit($limit)
            ->get([
                'order_dispatch_logs.id',
                'order_dispatch_logs.order_id',
                'order_dispatch_logs.driver_id',
                'orders.code as order_code',
                'users.name as driver_name',
                'order_dispatch_logs.offered_at',
                'order_dispatch_logs.responded_at',
                'order_dispatch_logs.result',
            ])
            ->map(function ($r) {
                $responseSecs = $r->responded_at
                    ? abs(strtotime($r->responded_at) - strtotime($r->offered_at))
                    : null;

                return [
                    'id' => $r->id,
                    'order_id' => $r->order_id,
                    'driver_id' => $r->driver_id,
                    'order_code' => $r->order_code ?: $r->order_id,
                    'driver_name' => $r->driver_name,
                    'offered_at' => date('H:i:s d/m', strtotime($r->offered_at)),
                    'result' => $r->result,
                    'response_sec' => $responseSecs,
                ];
            })
            ->all();
    }

    /**
     * Tài xế hay bỏ offer trong ngày: xếp theo tỷ lệ từ chối, chỉ xét người có ít nhất
     * MIN_OFFERS_FOR_RANKING lượt hỏi để không bị lệch bởi mẫu quá nhỏ.
     *
     * @return array<int, array{driver_id:int, name:string, offers:int, declined:int, expired:int, accepted:int, decline_rate:int}>
     */
    public function topDecliners(?int $cityId, ?CarbonInterface $now = null, int $limit = 5): array
    {
        $now ??= now();

        return DB::table('order_dispatch_logs as l')
            ->join('users as u', 'u.id', '=', 'l.driver_id')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereBetween('l.offered_at', [$now->copy()->startOfDay(), $now])
            ->when($cityId, fn ($query) => $query->where('o.city_id', $cityId))
            ->groupBy('l.driver_id', 'u.name')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_OFFERS_FOR_RANKING])
            ->havingRaw('SUM(l.result = "declined") > 0')
            ->selectRaw('l.driver_id, u.name, COUNT(*) as offers,
                SUM(l.result = "declined") as declined,
                SUM(l.result = "expired") as expired,
                SUM(l.result = "accepted") as accepted')
            ->orderByRaw('SUM(l.result = "declined") / COUNT(*) DESC')
            ->orderByDesc('offers')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'driver_id' => (int) $r->driver_id,
                'name' => $r->name,
                'offers' => (int) $r->offers,
                'declined' => (int) $r->declined,
                'expired' => (int) $r->expired,
                'accepted' => (int) $r->accepted,
                'decline_rate' => (int) round($r->declined / $r->offers * 100),
            ])
            ->all();
    }

    /**
     * Số đơn phát và số đơn đã có tài xế theo từng giờ trong ngày.
     *
     * @return array<int, array{hour:int, total:int, accepted:int}> đủ 24 phần tử, theo thứ tự giờ
     */
    public function hourly(?int $cityId, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $rows = DB::table('orders')
            ->whereBetween('dispatch_started_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->selectRaw('HOUR(dispatch_started_at) as h, COUNT(*) as total, SUM(delivery_man_id IS NOT NULL) as accepted')
            ->groupBy('h')
            ->get()
            ->keyBy('h');

        return collect(range(0, 23))->map(fn (int $h) => [
            'hour' => $h,
            'total' => (int) ($rows[$h]->total ?? 0),
            'accepted' => (int) ($rows[$h]->accepted ?? 0),
        ])->all();
    }
}
