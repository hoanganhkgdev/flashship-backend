<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\OperationalSettings;
use Modules\Shop\Models\PointReward;
use Modules\Shop\Models\ShopPointTransaction;
use Modules\Shop\Models\ShopReferral;
use Modules\Shop\Services\ShopReferralService;

class ReferralController extends Controller
{
    /** Mã giới thiệu, điểm hiện có, các lượt đã mời và lịch sử điểm. */
    public function show(Request $request): JsonResponse
    {
        $shop = $request->user();

        $referrals = ShopReferral::with('referred:id,name')
            ->where('referrer_shop_id', $shop->id)
            ->latest('id')
            ->limit(50)
            ->get();

        $transactions = ShopPointTransaction::where('shop_id', $shop->id)
            ->latest('id')
            ->limit(30)
            ->get(['id', 'type', 'points', 'balance_after', 'description', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $shop->referral_code,
                'points' => (int) $shop->reward_points,
                'program' => [
                    'points_per_referral' => OperationalSettings::shopReferralPoints($shop->city_id),
                    'min_orders' => OperationalSettings::shopReferralMinOrders($shop->city_id),
                    'welcome_amount' => OperationalSettings::shopReferralWelcomeAmount($shop->city_id),
                ],
                'stats' => [
                    'total' => $referrals->count(),
                    'rewarded' => $referrals->where('status', ShopReferral::REWARDED)->count(),
                    'pending' => $referrals->where('status', ShopReferral::PENDING)->count(),
                ],
                'referrals' => $referrals->map(fn (ShopReferral $r) => [
                    'id' => $r->id,
                    'shop_name' => $r->referred?->name ?? 'Shop',
                    'status' => $r->status,
                    'points' => $r->points,
                    'required_orders' => (int) $r->required_orders,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
                'transactions' => $transactions->map(fn (ShopPointTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'points' => (int) $t->points,
                    'balance_after' => (int) $t->balance_after,
                    'description' => $t->description,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /** Danh mục voucher có thể đổi bằng điểm. */
    public function rewards(): JsonResponse
    {
        $rewards = PointReward::active()->get()->map(fn (PointReward $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'points_cost' => (int) $r->points_cost,
            'type' => $r->type,
            'value' => (int) $r->value,
            'min_order_value' => $r->min_order_value,
            'max_discount' => $r->max_discount,
            'valid_days' => (int) $r->valid_days,
        ]);

        return response()->json(['success' => true, 'data' => $rewards]);
    }

    /** Đổi điểm lấy voucher: trừ điểm và cấp voucher riêng cho shop. */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['reward_id' => 'required|integer|exists:point_rewards,id']);

        $result = ShopReferralService::redeem($request->user(), PointReward::findOrFail($data['reward_id']));
        $voucher = $result['voucher'];

        return response()->json([
            'success' => true,
            'message' => 'Đổi voucher thành công',
            'data' => [
                'balance' => $result['balance'],
                'voucher' => [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'discount_label' => $voucher->discount_label,
                    'description' => $voucher->description,
                    'expires_at' => $voucher->expires_at?->toIso8601String(),
                ],
            ],
        ], 201);
    }
}
