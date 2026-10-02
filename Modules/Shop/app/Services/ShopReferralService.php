<?php

namespace Modules\Shop\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\ReferralService;
use Modules\Order\Models\Order;
use Modules\Shop\Models\PointReward;
use Modules\Shop\Models\ShopPointTransaction;
use Modules\Shop\Models\ShopReferral;

/**
 * Shop giới thiệu shop: người giới thiệu nhận điểm khi shop mới hoàn thành đủ số
 * đơn, shop mới nhận một voucher chào mừng. Điểm đổi lấy voucher trong danh mục
 * point_rewards. Mã giới thiệu dùng chung cột users.referral_code với tài xế.
 */
class ShopReferralService
{
    /** Shop đang hoạt động sở hữu mã, hoặc null nếu mã không hợp lệ. */
    public static function findShopByCode(?string $code): ?User
    {
        $code = ReferralService::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return User::where('user_type', 'shop')->where('status', 1)->where('referral_code', $code)->first();
    }

    /**
     * Gắn shop mới vào shop giới thiệu. Gọi trong transaction tạo shop; mã đã
     * được kiểm tra ở controller, mã không hợp lệ ở đây bị bỏ qua.
     */
    public static function attachShop(User $newShop, string $code): void
    {
        $referrer = self::findShopByCode($code);
        if (! $referrer
            || $referrer->id === $newShop->id
            || ReferralService::isSamePerson($referrer->phone, $newShop->phone)) {
            return;
        }

        $newShop->forceFill(['referred_by_shop_id' => $referrer->id])->save();
        ShopReferral::create([
            'referrer_shop_id' => $referrer->id,
            'referred_shop_id' => $newShop->id,
            'status' => ShopReferral::PENDING,
            'required_orders' => OperationalSettings::shopReferralMinOrders($newShop->city_id),
        ]);
    }

    /**
     * Gọi sau khi một đơn của shop hoàn thành (trong transaction hoàn thành đơn).
     * Shop mới đủ số đơn tối thiểu thì cộng điểm cho shop giới thiệu.
     */
    public static function onOrderCompleted(Order $order): void
    {
        if ($order->platform !== 'shop_app' || ! $order->sender_platform_id) {
            return;
        }

        $referral = ShopReferral::where('referred_shop_id', $order->sender_platform_id)
            ->where('status', ShopReferral::PENDING)
            ->lockForUpdate()
            ->first();
        if (! $referral) {
            return;
        }

        $completed = Order::where('sender_platform_id', $referral->referred_shop_id)
            ->where('platform', 'shop_app')
            ->where('status', 'completed')
            ->count();

        if ($completed < max(1, (int) ($referral->required_orders ?: 1))) {
            return;
        }

        self::grantReward($referral, $order->city_id);
    }

    /**
     * Cộng điểm cho một lượt giới thiệu đang chờ (đủ điều kiện tự động hoặc admin
     * duyệt tay) và tặng voucher chào mừng cho shop mới. Trả về false nếu lượt
     * này không còn ở trạng thái chờ.
     */
    public static function grantReward(ShopReferral $referral, ?int $cityId): bool
    {
        $granted = DB::transaction(function () use ($referral, $cityId) {
            $locked = ShopReferral::whereKey($referral->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== ShopReferral::PENDING) {
                return null;
            }

            $points = OperationalSettings::shopReferralPoints($cityId);
            $referred = User::whereKey($locked->referred_shop_id)->first();

            if ($points > 0) {
                self::addPoints(
                    $locked->referrer_shop_id,
                    $points,
                    'referral',
                    'Giới thiệu shop '.($referred?->name ?: '#'.$locked->referred_shop_id),
                    "shop_ref_{$locked->referred_shop_id}",
                );
            }

            $welcome = null;
            $welcomeAmount = OperationalSettings::shopReferralWelcomeAmount($cityId);
            if ($welcomeAmount > 0 && $referred) {
                $welcome = self::issueVoucher(
                    $referred,
                    'fixed',
                    $welcomeAmount,
                    null,
                    null,
                    OperationalSettings::shopReferralWelcomeDays($cityId),
                    'Quà chào mừng shop mới được giới thiệu',
                    'WL',
                );
            }

            $now = now();
            $locked->update([
                'status' => ShopReferral::REWARDED,
                'points' => $points,
                'welcome_voucher_id' => $welcome?->id,
                'qualified_at' => $now,
                'rewarded_at' => $now,
            ]);

            return [$locked, $points, $welcome];
        });

        if (! $granted) {
            return false;
        }

        [$locked, $points, $welcome] = $granted;
        self::notify(
            $locked->referrer_shop_id,
            'Điểm thưởng giới thiệu',
            $points > 0
                ? "Bạn được cộng {$points} điểm vì shop bạn giới thiệu đã hoạt động. Vào Giới thiệu & tích điểm để đổi voucher."
                : 'Shop bạn giới thiệu đã hoạt động.',
        );
        if ($welcome) {
            self::notify(
                $locked->referred_shop_id,
                'Quà chào mừng',
                "Bạn nhận được voucher {$welcome->code} ({$welcome->discount_label}). Dùng khi tạo đơn tiếp theo.",
            );
        }

        return true;
    }

