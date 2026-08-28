<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;

class NearbyDriversController extends Controller
{
    public function index(Request $request)
    {
        $lat    = (float) $request->query('lat', 0);
        $lng    = (float) $request->query('lng', 0);
        $radius = min((float) $request->query('radius', 5), 10); // tối đa 10km

        if (!$lat || !$lng) {
            return response()->json(['data' => []]);
        }

        $busyIds = Order::whereIn('status', ['assigned', 'processing'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $cityId = $request->user()->city_id;

        $candidateIds = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where('is_online', true)
            ->when($cityId, fn($q) => $q->where('city_id', $cityId))
            ->whereNotIn('id', $busyIds)
            ->where(function ($q) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', now());
            })
            ->pluck('id')
            ->all();

        $locations = app(DriverLocationService::class)->freshLocationsFor($candidateIds);

        $drivers = collect($locations)
            ->filter(fn($loc) => $this->haversineKm($loc['lat'], $loc['lng'], $lat, $lng) <= $radius)
            ->map(fn($loc, $id) => [
                'id'      => $id,
                'lat'     => $loc['lat'],
                'lng'     => $loc['lng'],
                'bearing' => $loc['bearing'] ?? 0,
            ])
            ->values();

        return response()->json(['data' => $drivers]);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
