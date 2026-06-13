<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Voucher;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $vouchers = Voucher::available()
            ->forCity($user->city_id)
            ->forAudience('shop')
            ->forUser($user->id)
            ->orderBy('expires_at')
            ->get()
            ->map(fn (Voucher $v) => [
                'id'              => $v->id,
                'code'            => $v->code,
                'type'            => $v->type,
                'value'           => $v->value,
                'description'     => $v->description,
                'discount_label'  => $v->discount_label,
                'min_order_value' => $v->min_order_value,
                'max_discount'    => $v->max_discount,
                'expires_at'      => $v->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $vouchers]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code'         => 'required|string',
            'shipping_fee' => 'nullable|integer|min:0',
        ]);

        $user    = $request->user();
        $voucher = Voucher::where('code', strtoupper(trim($data['code'])))->first();

        if (!$voucher || !$voucher->is_active) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu'], 422);
        }
        if (!in_array($voucher->audience, ['all', 'shop']) || ($voucher->user_id && $voucher->user_id != $user->id)) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu'], 422);
        }
        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết hạn sử dụng'], 422);
        }
        if ($voucher->usage_limit && $voucher->used_count >= $voucher->usage_limit) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng'], 422);
        }
        if ($voucher->per_user_limit) {
            $used = $voucher->usageCountByUser($user->id);
            if ($used >= $voucher->per_user_limit) {
                $limit = $voucher->per_user_limit === 1
                    ? 'Mã này chỉ dùng được 1 lần cho mỗi tài khoản'
                    : "Bạn đã dùng mã này {$used}/{$voucher->per_user_limit} lần";
                return response()->json(['valid' => false, 'message' => $limit], 422);
            }
        }
        if ($voucher->service_types && !in_array('delivery', $voucher->service_types)) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng cho dịch vụ này'], 422);
        }
        if ($voucher->city_id && $voucher->city_id != $user->city_id) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng tại thành phố của bạn'], 422);
        }

        $shippingFee = (int) ($data['shipping_fee'] ?? 0);

        if ($voucher->min_order_value && $shippingFee < $voucher->min_order_value) {
            return response()->json([
                'valid'   => false,
                'message' => 'Đơn hàng tối thiểu ' . number_format($voucher->min_order_value) . 'đ để dùng mã này',
            ], 422);
        }

        if ($voucher->type === 'freeship') {
            $discount = $shippingFee;
            if ($voucher->max_discount) {
                $discount = min($discount, $voucher->max_discount);
            }
        } elseif ($voucher->type === 'percent') {
            $discount = (int) round($shippingFee * $voucher->value / 100);
            if ($voucher->max_discount) {
                $discount = min($discount, $voucher->max_discount);
            }
            $discount = min($discount, $shippingFee);
        } else {
            $discount = min((int) $voucher->value, $shippingFee);
        }

        return response()->json([
            'valid'          => true,
            'code'           => $voucher->code,
            'type'           => $voucher->type,
            'discount'       => $discount,
            'discount_label' => $voucher->discount_label,
            'description'    => $voucher->description,
            'is_freeship'    => $voucher->type === 'freeship',
        ]);
    }
}
