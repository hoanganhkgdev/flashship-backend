<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\VoucherService;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Trước đây where('city_id', ...)/('user_id', ...) exact-match làm ẩn
        // mất voucher broadcast (city_id/user_id NULL = áp dụng mọi nơi/mọi
        // người) — lệch với logic thật ở validate() bên dưới (chấp nhận NULL).
        // Dùng lại đúng scope NULL-inclusive có sẵn trên model.
        $vouchers = Voucher::available()
            ->forCity($user->city_id)
            ->forAudience('shop')
            ->forUser($user->id)
            ->orderBy('expires_at')
            ->get()
            ->map(fn (Voucher $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'type' => $v->type,
                'value' => $v->value,
                'description' => $v->description,
                'discount_label' => $v->discount_label,
                'min_order_value' => $v->min_order_value,
                'max_discount' => $v->max_discount,
                'expires_at' => $v->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $vouchers]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'shipping_fee' => 'nullable|integer|min:0',
        ]);

        $shippingFee = (int) ($data['shipping_fee'] ?? 0);
        $result = app(VoucherService::class)->preview(
            $data['code'],
            $request->user(),
            'shop',
            'delivery',
            $shippingFee,
        );

        return response()->json($result, $result['valid'] ? 200 : 422);
    }
}
