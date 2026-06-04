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

    /**
     * Ước tính phí cho đơn gộp nhiều điểm.
     * Mỗi stop tính phí độc lập từ pickup (shop) → stop đó.
     */
    public function estimateBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cargo_type'         => 'nullable|in:food,flowers,parcel',
            'pickup_lat'         => 'nullable|numeric',
            'pickup_lng'         => 'nullable|numeric',
            'pickup_address'     => 'nullable|string',
            'stops'              => 'required|array|min:1|max:10',
            'stops.*.address'    => 'required|string',
            'stops.*.lat'        => 'nullable|numeric',
            'stops.*.lng'        => 'nullable|numeric',
            'stops.*.cargo_weight' => 'nullable|numeric|min:0',
        ]);

        $cargoType   = $data['cargo_type'] ?? 'food';
        $pickupLat   = $data['pickup_lat']  ?? null;
        $pickupLng   = $data['pickup_lng']  ?? null;
        $pickupAddr  = $data['pickup_address'] ?? null;

        $stops    = [];
        $totalFee = 0;

        foreach ($data['stops'] as $i => $stop) {
            $weightKg = isset($stop['cargo_weight'])
                ? (float) $stop['cargo_weight'] : null;

            if ($pickupLat && isset($stop['lat'], $stop['lng'])) {
                $result = ShopPricingService::estimateFromCoords(
                    $cargoType,
                    (float) $pickupLat, (float) $pickupLng,
                    (float) $stop['lat'], (float) $stop['lng'],
                    $weightKg
                );
            } elseif ($pickupAddr && !empty($stop['address'])) {
                $result = ShopPricingService::estimateFromAddresses(
                    $cargoType,
                    $pickupAddr,
                    $stop['address'],
                    $weightKg
                );
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Cần cung cấp tọa độ hoặc địa chỉ cho điểm lấy và điểm giao',
                ], 422);
            }

            $stops[] = [
                'seq'         => $i + 1,
                'address'     => $stop['address'],
                'distance_km' => $result['distance_km'],
                'fee'         => $result['fee'],
            ];
            $totalFee += $result['fee'];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'stops'     => $stops,
                'total_fee' => $totalFee,
            ],
        ]);
    }
}
