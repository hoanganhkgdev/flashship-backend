<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Voucher;
use Modules\Core\Models\VoucherUsage;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $cityId = $request->user()->city_id ?? null;

        $userId = $request->user()->id;

        // Trả cả lịch sử voucher để app có thể hiển thị đủ 3 nhóm:
        // khả dụng, đã dùng và hết hạn. Việc kiểm tra áp dụng voucher vẫn
        // được bảo vệ riêng trong validate().
        $vouchers = Voucher::query()
            ->forCity($cityId)
            ->forAudience('customer')
            ->forUser($userId)
            ->with(['usages' => fn ($query) => $query
                ->where('user_id', $userId)
                ->latest('used_at')])
            ->orderBy('expires_at')
            ->get()
            ->map(function (Voucher $v) {
                $usage = $v->usages->first();
                $isExpired = !$v->is_active
                    || ($v->expires_at && $v->expires_at->isPast())
                    || ($v->usage_limit && $v->used_count >= $v->usage_limit);
                $status = $usage ? 'used' : ($isExpired ? 'expired' : 'available');

                return [
                'id'               => $v->id,
                'code'             => $v->code,
                'type'             => $v->type,
                'value'            => $v->value,
                'description'      => $v->description,
                'discount_label'   => $v->discount_label,
                'min_order_value'  => $v->min_order_value,
                'max_discount'     => $v->max_discount,
                'service_types'    => $v->service_types,
                'expires_at'       => $v->expires_at?->toIso8601String(),
                'used_at'          => $usage?->used_at?->toIso8601String(),
                'status'           => $status,
                ];
            });

        return response()->json(['data' => $vouchers]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code'         => 'required|string',
            'service_type' => 'required|string',
            'order_total'  => 'nullable|integer|min:0',
            'shipping_fee' => 'nullable|integer|min:0',
        ]);

        $voucher = Voucher::where('code', strtoupper(trim($data['code'])))->first();

        if (!$voucher || !$voucher->is_active) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu'], 422);
        }
        if (!in_array($voucher->audience, ['all', 'customer']) || ($voucher->user_id && $voucher->user_id != $request->user()->id)) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu'], 422);
        }
        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết hạn sử dụng'], 422);
        }
        if ($voucher->usage_limit && $voucher->used_count >= $voucher->usage_limit) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng'], 422);
        }
        if ($voucher->per_user_limit) {
            $used = $voucher->usageCountByUser($request->user()->id);
            if ($used >= $voucher->per_user_limit) {
                $limit = $voucher->per_user_limit === 1
                    ? 'Mã này chỉ dùng được 1 lần cho mỗi tài khoản'
                    : "Bạn đã dùng mã này {$used}/{$voucher->per_user_limit} lần";
                return response()->json(['valid' => false, 'message' => $limit], 422);
            }
        }
        if ($voucher->service_types && !in_array($data['service_type'], $voucher->service_types)) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng cho dịch vụ này'], 422);
        }
        if ($voucher->city_id && $voucher->city_id != $request->user()->city_id) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng tại thành phố của bạn'], 422);
        }

        $orderTotal  = (int) ($data['order_total'] ?? 0);
        $shippingFee = (int) ($data['shipping_fee'] ?? 0);

        if ($voucher->min_order_value && $orderTotal < $voucher->min_order_value) {
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
            $discount = (int) round($orderTotal * $voucher->value / 100);
            if ($voucher->max_discount) {
                $discount = min($discount, $voucher->max_discount);
            }
            $discount = min($discount, $orderTotal);
        } else {
            $discount = min((int) $voucher->value, $orderTotal);
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
