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

    public static function needsAction(Order $o): bool
    {
        return $o->driver_rating !== null && $o->driver_rating <= self::LOW && ! $o->rating_handled_at && ! $o->rating_hidden;
    }

    public static function handle(Order $o, string $note, ?int $by): void
    {
        $o->update(['rating_handled_at' => now(), 'rating_handled_by' => $by, 'rating_handle_note' => $note]);
    }

    public static function hide(Order $o, string $reason, ?int $by): void
    {
        $o->update(['rating_hidden' => true, 'rating_handled_at' => now(), 'rating_handled_by' => $by, 'rating_handle_note' => 'Ẩn khỏi thống kê: '.$reason]);
    }

    public static function unhide(Order $o): void
    {
        $o->update(['rating_hidden' => false, 'rating_handled_at' => null, 'rating_handled_by' => null, 'rating_handle_note' => null]);
    }

    private static function base(?int $cityId)
    {
        return DB::table('orders')->whereNotNull('driver_rating')->where('rating_hidden', false)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId));
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
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();

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
