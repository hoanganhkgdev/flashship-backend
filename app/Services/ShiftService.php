<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Shift;
use Modules\Core\Models\ShiftLog;

/** Kiểm tra, ghi nhật ký và số liệu phủ ca. */
class ShiftService
{
    /** Từ mức này trở lên (đơn/tài xế/ngày) thì ca bị coi là quá tải. */
    public const OVERLOAD_PER_DRIVER = 8;

    public const DEMAND_DAYS = 14;

    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** Các đoạn [bắt đầu, kết thúc) tính theo phút trong ngày; ca qua nửa đêm tách làm hai. */
    public static function segments(string $start, string $end): array
    {
        $s = self::minutes($start);
        $e = self::minutes($end);

        return $e > $s ? [[$s, $e]] : [[$s, 1440], [0, $e]];
    }

    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        foreach (self::segments($startA, $endA) as [$a1, $a2]) {
            foreach (self::segments($startB, $endB) as [$b1, $b2]) {
                if ($a1 < $b2 && $b1 < $a2) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Ca đang kích hoạt cùng khu vực mà giờ này đang trùng, null nếu không trùng. */
    public static function conflictingShift(int $cityId, string $start, string $end, ?int $ignoreId = null): ?Shift
    {
        return Shift::where('city_id', $cityId)->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get()
            ->first(fn (Shift $s) => self::overlaps($start, $end, $s->start_time, $s->end_time));
    }

    /** Ca đang diễn ra thì không được đổi giờ (hệ thống chấm điểm dùng giờ ca hiện tại). */
    public static function inProgress(Shift $shift): bool
    {
        return $shift->is_active && $shift->isNowInShift();
    }

    public static function log(int $shiftId, string $action, ?int $by, ?string $detail = null): void
    {
        ShiftLog::create(['shift_id' => $shiftId, 'action' => $action, 'performed_by' => $by, 'detail' => $detail]);
    }

    public static function deactivate(Shift $shift, ?int $by): int
    {
        $affected = $shift->users()->where('users.status', 1)->count();
        $shift->update(['is_active' => false]);
        self::log($shift->id, 'deactivated', $by, $affected.' tài xế hoạt động đang đăng ký');

        return $affected;
    }

    public static function activate(Shift $shift, ?int $by): ?string
    {
        if ($c = self::conflictingShift($shift->city_id, $shift->start_time, $shift->end_time, $shift->id)) {
            return 'Không bật được: trùng giờ với "'.$c->name.'" ('.substr($c->start_time, 0, 5).'–'.substr($c->end_time, 0, 5).').';
        }
        $shift->update(['is_active' => true]);
        self::log($shift->id, 'activated', $by);

        return null;
    }

    private static function timeWhere($q, Shift $s, string $col = 'completed_at')
    {
        $start = substr($s->start_time, 0, 8);
        $end = substr($s->end_time, 0, 8);

        return $end > $start
            ? $q->whereRaw("TIME($col) >= ? AND TIME($col) < ?", [$start, $end])
            : $q->whereRaw("(TIME($col) >= ? OR TIME($col) < ?)", [$start, $end]);
    }

    /** Số liệu phủ ca của khu vực: mỗi ca một dòng + khung giờ không có ca. */
    public static function coverage(int $cityId): array
    {
        $shifts = Shift::where('city_id', $cityId)->orderBy('start_time')->get();
        $days = self::DEMAND_DAYS;
        $from = now()->subDays($days);

        $orders = fn () => DB::table('orders')->where('city_id', $cityId)->where('status', 'completed')->where('completed_at', '>=', $from);

        $reg = DB::table('shift_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->whereIn('su.shift_id', $shifts->pluck('id'))
            ->groupBy('su.shift_id')
            ->selectRaw('su.shift_id, SUM(u.status = 1) active, SUM(u.status = 2) locked, SUM(u.status = 1 AND u.is_online = 1) online')
            ->get()->keyBy('shift_id');

        // Kết quả chấm điểm cuối ca của tài xế đang hoạt động: lượt chấm có nhật ký điểm ngay sau đó.
        $scored = DB::table('driver_shift_score_runs as r')->join('users as u', fn ($j) => $j->on('u.id', '=', 'r.driver_id')->where('u.status', 1))
            ->join('driver_score_logs as l', fn ($j) => $j->on('l.driver_id', '=', 'r.driver_id')->where('l.reason', 'like', 'shift\_online%')
                ->whereRaw('l.created_at BETWEEN r.created_at AND DATE_ADD(r.created_at, INTERVAL 3 SECOND)'))
            ->where('r.created_at', '>=', $from)->whereIn('r.shift_id', $shifts->pluck('id'))
            ->groupBy('r.shift_id')
            ->selectRaw("r.shift_id, COUNT(*) n, SUM(l.reason = 'shift_online_critical') crit")->get()->keyBy('shift_id');

        $rows = $shifts->map(function (Shift $s) use ($reg, $scored, $orders, $days) {
            $r = $reg[$s->id] ?? null;
            $sc = $scored[$s->id] ?? null;
            $perDay = self::timeWhere($orders(), $s)->count() / $days;
            $active = (int) ($r->active ?? 0);

            return [
                'shift' => $s,
                'active' => $active,
                'locked' => (int) ($r->locked ?? 0),
                'online' => (int) ($r->online ?? 0),
                'perDay' => round($perDay, 1),
                'perDriver' => $active ? round($perDay / $active, 1) : null,
                'overloaded' => $s->is_active && ($active === 0 ? $perDay >= 1 : $perDay / $active >= self::OVERLOAD_PER_DRIVER),
                'critical' => $sc && $sc->n ? (int) round($sc->crit / $sc->n * 100) : null,
                'scored' => (int) ($sc->n ?? 0),
                'now' => $s->is_active && $s->isNowInShift(),
            ];
        });

        // Khung giờ không ca nào phủ (theo phút) và số đơn rơi vào đó.
        $cover = array_fill(0, 1440, false);
        foreach ($shifts->where('is_active', true) as $s) {
            foreach (self::segments($s->start_time, $s->end_time) as [$a, $b]) {
                for ($m = $a; $m < $b; $m++) {
                    $cover[$m] = true;
                }
            }
        }
        $gaps = [];
        for ($m = 0; $m < 1440; $m++) {
            if (! $cover[$m]) {
                $start = $m;
                while ($m < 1440 && ! $cover[$m]) {
                    $m++;
                }
                $gaps[] = [$start, $m];
            }
        }
        $fmt = fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
        $gapRows = collect($gaps)->map(function ($g) use ($orders, $fmt) {
            $from = sprintf('%02d:%02d:00', intdiv($g[0], 60), $g[0] % 60);
            $to = $g[1] >= 1440 ? '24:00:00' : sprintf('%02d:%02d:00', intdiv($g[1], 60), $g[1] % 60);
            $n = $orders()->whereRaw('TIME(completed_at) >= ?', [$from])->when($to !== '24:00:00', fn ($q) => $q->whereRaw('TIME(completed_at) < ?', [$to]))->count();

            return ['from' => $fmt($g[0]), 'to' => $fmt($g[1]), 'orders' => $n];
        });

        return ['rows' => $rows, 'gaps' => $gapRows, 'days' => $days];
    }

    public static function summary(int $cityId): array
    {
        $cov = self::coverage($cityId);
        $rows = $cov['rows']->where('shift.is_active', true);
        $activeDrivers = DB::table('users')->where('user_type', 'driver')->where('status', 1)->where('city_id', $cityId);
        $noShift = (clone $activeDrivers)->whereNotExists(fn ($q) => $q->from('shift_user')->whereColumn('shift_user.user_id', 'users.id'))->count();
        $busiest = $rows->filter(fn ($r) => $r['perDriver'] !== null)->sortByDesc('perDriver')->first();
        $current = $rows->firstWhere('now', true);

        return [
            'current' => $current,
            'activeDrivers' => (clone $activeDrivers)->count(),
            'noShift' => $noShift,
            'busiest' => $busiest,
            'pendingChanges' => DB::table('driver_shift_change_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
                ->where('u.city_id', $cityId)->where('r.status', 'pending')->count(),
            'coverage' => $cov,
        ];
    }

    /** Tài xế đã đăng ký ca, tài xế đang hoạt động trước. */
    public static function roster(Shift $shift): Collection
    {
        return $shift->users()->orderByRaw('users.status = 1 DESC')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.phone', 'users.status', 'users.is_online']);
    }

    /** Đơn hoàn thành trung bình theo giờ trong khung ca (N ngày). */
    public static function hourlyOrders(Shift $shift): array
    {
        $rows = self::timeWhere(DB::table('orders')->where('city_id', $shift->city_id)->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(self::DEMAND_DAYS)), $shift)
            ->selectRaw('HOUR(completed_at) h, COUNT(*) c')->groupBy('h')->pluck('c', 'h');

        $hours = [];
        foreach (self::segments($shift->start_time, $shift->end_time) as [$a, $b]) {
            for ($h = intdiv($a, 60); $h < (int) ceil($b / 60); $h++) {
                $hours[$h % 24] = round(($rows[$h % 24] ?? 0) / self::DEMAND_DAYS, 1);
            }
        }

        return $hours;
    }
}
