<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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

        $busyIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotIn('id', $busyIds)
            ->where(function ($q) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', now());
            })
            ->select('id', 'latitude', 'longitude')
            ->get()
            ->filter(function ($d) use ($lat, $lng, $radius) {
                return $this->haversineKm(
                    (float) $d->latitude, (float) $d->longitude,
                    $lat, $lng
                ) <= $radius;
            })
            ->values()
            ->map(fn($d) => [
                'id'  => $d->id,
                'lat' => (float) $d->latitude,
                'lng' => (float) $d->longitude,
            ]);

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
