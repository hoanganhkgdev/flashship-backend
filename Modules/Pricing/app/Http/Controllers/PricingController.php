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
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'delivery_lat'     => 'nullable|numeric',
            'delivery_lng'     => 'nullable|numeric',
            'pickup_address'   => 'nullable|string',
            'delivery_address' => 'nullable|string',
        ]);

        if (isset($data['pickup_lat'], $data['pickup_lng'], $data['delivery_lat'], $data['delivery_lng'])) {
            $result = PricingService::estimateFromCoords(
                $data['service_type'],
                (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                (float) $data['delivery_lat'], (float) $data['delivery_lng']
            );
        } elseif (isset($data['pickup_address'], $data['delivery_address'])) {
            $result = PricingService::estimateFromAddresses(
                $data['service_type'], $data['pickup_address'], $data['delivery_address']
            );
        } else {
            return response()->json(['success' => false, 'message' => 'Cần cung cấp tọa độ hoặc địa chỉ'], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
