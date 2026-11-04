<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;

/** Xử lý và số liệu đánh giá tài xế. Đánh giá không bao giờ bị xóa: chỉ đánh dấu đã xử lý hoặc ẩn khỏi thống kê. */
class DriverRatingService
{
    /** Từ mức sao này trở xuống thì cần xử lý. */
    public const LOW = 2;

    /** Tài xế phải có ít nhất bấy nhiêu lượt đánh giá mới được xếp vào danh sách cần chú ý. */
    public const MIN_REVIEWS = 2;

    public const LOG_ENTITY = 'driver_rating';

    public const LOG_LABELS = ['handled' => 'Đã xử lý', 'hidden' => 'Ẩn khỏi thống kê', 'unhidden' => 'Hiện lại'];

    public static function needsAction(Order $o): bool
    {
        return $o->driver_rating !== null && $o->driver_rating <= self::LOW && ! $o->rating_handled_at && ! $o->rating_hidden;
    }

    private static function log(int $orderId, string $action, ?int $by, ?string $detail): void
    {
        DB::table('config_logs')->insert(['entity' => self::LOG_ENTITY, 'entity_id' => $orderId, 'action' => $action, 'performed_by' => $by, 'detail' => $detail, 'created_at' => now()]);
    }

    /** Ghi kết quả xử lý. Chỉ khi vẫn cần xử lý: hai người cùng bấm thì ghi chú sau không đè ghi chú trước. */
    public static function handle(Order $o, string $note, ?int $by): bool
    {
        $ok = Order::whereKey($o->id)->whereNull('rating_handled_at')->where('rating_hidden', false)
            ->update(['rating_handled_at' => now(), 'rating_handled_by' => $by, 'rating_handle_note' => $note]) > 0;
        if ($ok) {
            self::log($o->id, 'handled', $by, $note);
        }

        return $ok;
    }

    /**
     * Ẩn khỏi thống kê (điểm trung bình trang này và app tài xế). Không đụng tới phần xử lý: ghi chú xử lý cũ còn nguyên.
     * Ai ẩn, lúc nào, vì sao nằm trong nhật ký.
     */
    public static function hide(Order $o, string $reason, ?int $by): bool
    {
        $ok = Order::whereKey($o->id)->where('rating_hidden', false)->update(['rating_hidden' => true]) > 0;
        if ($ok) {
            self::log($o->id, 'hidden', $by, $reason);
        }

        return $ok;
    }

    public static function unhide(Order $o, ?int $by = null): bool
    {
        $ok = Order::whereKey($o->id)->where('rating_hidden', true)->update(['rating_hidden' => false]) > 0;
        if ($ok) {
            self::log($o->id, 'unhidden', $by, null);
        }

        return $ok;
    }

    /** Nhật ký xử lý / ẩn / hiện lại của một đánh giá, mới nhất trước. */
    public static function logs(int $orderId): Collection
    {
        return DB::table('config_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.performed_by')
            ->where('l.entity', self::LOG_ENTITY)->where('l.entity_id', $orderId)->orderByDesc('l.id')
            ->get(['l.action', 'l.detail', 'l.created_at', 'u.name as by']);
    }

    /** Tài xế của đánh giá: còn tài khoản, đã bị xóa nhưng có bản lưu tên, hoặc không rõ (xóa trước khi có bản lưu). */
    public static function driverLabel(Order $o): array
    {
        if ($o->driver) {
            return ['name' => $o->driver->name, 'phone' => $o->driver->phone, 'deleted' => false];
        }

        return ['name' => $o->driver_name ?: 'Tài xế đã bị xóa', 'phone' => $o->driver_phone, 'deleted' => true];
    }

    private static function base(?int $cityId)
    {
        // Không có khu vực thì không có đánh giá nào, không bao giờ là "mọi khu vực".
        return DB::table('orders')->whereNotNull('driver_rating')->where('rating_hidden', false)
            ->where('city_id', (int) $cityId);
    }

    /** @return array{count:int,avg:float,rate:float,prevAvg:?float,prevCount:int,pending:int,dist:array<int,int>,days:int} */
    public static function summary(?int $cityId, int $days = 30): array
    {
        $from = now()->subDays($days);
        $prevFrom = now()->subDays($days * 2);

        $cur = self::base($cityId)->where('completed_at', '>=', $from)
            ->selectRaw('COUNT(*) c, AVG(driver_rating) a')->first();
        $prev = self::base($cityId)->where('completed_at', '>=', $prevFrom)->where('completed_at', '<', $from)
            ->selectRaw('COUNT(*) c, AVG(driver_rating) a')->first();

        $completed = DB::table('orders')->where('status', 'completed')->where('completed_at', '>=', $from)
            ->where('city_id', (int) $cityId)->count();

        $dist = self::base($cityId)->where('completed_at', '>=', $from)
            ->selectRaw('driver_rating r, COUNT(*) c')->groupBy('driver_rating')->pluck('c', 'r');

        return [
            'count' => (int) $cur->c,
            'avg' => $cur->c ? round((float) $cur->a, 2) : 0.0,
            'rate' => $completed ? round($cur->c / $completed * 100, 1) : 0.0,
            'prevAvg' => $prev->c ? round((float) $prev->a, 2) : null,
            'prevCount' => (int) $prev->c,
            'pending' => self::pendingCount($cityId),
            'dist' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [$s => (int) ($dist[$s] ?? 0)])->all(),
            'days' => $days,
        ];
    }

    public static function pendingCount(?int $cityId): int
    {
        return self::base($cityId)->where('driver_rating', '<=', self::LOW)->whereNull('rating_handled_at')->count();
    }

    /** Tài xế có điểm trung bình thấp nhất trong kỳ (đủ số lượt đánh giá tối thiểu). */
    public static function watchList(?int $cityId, int $days = 90, int $limit = 5): Collection
    {
        return self::base($cityId)->whereNotNull('delivery_man_id')->where('completed_at', '>=', now()->subDays($days))
            ->selectRaw('delivery_man_id, COUNT(*) c, AVG(driver_rating) a, SUM(driver_rating <= ?) low', [self::LOW])
            ->groupBy('delivery_man_id')->havingRaw('COUNT(*) >= ?', [self::MIN_REVIEWS])
            ->orderBy('a')->orderByDesc('c')->limit($limit)->get()
            ->pipe(function ($rows) {
                $users = DB::table('users')->whereIn('id', $rows->pluck('delivery_man_id'))->pluck('name', 'id');

                return $rows->map(fn ($r) => [
                    'id' => (int) $r->delivery_man_id,
                    'name' => $users[$r->delivery_man_id] ?? '—',
                    'count' => (int) $r->c,
                    'avg' => round((float) $r->a, 1),
                    'low' => (int) $r->low,
                ]);
            });
    }
}
