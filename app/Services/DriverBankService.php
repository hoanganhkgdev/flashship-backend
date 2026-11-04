<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\FCMService;
use Modules\Driver\Models\WithdrawRequest;

/**
 * Lịch sử đổi tài khoản ngân hàng nhận tiền rút của tài xế. Đổi số tài khoản rồi rút sạch ví là cách chiếm
 * tiền phổ biến khi người khác cầm được máy đang đăng nhập — nên mọi lần đổi đều được ghi lại, báo tài xế,
 * và trang duyệt rút tiền cảnh báo khi số tài khoản vừa đổi.
 */
class DriverBankService
{
    public const LOG_ENTITY = 'driver_bank';

    /** Cảnh báo khi số tài khoản đổi trong khoảng này trước lúc tạo yêu cầu rút. */
    public const RECENT_HOURS = 24;

    public static function mask(?string $account): string
    {
        $account = (string) $account;

        return $account === '' ? '—' : '••••'.substr($account, -4);
    }

    /**
     * @param  array{bank_code?: ?string, bank_name?: ?string, account_number?: ?string, account_name?: ?string}|null  $old
     * @param  array{bank_code: string, bank_name: ?string, account_number: string, account_name: string}  $new
     */
    public static function recordChange(int $driverId, ?array $old, array $new, ?int $by): void
    {
        $same = $old
            && ($old['bank_code'] ?? null) === $new['bank_code']
            && ($old['account_number'] ?? null) === $new['account_number']
            && ($old['account_name'] ?? null) === $new['account_name'];
        if ($same) {
            return;
        }

        $describe = fn (?array $b) => $b && ($b['account_number'] ?? null)
            ? trim(($b['bank_name'] ?? $b['bank_code'] ?? '').' '.self::mask($b['account_number']).' · '.($b['account_name'] ?? ''))
            : 'chưa có';

        DB::table('config_logs')->insert([
            'entity' => self::LOG_ENTITY,
            'entity_id' => $driverId,
            'action' => $old && ($old['account_number'] ?? null) ? 'changed' : 'created',
            'performed_by' => $by,
            'detail' => $describe($old).' → '.$describe($new),
            'created_at' => now(),
        ]);

        // Báo tài xế: nếu không phải chính họ đổi thì còn kịp liên hệ trước khi tiền bị rút.
        try {
            $token = DB::table('users')->where('id', $driverId)->value('fcm_token');
            if ($token) {
                FCMService::getInstance()->sendDriverNotice(
                    $token,
                    'Tài khoản ngân hàng vừa được thay đổi',
                    'Tiền rút sẽ chuyển tới '.$describe($new).'. Nếu không phải bạn đổi, hãy liên hệ tổng đài ngay.',
                    ['type' => 'bank_changed'],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[DriverBank] notify failed', ['driver' => $driverId, 'error' => $e->getMessage()]);
        }
    }

    /** Lần đổi số tài khoản gần nhất trong RECENT_HOURS giờ trước lúc tạo yêu cầu rút (null = không có). */
    public static function changedBefore(WithdrawRequest $r): ?Carbon
    {
        static $memo = [];
        if (! array_key_exists($r->id, $memo)) {
            $at = DB::table('config_logs')
                ->where('entity', self::LOG_ENTITY)->where('entity_id', $r->driver_id)->where('action', 'changed')
                ->whereBetween('created_at', [$r->created_at->copy()->subHours(self::RECENT_HOURS), $r->created_at])
                ->max('created_at');
            $memo[$r->id] = $at ? Carbon::parse($at) : null;
        }

        return $memo[$r->id];
    }

    /** @return \Illuminate\Support\Collection<int, object> lịch sử đổi, mới nhất trước */
    public static function history(int $driverId, int $limit = 10)
    {
        return DB::table('config_logs')->where('entity', self::LOG_ENTITY)->where('entity_id', $driverId)
            ->latest('created_at')->latest('id')->limit($limit)->get();
    }
}