    /** Từ chối một lượt giới thiệu đang chờ (nghi gian lận, shop ảo...). */
    public static function reject(ShopReferral $referral, string $reason): bool
    {
        return ShopReferral::whereKey($referral->id)
            ->where('status', ShopReferral::PENDING)
            ->update(['status' => ShopReferral::REJECTED, 'reject_reason' => $reason, 'updated_at' => now()]) > 0;
    }

    /**
     * Cộng/trừ điểm kèm dòng sổ cái. Phải gọi trong transaction. [$reference]
     * duy nhất: gọi lại cùng reference là bỏ qua, không ghi trùng.
     */
    public static function addPoints(int $shopId, int $points, string $type, string $description, ?string $reference = null): int
    {
        $user = User::whereKey($shopId)->lockForUpdate()->firstOrFail();

        if ($reference && ShopPointTransaction::where('reference', $reference)->exists()) {
            return (int) $user->reward_points;
        }

        $balance = (int) $user->reward_points + $points;
        if ($balance < 0) {
            throw ValidationException::withMessages(['points' => 'Không đủ điểm']);
        }

        $user->forceFill(['reward_points' => $balance])->save();
        ShopPointTransaction::create([
            'shop_id' => $shopId,
            'type' => $type,
            'points' => $points,
            'balance_after' => $balance,
            'description' => $description,
            'reference' => $reference,
        ]);

        return $balance;
    }

    /**
     * Đổi điểm lấy voucher từ danh mục. Trừ điểm và tạo voucher riêng cho shop
     * trong cùng một transaction.
     *
     * @return array{voucher: Voucher, balance: int}
     */
    public static function redeem(User $shop, PointReward $reward): array
    {
        if (! $reward->is_active) {
            throw ValidationException::withMessages(['reward_id' => 'Phần quà này đã ngừng đổi']);
        }
        if ($shop->sharesPhoneWithDriver()) {
            throw ValidationException::withMessages([
                'reward_id' => 'Số điện thoại này đang được dùng cho tài khoản tài xế nên không đổi được voucher.',
            ]);
        }

        return DB::transaction(function () use ($shop, $reward) {
            $balance = self::addPoints(
                $shop->id,
                -$reward->points_cost,
                'redeem',
                'Đổi voucher: '.$reward->name,
            );

            $voucher = self::issueVoucher(
                $shop,
                $reward->type,
                (int) $reward->value,
                $reward->min_order_value,
                $reward->max_discount,
                (int) $reward->valid_days,
                $reward->name,
                'RW',
            );

            return ['voucher' => $voucher, 'balance' => $balance];
        });
    }

    /** Voucher dùng một lần, chỉ shop nhận mới dùng được. */
    private static function issueVoucher(
        User $shop,
        string $type,
        int $value,
        ?int $minOrder,
        ?int $maxDiscount,
        int $validDays,
        string $description,
        string $prefix,
    ): Voucher {
        do {
            $code = $prefix.ReferralService::generateUniqueCode().random_int(10, 99);
        } while (Voucher::where('code', $code)->exists());

        return Voucher::create([
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'description' => $description,
            'min_order_value' => $minOrder,
            'max_discount' => $maxDiscount,
            'audience' => 'shop',
            'user_id' => $shop->id,
            'city_id' => null,
            'expires_at' => now()->addDays(max(1, $validDays)),
            'usage_limit' => 1,
            'per_user_limit' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);
    }

    /** Thông báo trong app + push; lỗi gửi không được làm hỏng nghiệp vụ. */
    private static function notify(int $shopId, string $title, string $body): void
    {
        try {
            DB::table('customer_notifications')->insert([
                'user_id' => $shopId,
                'title' => $title,
                'body' => $body,
                'type' => 'referral',
                'is_read' => false,
                'created_at' => now(),
            ]);
            $token = User::whereKey($shopId)->value('fcm_token');
            if ($token) {
                FCMService::getInstance()->sendDriverNotice($token, $title, $body, ['type' => 'referral']);
            }
        } catch (\Throwable $e) {
            Log::warning('[ShopReferral] notify failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }
}
