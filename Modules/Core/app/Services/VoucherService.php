<?php

namespace Modules\Core\Services;

use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Voucher;

class VoucherService
{
    public function preview(?string $code, $user, string $audience, string $serviceType, int $discountBase): array
    {
        $voucher = $code ? Voucher::where('code', strtoupper(trim($code)))->first() : null;
        $result = $this->evaluate($voucher, $user, $audience, $serviceType, $discountBase);
        unset($result['voucher']);

        return $result;
    }

    /** Call inside the same transaction that creates the order and usage. */
    public function redeem(?string $code, $user, string $audience, string $serviceType, int $discountBase): ?array
    {
        if (! $code) {
            return null;
        }

        $voucher = Voucher::where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        $result = $this->evaluate($voucher, $user, $audience, $serviceType, $discountBase);
        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'voucher_code' => $result['message'],
            ]);
        }

        $voucher->increment('used_count');

        return [
            'voucher' => $voucher,
            'code' => $voucher->code,
            'discount_amount' => $result['discount'],
            'is_freeship' => $voucher->type === 'freeship',
        ];
    }

    public function evaluate(?Voucher $voucher, $user, string $audience, string $serviceType, int $discountBase): array
    {
        $invalid = fn (string $reason, string $message) => [
            'valid' => false, 'reason_code' => $reason, 'message' => $message,
        ];

        if (! $voucher || ! $voucher->is_active) {
            return $invalid('NOT_FOUND', 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu');
        }
        if (! in_array($voucher->audience, ['all', $audience], true)
            || ($voucher->user_id && (int) $voucher->user_id !== (int) $user->id)) {
            return $invalid('NOT_ELIGIBLE', 'Mã giảm giá không áp dụng cho tài khoản này');
        }
        if ($voucher->expires_at && ! $voucher->expires_at->isFuture()) {
            return $invalid('EXPIRED', 'Mã giảm giá đã hết hạn sử dụng');
        }
        if ($voucher->usage_limit && $voucher->used_count >= $voucher->usage_limit) {
            return $invalid('USAGE_LIMIT_REACHED', 'Mã giảm giá đã hết lượt sử dụng');
        }

        $used = $voucher->usageCountByUser((int) $user->id);
        if ($voucher->per_user_limit && $used >= $voucher->per_user_limit) {
            $message = $voucher->per_user_limit === 1
                ? 'Mã này chỉ dùng được 1 lần cho mỗi tài khoản'
                : "Bạn đã dùng mã này {$used}/{$voucher->per_user_limit} lần";

            return $invalid('USER_LIMIT_REACHED', $message);
        }
        if ($voucher->service_types && ! in_array($serviceType, $voucher->service_types, true)) {
            return $invalid('SERVICE_NOT_SUPPORTED', 'Mã không áp dụng cho dịch vụ này');
        }
        if ($voucher->city_id && (int) $voucher->city_id !== (int) $user->city_id) {
            return $invalid('CITY_NOT_SUPPORTED', 'Mã không áp dụng tại thành phố của bạn');
        }

        $discountBase = max(0, $discountBase);
        if ($voucher->min_order_value && $discountBase < $voucher->min_order_value) {
            return $invalid('MIN_ORDER_NOT_MET', 'Đơn hàng tối thiểu '.number_format($voucher->min_order_value).'đ để dùng mã này');
        }

        $discount = match ($voucher->type) {
            'freeship' => $discountBase,
            'percent' => (int) round($discountBase * $voucher->value / 100),
            default => (int) $voucher->value,
        };
        if ($voucher->max_discount) {
            $discount = min($discount, (int) $voucher->max_discount);
        }
        $discount = min(max(0, $discount), $discountBase);

        return [
            'valid' => true,
            'reason_code' => null,
            'message' => null,
            'voucher' => $voucher,
            'code' => $voucher->code,
            'type' => $voucher->type,
            'discount' => $discount,
            'discount_base' => $discountBase,
            'final_amount' => $discountBase - $discount,
            'discount_label' => $voucher->discount_label,
            'description' => $voucher->description,
            'is_freeship' => $voucher->type === 'freeship',
        ];
    }
}
