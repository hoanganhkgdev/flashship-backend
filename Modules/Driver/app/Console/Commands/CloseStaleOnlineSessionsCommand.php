<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverLocationService;

/**
 * Tài xế bấm "online" chỉ mở 1 dòng DriverShiftSession, và chỉ chính tài xế
 * tự tắt mới đóng lại — không có gì khác đóng session đó cả. ScoreShiftSessionsCommand
 * chấm điểm % online của ca hoàn toàn theo giờ đồng hồ giữa started_at/ended_at,
 * KHÔNG đối chiếu GPS còn sống hay không. Hệ quả: tài xế bật online rồi tắt định
 * vị/tắt mạng vẫn được cộng điểm đều đặn mỗi ca dù không hề chạy đơn nào — vừa
 * farm được điểm "miễn phí", vừa vô hình với dispatch (không ai biết đã mất tín
 * hiệu) suốt thời gian đó (xem điều tra tài xế #107 — 15 tiếng không cập nhật
 * GPS mà vẫn "online").
 *
 * Lệnh này chạy định kỳ, dùng lại đúng ngưỡng "GPS mới hơn 10 phút"
 * (DriverLocationService::POS_MAX_AGE_SECS) mà dispatch đã dùng để lọc ứng viên
 * — tài xế mất tín hiệu quá ngưỡng đó thì tự chuyển offline, và session được
 * đóng tại ĐÚNG thời điểm GPS cuối cùng còn tươi (không phải "bây giờ") để
 * quãng thời gian mất tín hiệu không bị tính là online khi chấm điểm ca.
 */
class CloseStaleOnlineSessionsCommand extends Command
{
    protected $signature   = 'drivers:close-stale-sessions';
    protected $description = 'Tự chuyển offline tài xế đang online nhưng GPS đã chết quá ngưỡng dispatch';

    public function handle(): void
    {
        $locations = RTDBService::getDriverLocations();
        $now       = time();

        $onlineDrivers = User::where('user_type', 'driver')
            ->where('is_online', true)
            ->get(['id']);

        $closed = 0;

        foreach ($onlineDrivers as $driver) {
            $data      = $locations["driver_{$driver->id}"] ?? null;
            $updatedAt = $data['updated_at'] ?? null;
            $posAge    = is_numeric($updatedAt) ? $now - (int) ($updatedAt / 1000) : PHP_INT_MAX;

            if ($posAge <= DriverLocationService::POS_MAX_AGE_SECS) {
                continue;
            }

            // createFromTimestamp() KHÔNG tự theo timezone app (Asia/Ho_Chi_Minh)
            // như now() — mặc định trả về UTC, lệch 7 tiếng nếu không truyền tay.
            $lastFreshAt = is_numeric($updatedAt)
                ? Carbon::createFromTimestamp((int) ($updatedAt / 1000), config('app.timezone'))
                : null;

            DB::transaction(function () use ($driver, $lastFreshAt) {
                // Đóng TẤT CẢ phiên đang mở, không chỉ phiên gần nhất — nếu
                // sót lại phiên chồng lấp nào (bug cũ, hoặc lỗi khác trong
                // tương lai) mà chỉ đóng đúng 1 phiên thì phiên còn lại sẽ mở
                // mãi mãi, ăn "online" vào mọi ca sau này vô thời hạn.
                $sessions = DriverShiftSession::where('driver_id', $driver->id)
                    ->whereNull('ended_at')
                    ->get();

                foreach ($sessions as $session) {
                    // Chưa từng có 1 fix GPS nào trong suốt session này → không
                    // tính giây online nào cả, đóng ngay tại lúc mở (thay vì
                    // "bây giờ", vốn sẽ vô tình hoàn lại toàn bộ thời gian
                    // farm). Kẹp tối thiểu về đúng lúc mở phiên — tránh đóng ở
                    // 1 mốc trước cả started_at nếu $lastFreshAt thuộc về 1
                    // phiên chồng lấp khác cũ hơn.
                    $endAt = $lastFreshAt && $lastFreshAt->greaterThan($session->started_at)
                        ? $lastFreshAt
                        : $session->started_at;
                    $session->update(['ended_at' => $endAt]);
                }

                User::where('id', $driver->id)->update([
                    'is_online'    => false,
                    'online_since' => null,
                ]);
            });

            RTDBService::setDriverOnlineStatus($driver->id, false);

            $ageMinutes = intdiv($posAge === PHP_INT_MAX ? 0 : $posAge, 60);
            Log::info("[CloseStaleSessions] Tài xế #{$driver->id}: GPS " .
                ($updatedAt ? "chết {$ageMinutes} phút" : "chưa từng có fix") .
                " → tự chuyển offline, đóng session tại " . ($lastFreshAt?->toDateTimeString() ?? 'lúc mở ca'));

            $closed++;
        }

        $this->info("[CloseStaleSessions] Đã tự chuyển offline {$closed} tài xế mất tín hiệu GPS.");
        Log::info("[CloseStaleSessions] Đã tự chuyển offline {$closed} tài xế mất tín hiệu GPS.");
    }
}
