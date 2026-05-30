<?php
namespace Modules\Pricing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pricing\Models\PricingConfig;
use Modules\Pricing\Services\PricingService;

class PricingController extends Controller
{
    public function configs(): JsonResponse
    {
        $configs = PricingConfig::where('is_active', true)->get();
        return response()->json(['success' => true, 'data' => $configs]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_type'     => 'required|in:delivery,shopping,topup,bike,motor,car',
            'topup_amount'     => 'nullable|integer|min:1',
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'delivery_lat'     => 'nullable|numeric',
            'delivery_lng'     => 'nullable|numeric',
            'pickup_address'   => 'nullable|string',
            'delivery_address' => 'nullable|string',
            'stop_count'       => 'nullable|integer|min:1|max:5',
        ]);

        // Topup fee is based solely on the recharge amount, not distance
        $cityId = $request->user()?->city_id;

        if ($data['service_type'] === 'topup') {
            $amount    = (int) ($data['topup_amount'] ?? 0);
            $surcharge = PricingService::nightSurcharge();
            return response()->json(['success' => true, 'data' => [
                'service_type'    => 'topup',
                'distance_km'     => 0,
                'fee'             => PricingService::topupFee($amount, $cityId) + $surcharge,
                'night_surcharge' => $surcharge,
            ]]);
        }

        if (isset($data['pickup_lat'], $data['pickup_lng'], $data['delivery_lat'], $data['delivery_lng'])) {
            $result = PricingService::estimateFromCoords(
                $data['service_type'],
                (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                (float) $data['delivery_lat'], (float) $data['delivery_lng'],
                $cityId
            );
        } elseif (isset($data['pickup_address'], $data['delivery_address'])) {
            $result = PricingService::estimateFromAddresses(
                $data['service_type'], $data['pickup_address'], $data['delivery_address'], null, $cityId
            );
        } else {
            return response()->json(['success' => false, 'message' => 'Cần cung cấp tọa độ hoặc địa chỉ'], 422);
        }

        if ($data['service_type'] === 'shopping') {
            $stopCount       = max(1, (int) ($data['stop_count'] ?? 1));
            $result['fee']  += ($stopCount - 1) * 5000;
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
