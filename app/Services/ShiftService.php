<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Shift;
use Modules\Core\Models\ShiftLog;

/** Kiểm tra, ghi nhật ký và số liệu phủ ca. */
class ShiftService
{
    /** Từ mức này trở lên (đơn/tài xế/ngày) thì ca bị coi là quá tải. */
    public const OVERLOAD_PER_DRIVER = 8;

    public const DEMAND_DAYS = 14;

    /** Hành động làm "ca bắt đầu có hiệu lực" từ một thời điểm: phạt Offline không tính ngược về trước mốc này. */
    private const EFFECTIVE_ACTIONS = ['created', 'activated', 'time_changed'];

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

    /**
     * Ca tài xế "đang có": đã đăng ký, đang bật VÀ cùng khu vực hiện tại của tài xế. Cron phạt Offline chỉ xét
     * những ca này, nên mọi nơi khác (điều kiện bật Online, danh sách ca của app, đồng hồ Offline, "đã đăng ký
     * rồi") phải dùng đúng định nghĩa này — tài xế đổi khu vực còn dính ca cũ coi như chưa có ca.
     */
    public static function activeRegistered(\Modules\Core\Models\User $driver)
    {
        return $driver->registeredShifts()
            ->where('shifts.is_active', true)
            ->where('shifts.city_id', (int) $driver->city_id);
    }

    /** Cặp ca trùng giờ đầu tiên trong danh sách (mỗi phần tử có name, start_time, end_time), null nếu không có. */
    public static function firstOverlap(iterable $shifts): ?array
    {
        $list = collect($shifts)->values();
        foreach ($list as $i => $a) {
            foreach ($list as $j => $b) {
                if ($i < $j && self::overlaps($a->start_time, $a->end_time, $b->start_time, $b->end_time)) {
                    return [$a, $b];
                }
            }
        }

        return null;
    }

    public static function log(int $shiftId, string $action, ?int $by, ?string $detail = null): void
    {
        ShiftLog::create(['shift_id' => $shiftId, 'action' => $action, 'performed_by' => $by, 'detail' => $detail, 'created_at' => now()]);
    }

