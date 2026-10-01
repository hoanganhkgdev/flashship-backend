<?php

namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverReferral;
use Modules\Order\Models\Order;

class ReferralService
{
    // Bỏ các ký tự dễ nhầm: 0/O, 1/I.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    public static function generateUniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    /** Tài xế đang hoạt động sở hữu mã, hoặc null nếu mã không hợp lệ. */
    public static function findDriverByCode(?string $code): ?User
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return User::where('user_type', 'driver')->where('status', 1)->where('referral_code', $code)->first();
    }

    /** So theo 9 số cuối để 0xxx / 84xxx / +84xxx là một số. */
    public static function isSamePerson(?string $phoneA, ?string $phoneB): bool
    {
        $a = substr(preg_replace('/\D/', '', (string) $phoneA), -9);
        $b = substr(preg_replace('/\D/', '', (string) $phoneB), -9);

        return $a !== '' && $a === $b;
    }

    /**
     * Gắn shop mới vào tài xế giới thiệu. Gọi trong transaction tạo shop.
     * Mã đã được kiểm tra ở controller; mã không hợp lệ ở đây bị bỏ qua.
     */
    public static function attachShop(User $shop, string $code): void
    {
        $driver = self::findDriverByCode($code);
        if (! $driver || self::isSamePerson($driver->phone, $shop->phone)) {
            return;
        }

        $shop->forceFill(['referred_by_driver_id' => $driver->id])->save();
        DriverReferral::create([
            'driver_id' => $driver->id,
            'shop_id' => $shop->id,
            'status' => DriverReferral::PENDING,
            'required_orders' => OperationalSettings::referralMinOrders($shop->city_id),
        ]);
    }

    /**
     * Gọi sau khi một đơn của shop hoàn thành (trong transaction hoàn thành đơn).
     * Đủ số đơn tối thiểu theo cấu hình khu vực thì cộng thưởng cho tài xế giới thiệu.
     */
    public static function onOrderCompleted(Order $order): void
    {
        if ($order->platform !== 'shop_app' || ! $order->sender_platform_id) {
            return;
        }

        $referral = DriverReferral::where('shop_id', $order->sender_platform_id)
            ->where('status', DriverReferral::PENDING)
            ->lockForUpdate()
            ->first();
        if (! $referral) {
            return;
        }

        // Tài xế tự giao đơn của shop mình giới thiệu thì không được tính (chống đơn ảo).
        $completed = Order::where('sender_platform_id', $referral->shop_id)
            ->where('platform', 'shop_app')
            ->where('status', 'completed')
            ->where('delivery_man_id', '!=', $referral->driver_id)
            ->count();

        $required = max(1, (int) ($referral->required_orders ?: 1));
        if ($completed < $required) {
            return;
        }

        self::grantReward($referral, $order->city_id);
    }

    /**
     * Cộng thưởng cho một lượt giới thiệu đang chờ (đủ điều kiện tự động hoặc
     * admin duyệt tay). Trả về false nếu lượt này không còn ở trạng thái chờ.
     */
    public static function grantReward(DriverReferral $referral, ?int $cityId): bool
    {
        $granted = DB::transaction(function () use ($referral, $cityId) {
            $locked = DriverReferral::whereKey($referral->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== DriverReferral::PENDING) {
                return null;
            }

            $amount = OperationalSettings::referralRewardAmount($cityId);
            $now = now();

            if ($amount > 0) {
                $shopName = User::whereKey($locked->shop_id)->value('name');
                DriverWalletService::adjust(
                    $locked->driver_id,
                    $amount,
                    'credit',
                    'Thưởng giới thiệu shop '.($shopName ?: '#'.$locked->shop_id),
                    "referral_{$locked->shop_id}",
                );
            }

            $locked->update([
                'status' => DriverReferral::REWARDED,
                'reward_amount' => $amount,
                'qualified_at' => $now,
                'rewarded_at' => $now,
            ]);

            return [$locked, $amount];
        });

        if (! $granted) {
            return false;
        }

        self::notifyDriver(...$granted);

        return true;
    }

    /** Từ chối một lượt giới thiệu đang chờ (nghi gian lận, shop ảo...). */
    public static function reject(DriverReferral $referral, string $reason): bool
    {
        return DriverReferral::whereKey($referral->id)
            ->where('status', DriverReferral::PENDING)
            ->update(['status' => DriverReferral::REJECTED, 'reject_reason' => $reason, 'updated_at' => now()]) > 0;
    }

    private static function notifyDriver(DriverReferral $referral, int $amount): void
    {
        try {
            $token = User::whereKey($referral->driver_id)->value('fcm_token');
            if ($token && $amount > 0) {
                FCMService::getInstance()->sendDriverNotice(
                    $token,
                    'Thưởng giới thiệu',
                    'Bạn được cộng '.number_format($amount, 0, ',', '.').'đ vì shop bạn giới thiệu đã hoạt động.',
                    ['type' => 'referral_reward'],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[Referral] notify failed', ['driver_id' => $referral->driver_id, 'error' => $e->getMessage()]);
        }
    }
}
