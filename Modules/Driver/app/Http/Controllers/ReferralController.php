<?php

namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverReferral;

class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();

        $referrals = DriverReferral::with('shop:id,name')
            ->where('driver_id', $driver->id)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $driver->referral_code,
                'reward_amount' => OperationalSettings::referralRewardAmount($driver->city_id),
                'required_orders' => OperationalSettings::referralMinOrders($driver->city_id),
                'total_referred' => $referrals->count(),
                'total_rewarded' => (int) $referrals->where('status', DriverReferral::REWARDED)->sum('reward_amount'),
                'shops' => $referrals->map(fn (DriverReferral $r) => [
                    'shop_name' => $r->shop?->name,
                    'status' => $r->status,
                    'reward_amount' => $r->reward_amount,
                    'registered_at' => $r->created_at?->toIso8601String(),
                    'rewarded_at' => $r->rewarded_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }
}
