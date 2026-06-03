<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shop\Services\ShopPricingService;

class PricingController extends Controller
{
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cargo_type'       => 'nullable|in:food,flowers,parcel',
            'cargo_weight'     => 'nullable|numeric|min:0',
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'delivery_lat'     => 'nullable|numeric',
            'delivery_lng'     => 'nullable|numeric',
            'pickup_address'   => 'nullable|string',
            'delivery_address' => 'nullable|string',
        ]);

        $cargoType = $data['cargo_type']   ?? 'food';
        $weightKg  = isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null;

        if (isset($data['pickup_lat'], $data['pickup_lng'],
                   $data['delivery_lat'], $data['delivery_lng'])) {
            $result = ShopPricingService::estimateFromCoords(
                $cargoType,
                (float) $data['pickup_lat'],   (float) $data['pickup_lng'],
                (float) $data['delivery_lat'],  (float) $data['delivery_lng'],
                $weightKg
            );
        } elseif (isset($data['pickup_address'], $data['delivery_address'])) {
            $result = ShopPricingService::estimateFromAddresses(
                $cargoType,
                $data['pickup_address'],
                $data['delivery_address'],
                $weightKg
            );
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Cần cung cấp tọa độ hoặc địa chỉ',
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