    /**
     * Lần gần nhất mỗi ca được tạo, bật lại hoặc đổi giờ. Phạt Offline chỉ tính thời gian vắng từ mốc này:
     * ca bật/đổi giờ giữa lúc đang chạy không được coi là tài xế đã vắng từ đầu ca.
     *
     * @param  array<int, int>  $shiftIds
     * @return Collection<int, Carbon> shift_id => mốc
     */
    public static function lastEffectiveChange(array $shiftIds): Collection
    {
        if ($shiftIds === []) {
            return collect();
        }

        return ShiftLog::whereIn('shift_id', $shiftIds)->whereIn('action', self::EFFECTIVE_ACTIONS)
            ->groupBy('shift_id')->selectRaw('shift_id, MAX(created_at) as at')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->shift_id => Carbon::parse($r->at)]);
    }

    /**
     * Tài xế đang đăng ký ca này mà đã có ca ĐANG BẬT khác trùng với khung giờ [$start, $end).
     * Mỗi tài xế chỉ được giữ các ca không chồng giờ nhau (app và luồng đổi ca đều đòi như vậy).
     *
     * @return Collection<int, array{driver:string,shift:string}>
     */
    public static function registeredConflicts(Shift $shift, string $start, string $end): Collection
    {
        $others = Shift::where('city_id', $shift->city_id)->where('is_active', true)->where('id', '!=', $shift->id)->get()
            ->filter(fn (Shift $o) => self::overlaps($start, $end, $o->start_time, $o->end_time));
        if ($others->isEmpty()) {
            return collect();
        }

        return DB::table('shift_user as a')->join('shift_user as b', 'b.user_id', '=', 'a.user_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.shift_id', $shift->id)->whereIn('b.shift_id', $others->pluck('id'))->where('u.status', 1)
            ->get(['u.name', 'b.shift_id'])
            ->map(fn ($r) => ['driver' => $r->name, 'shift' => $others->firstWhere('id', $r->shift_id)->name]);
    }

    /** Thông báo lỗi nếu bật/đổi giờ ca làm tài xế bị trùng ca khác, null nếu ổn. */
    public static function conflictMessage(Shift $shift, string $start, string $end): ?string
    {
        $c = self::registeredConflicts($shift, $start, $end);
        if ($c->isEmpty()) {
            return null;
        }
        $names = $c->take(4)->map(fn ($x) => $x['driver'].' (trùng '.$x['shift'].')')->implode(', ');

        return 'Có '.$c->count().' tài xế sẽ bị trùng giờ với ca khác đang đăng ký: '.$names.($c->count() > 4 ? '…' : '')
            .'. Hãy đổi ca cho họ trước.';
    }

    public static function deactivate(Shift $shift, ?int $by): int
    {
        $affected = $shift->users()->where('users.status', 1)->count();
        $shift->update(['is_active' => false]);
        self::log($shift->id, 'deactivated', $by, $affected.' tài xế hoạt động đang đăng ký');

        return $affected;
    }

    /** @return string|null Lý do không bật được, null nếu đã bật. */
    public static function activate(Shift $shift, ?int $by): ?string
    {
        if ($err = self::conflictMessage($shift, $shift->start_time, $shift->end_time)) {
            return $err;
        }
        $shift->update(['is_active' => true]);
        self::log($shift->id, 'activated', $by);

        return null;
    }

    /** Lý do không xóa được ca, null nếu xóa được. Ca đã từng phạt/chấm điểm tài xế thì giữ lại để không mất lịch sử. */
    public static function undeletableReason(Shift $shift): ?string
    {
        if ($shift->users()->exists()) {
            return 'Ca còn tài xế đăng ký.';
        }
        if (DB::table('driver_shift_offline_penalties')->where('shift_id', $shift->id)->exists()
            || DB::table('driver_shift_score_runs')->where('shift_id', $shift->id)->exists()) {
            return 'Ca đã có lịch sử phạt/chấm điểm tài xế. Hãy tắt ca thay vì xóa.';
        }

        return null;
    }

    /** Mã ca tự sinh từ tên, duy nhất trong khu vực. */
    public static function uniqueCode(int $cityId, string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'ca', 40, '');
        $code = $base;
        for ($i = 2; Shift::where('city_id', $cityId)->where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    /** Điều kiện giờ [bắt đầu, kết thúc) của ca trên cột thời điểm đơn (mặc định giờ đặt đơn). */
    private static function timeWhere($q, Shift $s, string $col = 'created_at')
    {
        $start = substr($s->start_time, 0, 8);
        $end = substr($s->end_time, 0, 8);

        return $end > $start
            ? $q->whereRaw("TIME($col) >= ? AND TIME($col) < ?", [$start, $end])
            : $q->whereRaw("(TIME($col) >= ? OR TIME($col) < ?)", [$start, $end]);
    }

    /** Đơn hoàn thành của khu vực trong DEMAND_DAYS ngày, tính theo giờ KHÁCH ĐẶT (đơn đặt 12:50 giao xong 13:20 thuộc ca đang chạy lúc đặt). */
    private static function demandOrders(int $cityId)
    {
        return DB::table('orders')->where('city_id', $cityId)->where('status', 'completed')->where('created_at', '>=', now()->subDays(self::DEMAND_DAYS));
    }

    /** Số liệu phủ ca của khu vực: mỗi ca một dòng + khung giờ không có ca. */
    public static function coverage(int $cityId): array
    {
        $shifts = Shift::where('city_id', $cityId)->orderBy('start_time')->get();
        $days = self::DEMAND_DAYS;
        $orders = fn () => self::demandOrders($cityId);

        $reg = DB::table('shift_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->whereIn('su.shift_id', $shifts->pluck('id'))
            ->groupBy('su.shift_id')
            ->selectRaw('su.shift_id, SUM(u.status = 1) active, SUM(u.status = 2) locked, SUM(u.status = 1 AND u.is_online = 1) online')
            ->get()->keyBy('shift_id');

        $rows = $shifts->map(function (Shift $s) use ($reg, $orders, $days) {
            $r = $reg[$s->id] ?? null;
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
            $n = $orders()->whereRaw('TIME(created_at) >= ?', [$from])->when($to !== '24:00:00', fn ($q) => $q->whereRaw('TIME(created_at) < ?', [$to]))->count();

            return ['from' => $fmt($g[0]), 'to' => $fmt($g[1]), 'orders' => $n];
        });

        return ['rows' => $rows, 'gaps' => $gapRows, 'days' => $days];
    }

    public static function summary(int $cityId): array
    {
        $cov = self::coverage($cityId);
        $rows = $cov['rows']->where('shift.is_active', true);
        $drivers = fn () => DB::table('users')->where('user_type', 'driver')->where('status', 1)->where('city_id', $cityId);
        $activeShiftIds = $cov['rows']->where('shift.is_active', true)->pluck('shift.id');
        $inActiveShift = fn ($q) => $q->from('shift_user')->whereColumn('shift_user.user_id', 'users.id')->whereIn('shift_user.shift_id', $activeShiftIds);
        $inAnyShift = fn ($q) => $q->from('shift_user')->whereColumn('shift_user.user_id', 'users.id');

        // "Chưa có ca": chưa đăng ký ca nào, hoặc chỉ còn ca đã tắt (không được tính ca, không bị phạt Offline).
        $noShift = $drivers()->whereNotExists($inAnyShift)->count();
        $idle = $drivers()->whereExists($inAnyShift)->whereNotExists($inActiveShift)->count();
        $busiest = $rows->filter(fn ($r) => $r['perDriver'] !== null)->sortByDesc('perDriver')->first();

        return [
            'current' => $rows->where('now', true)->values(),
            'activeDrivers' => $drivers()->count(),
            'noShift' => $noShift,
            'idle' => $idle,
            'busiest' => $busiest,
            'pendingChanges' => DB::table('driver_shift_change_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
                ->where('u.city_id', $cityId)->where('r.status', 'pending')->count(),
            'coverage' => $cov,
        ];
    }

    /** Tài xế đang hoạt động nhưng không có ca đang bật nào (chưa đăng ký hoặc chỉ còn ca đã tắt), để admin xếp lại ca. */
    public static function driversWithoutActiveShift(int $cityId, int $limit = 8): Collection
    {
        return DB::table('users as u')->where('u.user_type', 'driver')->where('u.status', 1)->where('u.city_id', $cityId)
            ->whereNotExists(fn ($q) => $q->from('shift_user as su')->join('shifts as s', 's.id', '=', 'su.shift_id')
                ->whereColumn('su.user_id', 'u.id')->where('s.is_active', true))
            ->selectRaw('u.id, u.name, u.phone, EXISTS(SELECT 1 FROM shift_user x WHERE x.user_id = u.id) AS had_shift')
            ->orderBy('u.name')->limit($limit)->get();
    }

    /** Tài xế đã đăng ký ca, tài xế đang hoạt động trước. */
    public static function roster(Shift $shift): Collection
    {
        return $shift->users()->orderByRaw('users.status = 1 DESC')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.phone', 'users.status', 'users.is_online']);
    }

    /** Đơn hoàn thành trung bình theo giờ trong khung ca (N ngày), tính theo giờ đặt. */
    public static function hourlyOrders(Shift $shift): array
    {
        $rows = self::timeWhere(self::demandOrders((int) $shift->city_id), $shift)
            ->selectRaw('HOUR(created_at) h, COUNT(*) c')->groupBy('h')->pluck('c', 'h');

        $hours = [];
        foreach (self::segments($shift->start_time, $shift->end_time) as [$a, $b]) {
            for ($h = intdiv($a, 60); $h < (int) ceil($b / 60); $h++) {
                $hours[$h % 24] = round(($rows[$h % 24] ?? 0) / self::DEMAND_DAYS, 1);
            }
        }

        return $hours;
    }
}
