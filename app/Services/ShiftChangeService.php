<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Driver\Models\DriverShiftChangeRequest;

/** Duyệt/từ chối yêu cầu đổi ca, kèm kiểm tra điều kiện, giờ duyệt được sớm nhất và tác động lên từng ca. */
class ShiftChangeService
{
    public const REJECT_REASONS = [
        'no_capacity' => 'Ca đề nghị không còn đủ chỗ',
        'restricted' => 'Tài khoản đang bị hạn chế',
        'too_many' => 'Gửi yêu cầu quá nhiều lần',
        'other' => 'Lý do khác',
    ];

    public const STALE_HOURS = 24;

    /** Gửi từ ngần này yêu cầu trong 30 ngày thì bị gắn cờ. */
    public const FREQUENT_AT = 3;

    private static function minute(Carbon $t): int
    {
        return $t->hour * 60 + $t->minute;
    }

    private static function running(Shift $s, Carbon $t): bool
    {
        $m = self::minute($t);
        $a = ShiftService::minutes($s->start_time);
        $b = ShiftService::minutes($s->end_time);

        return $b > $a ? ($m >= $a && $m < $b) : ($m >= $a || $m < $b);
    }

    /** @return Collection<int, Shift> */
    public static function requestedShifts(DriverShiftChangeRequest $r): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $r->shift_ids ?? [])));

        return Shift::whereIn('id', $ids)->get()->sortBy('start_time')->values();
    }

    private static function currentShifts(DriverShiftChangeRequest $r): Collection
    {
        return ($r->driver?->registeredShifts ?? collect())->sortBy('start_time')->values();
    }

    private static function overlapAny(Collection $shifts): bool
    {
        foreach ($shifts->values() as $i => $a) {
            foreach ($shifts->values() as $j => $b) {
                if ($i < $j && ShiftService::overlaps($a->start_time, $a->end_time, $b->start_time, $b->end_time)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Lỗi cố định của yêu cầu (không phụ thuộc giờ), null nếu hợp lệ. */
    public static function invalidReason(DriverShiftChangeRequest $r): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $r->shift_ids ?? [])));
        $shifts = self::requestedShifts($r);
        $driver = $r->driver;

        return match (true) {
            ! $driver => 'Tài xế không còn tồn tại',
            (int) $driver->status !== 1 => 'Tài xế đã bị khóa hoặc chưa được duyệt',
            $ids === [] || $shifts->count() !== count($ids) => 'Có ca không còn tồn tại',
            $shifts->contains(fn (Shift $s) => ! $s->is_active) => 'Có ca đã tắt',
            $shifts->contains(fn (Shift $s) => (int) $s->city_id !== (int) $driver->city_id) => 'Ca không thuộc khu vực tài xế',
            self::overlapAny($shifts) => 'Các ca đề nghị bị trùng giờ',
            default => null,
        };
    }

    /** Thời điểm sớm nhất (trong 24 giờ tới) mà cả ca cũ lẫn ca mới đều không đang diễn ra. */
    public static function earliestApproval(DriverShiftChangeRequest $r): array
    {
        $now = now();
        $all = self::currentShifts($r)->concat(self::requestedShifts($r));
        $online = (bool) $r->driver?->is_online;

        for ($i = 0; $i <= 1440; $i++) {
            $t = $now->copy()->addMinutes($i)->startOfMinute();
            if (! $all->contains(fn (Shift $s) => self::running($s, $t))) {
                return ['at' => $i === 0 ? null : $t, 'now' => $i === 0 && ! $online, 'online' => $online];
            }
        }

        return ['at' => null, 'now' => false, 'online' => $online];
    }

    /** Có duyệt được ngay bây giờ không (kèm lý do nếu không). */
    public static function canApproveNow(DriverShiftChangeRequest $r): array
    {
        if ($r->status !== 'pending') {
            return ['ok' => false, 'reason' => 'Yêu cầu đã được xử lý'];
        }
        if ($why = self::invalidReason($r)) {
            return ['ok' => false, 'reason' => $why];
        }
        $e = self::earliestApproval($r);
        if ($e['online']) {
            return ['ok' => false, 'reason' => 'Tài xế đang online, chờ tài xế offline'];
        }
        if (! $e['now']) {
            return ['ok' => false, 'reason' => $e['at'] ? 'Duyệt được từ '.$e['at']->format('H:i').($e['at']->isTomorrow() ? ' ngày mai' : '') : 'Chưa có thời điểm phù hợp trong 24 giờ tới'];
        }

        return ['ok' => true, 'reason' => 'Có thể duyệt ngay'];
    }

    /** @return array<int, array{key:string,label:string,level:string}> */
    public static function flags(DriverShiftChangeRequest $r): array
    {
        $flags = [];
        $driver = $r->driver;
        if ($driver && (int) $driver->status !== 1) {
            $flags[] = ['key' => 'locked', 'label' => 'Tài xế đã khóa', 'level' => 'danger'];
        }
        $cur = self::currentShifts($r)->pluck('id')->sort()->values()->all();
        $req = collect($r->shift_ids ?? [])->map(fn ($i) => (int) $i)->unique()->sort()->values()->all();
        if ($cur && $cur === $req) {
            $flags[] = ['key' => 'same', 'label' => 'Trùng ca đang đăng ký', 'level' => 'warning'];
        }
        $recent = DriverShiftChangeRequest::where('driver_id', $r->driver_id)->where('created_at', '>=', now()->subDays(30))->count();
        if ($recent >= self::FREQUENT_AT) {
            $flags[] = ['key' => 'frequent', 'label' => 'Gửi '.$recent.' yêu cầu/30 ngày', 'level' => 'warning'];
        }
        if ($r->status === 'pending' && $r->created_at->diffInHours(now()) >= self::STALE_HOURS) {
            $flags[] = ['key' => 'stale', 'label' => 'Chờ quá '.self::STALE_HOURS.' giờ', 'level' => 'danger'];
        }

        return $flags;
    }

    /** Số tài xế hoạt động và tải của từng ca bị ảnh hưởng, trước → sau khi duyệt. */
    public static function impact(DriverShiftChangeRequest $r): Collection
    {
        $cityId = (int) $r->driver?->city_id;
        $rows = ShiftService::coverage($cityId)['rows']->keyBy(fn ($row) => $row['shift']->id);
        $cur = self::currentShifts($r)->pluck('id')->all();
        $req = self::requestedShifts($r)->pluck('id')->all();
        $threshold = ShiftService::OVERLOAD_PER_DRIVER;

        return collect(array_unique(array_merge($cur, $req)))->map(function ($id) use ($rows, $cur, $req, $threshold) {
            $row = $rows[$id] ?? null;
            if (! $row) {
                return null;
            }
            $delta = (in_array($id, $req, true) ? 1 : 0) - (in_array($id, $cur, true) ? 1 : 0);
            $before = $row['active'];
            $after = max(0, $before + $delta);
            $ratio = fn (int $n) => $n > 0 ? round($row['perDay'] / $n, 1) : null;

            return [
                'shift' => $row['shift'], 'delta' => $delta,
                'before' => $before, 'after' => $after,
                'perBefore' => $ratio($before), 'perAfter' => $ratio($after),
                'warn' => $delta < 0 && ($after === 0 || ($ratio($after) ?? 0) >= $threshold) ? 'Ca này sẽ quá tải hoặc không còn tài xế' : null,
            ];
        })->filter()->sortBy(fn ($i) => $i['shift']->start_time)->values();
    }

    /** @return array{ok:bool,message:string} */
    public static function approve(DriverShiftChangeRequest $request, ?int $by): array
    {
        $result = DB::transaction(function () use ($request, $by) {
            $locked = DriverShiftChangeRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return 'handled';
            }
            $locked->load(['driver' => fn ($q) => $q->lockForUpdate(), 'driver.registeredShifts']);

            if (self::invalidReason($locked)) {
                return 'invalid';
            }
            $e = self::earliestApproval($locked);
            if ($e['online'] || ! $e['now']) {
                return 'active_shift';
            }

            $ids = self::requestedShifts($locked)->pluck('id')->all();
            $locked->driver->registeredShifts()->sync($ids);
            $locked->update(['status' => 'approved', 'processed_by' => $by, 'processed_at' => now()]);

            return 'approved';
        });

        if ($result !== 'approved') {
            return ['ok' => false, 'message' => match ($result) {
                'handled' => 'Yêu cầu đã được người khác xử lý.',
                'active_shift' => 'Chưa đổi được: tài xế đang online hoặc ca cũ/ca mới đang diễn ra.',
                default => 'Yêu cầu không còn hợp lệ (tài xế khóa, ca đã tắt, trùng giờ...).',
            }];
        }

        $names = self::requestedShifts($request)->map(fn (Shift $s) => $s->name.' ('.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5).')')->implode(', ');
        self::notify($request->driver_id, 'Yêu cầu đổi ca được duyệt', 'Ca làm việc mới của bạn: '.$names.'.');

        return ['ok' => true, 'message' => 'Đã duyệt đổi ca.'];
    }

    /** @return array{ok:bool,message:string} */
    public static function reject(DriverShiftChangeRequest $request, string $reason, ?string $note, ?int $by): array
    {
        $label = self::REJECT_REASONS[$reason] ?? null;
        $note = trim((string) $note);
        if (! $label) {
            return ['ok' => false, 'message' => 'Hãy chọn lý do từ chối.'];
        }
        if ($reason === 'other' && mb_strlen($note) < 5) {
            return ['ok' => false, 'message' => 'Với lý do khác, hãy ghi rõ lý do (ít nhất 5 ký tự).'];
        }
        $text = $note !== '' ? $label.': '.$note : $label;

        $done = DB::transaction(function () use ($request, $reason, $text, $by) {
            $locked = DriverShiftChangeRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return false;
            }
            $locked->update(['status' => 'rejected', 'reject_reason' => $reason, 'admin_note' => $text, 'processed_by' => $by, 'processed_at' => now()]);

            return true;
        });
        if (! $done) {
            return ['ok' => false, 'message' => 'Yêu cầu đã được người khác xử lý.'];
        }

        self::notify($request->driver_id, 'Yêu cầu đổi ca bị từ chối', $text.'.');

        return ['ok' => true, 'message' => 'Đã từ chối yêu cầu đổi ca.'];
    }

    private static function notify(int $driverId, string $title, string $body): void
    {
        try {
            $token = User::whereKey($driverId)->value('fcm_token');
            if ($token) {
                FCMService::getInstance()->sendDriverNotice($token, $title, $body, ['type' => 'shift_change_processed']);
            }
        } catch (\Throwable $e) {
            Log::warning('[ShiftChange] notify failed', ['driver_id' => $driverId, 'error' => $e->getMessage()]);
        }
    }

    public static function summary(?int $cityId): array
    {
        $base = fn () => DB::table('driver_shift_change_requests as r')->join('users as u', 'u.id', '=', 'r.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId));

        $pending = DriverShiftChangeRequest::with(['driver.registeredShifts', 'driver.city'])->where('status', 'pending')
            ->whereHas('driver', fn ($q) => $q->when($cityId, fn ($d) => $d->where('city_id', $cityId)))->orderBy('created_at')->get();

        $done = $base()->where('r.processed_at', '>=', now()->subDays(30))->selectRaw("SUM(r.status='approved') a, SUM(r.status='rejected') x")->first();
        $processed = (int) $done->a + (int) $done->x;

        return [
            'pending' => $pending->count(),
            'oldestHours' => $pending->isEmpty() ? 0 : (int) $pending->first()->created_at->diffInHours(now()),
            'stale' => $pending->filter(fn ($r) => $r->created_at->diffInHours(now()) >= self::STALE_HOURS)->count(),
            'approvableNow' => $pending->filter(fn ($r) => self::canApproveNow($r)['ok'])->count(),
            'locked' => $pending->filter(fn ($r) => (int) $r->driver?->status !== 1)->count(),
            'approveRate' => $processed ? (int) round($done->a / $processed * 100) : null,
            'processed' => $processed,
        ];
    }
}
