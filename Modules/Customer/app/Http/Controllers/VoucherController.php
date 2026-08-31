<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\VoucherService;

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
                $usedByUser = $v->usages->count();
                $isExpired = ! $v->is_active
                    || ($v->expires_at && $v->expires_at->isPast())
                    || ($v->usage_limit && $v->used_count >= $v->usage_limit);
                $reachedUserLimit = $v->per_user_limit
                    && $usedByUser >= $v->per_user_limit;
                $status = $isExpired
                    ? 'expired'
                    : ($reachedUserLimit ? 'used' : 'available');

                return [
                    'id' => $v->id,
                    'code' => $v->code,
                    'type' => $v->type,
                    'value' => $v->value,
                    'description' => $v->description,
                    'discount_label' => $v->discount_label,
                    'min_order_value' => $v->min_order_value,
                    'max_discount' => $v->max_discount,
                    'service_types' => $v->service_types,
                    'expires_at' => $v->expires_at?->toIso8601String(),
                    'used_at' => $usage?->used_at?->toIso8601String(),
                    'used_count_by_user' => $usedByUser,
                    'per_user_limit' => $v->per_user_limit,
                    'status' => $status,
                ];
            });

        return response()->json(['data' => $vouchers]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'service_type' => 'required|string',
            'order_total' => 'nullable|integer|min:0',
            'shipping_fee' => 'nullable|integer|min:0',
        ]);

        $shippingFee = (int) ($data['shipping_fee'] ?? 0);
        $result = app(VoucherService::class)->preview(
            $data['code'],
            $request->user(),
            'customer',
            $data['service_type'],
            $shippingFee,
        );

        return response()->json($result, $result['valid'] ? 200 : 422);
    }
}
