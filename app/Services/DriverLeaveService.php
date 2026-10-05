<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverLeaveLog;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Order\Models\Order;

/** Ghi nhận/hủy ngày nghỉ của tài xế (có nhật ký) và tính tác động lên từng ca. */
class DriverLeaveService
{
    public const MAX_DAYS = 14;

    /** Từ ngần này lượt nghỉ trong 30 ngày thì bị gắn cờ "nghỉ nhiều". */
    public const FREQUENT_AT = 4;

    public const IMPACT_DAYS = 7;

    /** @return array{ok:bool,message:string,created:int,skipped:int,first:?DriverLeaveRequest} */
    public static function create(int $driverId, Carbon|string $from, Carbon|string|null $to, string $note, ?int $by): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to ?? $from)->startOfDay();
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'created' => 0, 'skipped' => 0, 'first' => null];

        if (mb_strlen(trim($note)) < 3) {
            return $fail('Hãy ghi lý do nghỉ (ít nhất 3 ký tự).');
        }
        if ($from->lt(today()) || $to->lt($from)) {
            return $fail('Ngày nghỉ phải từ hôm nay trở đi và ngày kết thúc không trước ngày bắt đầu.');
        }
        $days = (int) $from->diffInDays($to) + 1;
        if ($days > self::MAX_DAYS) {
            return $fail('Mỗi lần chỉ ghi tối đa '.self::MAX_DAYS.' ngày.');
        }
        $driver = User::where('user_type', 'driver')->find($driverId);
        if (! $driver || (int) $driver->status !== 1) {
            return $fail('Chỉ ghi nghỉ cho tài xế đang hoạt động.');
        }
        $includesToday = $from->isToday();
        if ($includesToday && Order::where('delivery_man_id', $driverId)->whereIn('status', ['assigned', 'processing'])->exists()) {
            return $fail('Tài xế đang có đơn chưa hoàn thành, không thể ghi nghỉ phép hôm nay.');
        }

        $created = 0;
        $skipped = 0;
        $first = null;
        DB::transaction(function () use ($driverId, $from, $days, $note, $by, &$created, &$skipped, &$first) {
            for ($i = 0; $i < $days; $i++) {
                $date = $from->copy()->addDays($i);
                if (DriverLeaveRequest::where('driver_id', $driverId)->whereDate('leave_date', $date)->exists()) {
                    $skipped++;

                    continue;
                }
                $leave = DriverLeaveRequest::create(['driver_id' => $driverId, 'leave_date' => $date->toDateString(), 'note' => trim($note), 'created_by' => $by]);
                $first ??= $leave;
                DriverLeaveLog::create(['driver_id' => $driverId, 'leave_date' => $date->toDateString(), 'action' => 'created', 'performed_by' => $by, 'note' => trim($note)]);
                $created++;
            }
        });

        if ($created === 0) {
            return $fail('Tài xế đã được ghi nhận nghỉ trong tất cả các ngày này.');
        }

        if ($includesToday && $skipped < $days) {
            DB::transaction(function () use ($driverId) {
                User::whereKey($driverId)->update(['is_online' => false, 'online_since' => null]);
                DriverShiftSession::where('driver_id', $driverId)->whereNull('ended_at')->update(['ended_at' => now()]);
            });
            RTDBService::removeDriverLocation($driverId);
        }

        self::notify($driverId, 'Đã ghi nhận ngày nghỉ', 'Bạn nghỉ '.($created > 1 ? $created.' ngày từ ' : 'ngày ').$from->format('d/m').($created > 1 ? ' đến '.$to->format('d/m') : '').'. Trong ngày nghỉ bạn không bật được online.');

        return ['ok' => true, 'message' => 'Đã ghi nhận '.$created.' ngày nghỉ'.($skipped ? ' (bỏ qua '.$skipped.' ngày đã có)' : '').'.', 'created' => $created, 'skipped' => $skipped, 'first' => $first];
    }

    /** Hủy phiếu hôm nay trở đi. Phiếu đã qua giữ nguyên để lịch sử miễn điểm còn nguyên. */
    public static function cancel(DriverLeaveRequest $leave, string $reason, ?int $by): array
    {
        if (mb_strlen(trim($reason)) < 3) {
            return ['ok' => false, 'message' => 'Hãy ghi lý do hủy (ít nhất 3 ký tự).'];
        }
        if ($leave->leave_date->lt(today())) {
            return ['ok' => false, 'message' => 'Phiếu nghỉ đã qua, không hủy được.'];
        }

        DB::transaction(function () use ($leave, $reason, $by) {
            DriverLeaveLog::create(['driver_id' => $leave->driver_id, 'leave_date' => $leave->leave_date->toDateString(), 'action' => 'cancelled', 'performed_by' => $by, 'note' => trim($reason)]);
            $leave->delete();
        });
        self::notify($leave->driver_id, 'Đã hủy ngày nghỉ', 'Ngày nghỉ '.$leave->leave_date->format('d/m').' đã được hủy: '.trim($reason).'.');

        return ['ok' => true, 'message' => 'Đã hủy phiếu nghỉ.'];
    }

    private static function notify(int $driverId, string $title, string $body): void
    {
        try {
            $token = User::whereKey($driverId)->value('fcm_token');
            if ($token) {
                FCMService::getInstance()->sendDriverNotice($token, $title, $body, ['type' => 'leave_recorded']);
            }
        } catch (\Throwable $e) {
            Log::warning('[Leave] notify failed', ['driver_id' => $driverId, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<int, array{key:string,label:string,level:string}> */
    public static function flags(DriverLeaveRequest $leave): array
    {
        $flags = [];
        // Danh sách đã tính sẵn các cột này bằng subquery; trang chi tiết/test thì tính tại chỗ.
        $recent = $leave->getAttribute('recent_leaves') ?? DriverLeaveRequest::where('driver_id', $leave->driver_id)
            ->where('leave_date', '>=', today()->subDays(30))->where('leave_date', '<=', today())->count();
        if ($recent >= self::FREQUENT_AT) {
            $flags[] = ['key' => 'frequent', 'label' => 'Nghỉ '.$recent.' lần/30 ngày', 'level' => 'warning'];
        }
        $worked = $leave->getAttribute('worked_before') ?? Order::where('delivery_man_id', $leave->driver_id)->where('status', 'completed')
            ->whereDate('completed_at', $leave->leave_date)->where('completed_at', '<=', $leave->created_at)->count();
        if ($worked > 0) {
            $flags[] = ['key' => 'worked', 'label' => 'Đã chạy '.$worked.' đơn trước khi ghi nhận', 'level' => 'info'];
        }
        $hasShift = $leave->getAttribute('has_shift') ?? DB::table('shift_user')->where('user_id', $leave->driver_id)->exists();
        if (! $hasShift) {
            $flags[] = ['key' => 'noshift', 'label' => 'Chưa đăng ký ca', 'level' => 'gray'];
        }

        return $flags;
    }

    /**
     * Mỗi ngày trong N ngày tới: số tài xế hoạt động còn lại ở từng ca sau khi trừ người nghỉ.
     * $extra = [ 'Y-m-d' => [driverId,...] ] để xem trước khi thêm người nghỉ.
     */
    public static function impact(int $cityId, int $days = self::IMPACT_DAYS, array $extra = []): array
    {
        $shifts = Shift::where('city_id', $cityId)->where('is_active', true)->orderBy('start_time')->get();
        $registered = DB::table('shift_user as su')->join('users as u', 'u.id', '=', 'su.user_id')
            ->whereIn('su.shift_id', $shifts->pluck('id'))->where('u.status', 1)->get(['su.shift_id', 'su.user_id'])
            ->groupBy('shift_id')->map(fn ($g) => $g->pluck('user_id')->map(fn ($i) => (int) $i)->all());
        $perDay = ShiftService::coverage($cityId)['rows']->mapWithKeys(fn ($r) => [$r['shift']->id => $r['perDay']]);

        $leaves = DB::table('driver_leave_requests as l')->join('users as u', 'u.id', '=', 'l.driver_id')
            ->where('u.city_id', $cityId)->whereBetween('l.leave_date', [today()->toDateString(), today()->addDays($days - 1)->toDateString()])
            ->get(['l.driver_id', 'l.leave_date'])
            ->groupBy(fn ($r) => Carbon::parse($r->leave_date)->toDateString())
            ->map(fn ($g) => $g->pluck('driver_id')->map(fn ($i) => (int) $i)->all());

        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $date = today()->addDays($i);
            $key = $date->toDateString();
            $away = array_unique(array_merge($leaves[$key] ?? [], $extra[$key] ?? []));
            $cells = $shifts->map(function (Shift $s) use ($registered, $away, $perDay) {
                $reg = $registered[$s->id] ?? [];
                $gone = count(array_intersect($reg, $away));
                $remaining = count($reg) - $gone;
                $pd = (float) ($perDay[$s->id] ?? 0);
                $ratio = $remaining > 0 ? round($pd / $remaining, 1) : null;

                return [
                    'shift' => $s, 'registered' => count($reg), 'away' => $gone, 'remaining' => $remaining, 'perDriver' => $ratio,
                    'bad' => $remaining === 0 ? $pd >= 1 : ($ratio ?? 0) >= ShiftService::OVERLOAD_PER_DRIVER,
                ];
            });
            $rows[] = ['date' => $date, 'onLeave' => count($away), 'cells' => $cells];
        }

        return ['rows' => $rows, 'shifts' => $shifts];
    }

    public static function summary(?int $cityId): array
    {
        $q = fn () => DB::table('driver_leave_requests as l')->join('users as u', 'u.id', '=', 'l.driver_id')->when($cityId, fn ($x) => $x->where('u.city_id', $cityId));

        $frequent = $q()->where('l.leave_date', '>=', today()->subDays(30))->where('l.leave_date', '<=', today())
            ->groupBy('l.driver_id')->havingRaw('COUNT(*) >= ?', [self::FREQUENT_AT])->get(['l.driver_id'])->count();

        return [
            'today' => $q()->whereDate('l.leave_date', today())->count(),
            'tomorrow' => $q()->whereDate('l.leave_date', today()->addDay())->count(),
            'week' => $q()->whereBetween('l.leave_date', [today()->toDateString(), today()->addDays(6)->toDateString()])->count(),
            'frequent' => $frequent,
        ];
    }

    /** @return Collection<int, DriverLeaveLog> */
    public static function logs(int $driverId, Carbon $date): Collection
    {
        return DriverLeaveLog::where('driver_id', $driverId)->whereDate('leave_date', $date)->latest('id')->get();
    }
}
