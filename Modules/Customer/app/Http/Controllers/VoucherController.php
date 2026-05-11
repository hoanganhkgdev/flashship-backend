<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Voucher;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $cityId = $request->user()->city_id ?? null;

        $vouchers = Voucher::available()
            ->forCity($cityId)
            ->orderBy('expires_at')
            ->get()
            ->map(fn (Voucher $v) => [
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
            ]);

        return response()->json(['data' => $vouchers]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code'         => 'required|string',
            'service_type' => 'required|string',
            'order_total'  => 'nullable|integer|min:0',
        ]);

        $voucher = Voucher::where('code', strtoupper(trim($data['code'])))->first();

        if (!$voucher || !$voucher->is_active) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã bị vô hiệu'], 422);
        }
        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết hạn sử dụng'], 422);
        }
        if ($voucher->usage_limit && $voucher->used_count >= $voucher->usage_limit) {
            return response()->json(['valid' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng'], 422);
        }
        if ($voucher->service_types && !in_array($data['service_type'], $voucher->service_types)) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng cho dịch vụ này'], 422);
        }
        if ($voucher->city_id && $voucher->city_id != $request->user()->city_id) {
            return response()->json(['valid' => false, 'message' => 'Mã không áp dụng tại thành phố của bạn'], 422);
        }

        $orderTotal = (int) ($data['order_total'] ?? 0);

        if ($voucher->min_order_value && $orderTotal < $voucher->min_order_value) {
            return response()->json([
                'valid'   => false,
                'message' => 'Đơn hàng tối thiểu ' . number_format($voucher->min_order_value) . 'đ để dùng mã này',
            ], 422);
        }

        $discount = $voucher->type === 'percent'
            ? (int) round($orderTotal * $voucher->value / 100)
            : $voucher->value;

        if ($voucher->max_discount) {
            $discount = min($discount, $voucher->max_discount);
        }
        $discount = min($discount, $orderTotal);

        return response()->json([
            'valid'          => true,
            'code'           => $voucher->code,
            'discount'       => $discount,
            'discount_label' => $voucher->discount_label,
            'description'    => $voucher->description,
        ]);
    }
}
