<?php

namespace Modules\Driver\Services;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverShiftSession;

/**
 * Mỗi ca tài xế được Offline tối đa N phút (cấu hình theo khu vực, mặc định
 * 30). Vượt quá thì tự động ghi một khoản công nợ phạt (mặc định 50.000đ),
 * một lần cho mỗi ca mỗi ngày.
 *
 * Thời gian Offline = thời gian đã trôi qua trong ca (tính từ lúc ca bắt đầu
 * hoặc lúc tài xế đăng ký ca, lấy mốc muộn hơn) trừ thời gian Online. Không
 * tính tài xế bị khóa hoặc đã xin nghỉ phép ngày đó.
 */
class ShiftOfflineService
{
    public const REF_PREFIX = 'shift_offline_';

    public function __construct(private readonly ShiftOnlineTimeCalculator $calculator) {}

    /** @return int Số tài xế vừa bị phạt. */
    public function check(?Carbon $now = null): int
    {
        $now ??= now();
        $penalized = 0;

        $rows = DB::table('shift_user as su')
            ->join('shifts as s', 's.id', '=', 'su.shift_id')
            ->join('users as u', 'u.id', '=', 'su.user_id')
            ->where('s.is_active', true)
            ->where('u.user_type', 'driver')
            ->where('u.status', 1)
            ->whereColumn('s.city_id', 'u.city_id')
            ->get(['su.user_id', 'su.shift_id', 'su.created_at as registered_at', 'u.city_id as driver_city_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $shifts = Shift::whereIn('id', $rows->pluck('shift_id')->unique())->get()->keyBy('id');

        foreach ($rows as $row) {
            $shift = $shifts[$row->shift_id];
            $cityId = (int) $row->driver_city_id;
            $window = $this->currentWindow($shift, $now);
            if (! $window) {
                continue;
            }
            [$start, $end] = $window;
            $shiftDate = $start->toDateString();

            // Chỉ tính từ lúc tài xế đăng ký ca (đăng ký giữa ca không bị phạt ngược).
            $from = $row->registered_at ? $start->max(Carbon::parse($row->registered_at)) : $start;
            if ($now->lessThanOrEqualTo($from)) {
                continue;
            }

            if (DB::table('driver_shift_offline_penalties')
                ->where('driver_id', $row->user_id)->where('shift_id', $shift->id)->where('shift_date', $shiftDate)->exists()) {
                continue;
            }
            if (DB::table('driver_leave_requests')->where('driver_id', $row->user_id)->whereDate('leave_date', $shiftDate)->exists()) {
                continue;
            }

            $offline = $this->offlineSeconds((int) $row->user_id, $from, $end, $now);
            $limit = OperationalSettings::shiftOfflineLimitMinutes($cityId) * 60;
            if ($offline <= $limit) {
                continue;
            }

            if ($this->penalize((int) $row->user_id, $shift, $shiftDate, $offline, $cityId)) {
                $penalized++;
            }
        }

        return $penalized;
    }

    /** Giây Offline của tài xế trong cửa sổ [$from, min($now, $end)). */
    public function offlineSeconds(int $driverId, Carbon $from, Carbon $end, Carbon $now): int
    {
        $upTo = $now->lessThan($end) ? $now : $end;
        if ($upTo->lessThanOrEqualTo($from)) {
            return 0;
        }
        $sessions = DriverShiftSession::where('driver_id', $driverId)
            ->where('started_at', '<', $upTo)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get();
        $online = $this->calculator->seconds($sessions, $from, $upTo);

        return max(0, $from->diffInSeconds($upTo) - $online);
    }

    /**
     * Trạng thái Offline trong ca đang diễn ra của tài xế (cho app hiển thị),
     * null nếu tài xế không có ca nào đang chạy.
     *
     * @return array{shift_id:int, shift_name:string, limit_seconds:int, offline_seconds:int, remaining_seconds:int, penalized:bool, exempt:bool, measured_at:string, shift_end_at:string, penalty_amount:int}|null
     */
    public function status(User $driver, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $cityId = (int) $driver->city_id;
        $registered = DB::table('shift_user as su')
            ->join('shifts as s', 's.id', '=', 'su.shift_id')
            ->where('su.user_id', $driver->id)->where('s.is_active', true)
            ->get(['su.shift_id', 'su.created_at as registered_at']);

        foreach ($registered as $row) {
            $shift = Shift::find($row->shift_id);
            $window = $shift ? $this->currentWindow($shift, $now) : null;
            if (! $window) {
                continue;
            }
            [$start, $end] = $window;
            $from = $row->registered_at ? $start->max(Carbon::parse($row->registered_at)) : $start;
            $shiftDate = $start->toDateString();
            $limit = OperationalSettings::shiftOfflineLimitMinutes($cityId) * 60;
            $offline = $this->offlineSeconds((int) $driver->id, $from, $end, $now);
            $penalized = DB::table('driver_shift_offline_penalties')
                ->where('driver_id', $driver->id)->where('shift_id', $shift->id)->where('shift_date', $shiftDate)->exists();
            $exempt = DB::table('driver_leave_requests')->where('driver_id', $driver->id)->whereDate('leave_date', $shiftDate)->exists();

            return [
                'shift_id' => (int) $shift->id,
                'shift_name' => (string) $shift->name,
                'limit_seconds' => $limit,
                'offline_seconds' => $offline,
                'remaining_seconds' => max(0, $limit - $offline),
                'penalized' => $penalized,
                'exempt' => $exempt,
                'measured_at' => $now->toIso8601String(),
                'shift_end_at' => $end->toIso8601String(),
                'penalty_amount' => OperationalSettings::shiftOfflinePenaltyAmount($cityId),
            ];
        }

        return null;
    }

    /** Cửa sổ [bắt đầu, kết thúc) của lần ca đang diễn ra tại $now, null nếu ngoài ca. */
    public function currentWindow(Shift $shift, Carbon $now): ?array
    {
        foreach ([0, -1] as $dayOffset) {
            $day = $now->copy()->startOfDay()->addDays($dayOffset);
            $start = $day->copy()->setTimeFromTimeString((string) $shift->start_time);
            $end = $day->copy()->setTimeFromTimeString((string) $shift->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            if ($now->gte($start) && $now->lt($end)) {
                return [$start, $end];
            }
        }

        return null;
    }

    private function penalize(int $driverId, Shift $shift, string $shiftDate, int $offlineSeconds, int $cityId): bool
    {
        $amount = OperationalSettings::shiftOfflinePenaltyAmount($cityId);
        $limitMinutes = OperationalSettings::shiftOfflineLimitMinutes($cityId);
        if ($amount <= 0 || ! OperationalSettings::debtEnabled($cityId)) {
            return false;
        }
        try {
            DB::transaction(function () use ($driverId, $shift, $shiftDate, $offlineSeconds, $amount, $limitMinutes) {
                $now = now();
                $minutes = (int) floor($offlineSeconds / 60);
                $debtId = DB::table('driver_debts')->insertGetId([
                    'driver_id' => $driverId,
                    'status' => 'pending',
                    'amount_due' => $amount,
                    'amount_paid' => 0,
                    'week_start' => $shiftDate,
                    'week_end' => $shiftDate,
                    'ref_id' => self::REF_PREFIX."{$driverId}_{$shift->id}_{$shiftDate}",
                    'note' => "Phạt Offline quá {$limitMinutes} phút trong {$shift->name} ngày {$shiftDate} (đã off {$minutes} phút)",
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('driver_shift_offline_penalties')->insert([
                    'driver_id' => $driverId,
                    'shift_id' => $shift->id,
                    'shift_date' => $shiftDate,
                    'offline_seconds' => $offlineSeconds,
                    'amount' => $amount,
                    'debt_id' => $debtId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // một lượt kiểm tra khác vừa phạt xong
        }

        $this->notify($driverId, $shift, $limitMinutes, $amount);
        Log::info('[ShiftOffline] Phạt tài xế Offline quá giờ', [
            'driver_id' => $driverId, 'shift_id' => $shift->id, 'shift_date' => $shiftDate, 'offline_seconds' => $offlineSeconds,
        ]);

        return true;
    }

    private function notify(int $driverId, Shift $shift, int $limitMinutes, int $amount): void
    {
        $token = User::whereKey($driverId)->value('fcm_token');
        if (! $token) {
            return;
        }
        try {
            FCMService::getInstance()->sendDriverNotice(
                $token,
                'Bị phạt Offline quá giờ',
                "Bạn đã Offline quá {$limitMinutes} phút trong {$shift->name}. Phạt ".number_format($amount, 0, ',', '.').'đ, ghi vào Công nợ.',
                ['type' => 'debt_overdue', 'reason' => 'shift_offline'],
            );
        } catch (\Throwable $e) {
            Log::warning('[ShiftOffline] Không gửi được thông báo: '.$e->getMessage());
        }
    }
}
