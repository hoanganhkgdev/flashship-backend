<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
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
            ->pluck('id')
            ->all();

        // Mỗi khách mở màn đặt xe đều gọi API này; đọc cả nhánh vị trí Firebase mỗi lần là thừa — giữ 5 giây.
        sort($candidateIds);
        $locations = Cache::remember('nearby_drivers:'.md5(implode(',', $candidateIds)), 5,
            fn () => app(DriverLocationService::class)->freshLocationsFor($candidateIds));

        // Chỉ đủ để vẽ "xe gần bạn": không trả mã tài xế, toạ độ làm tròn ~110 m. Trước đây trả mã + toạ độ
        // chính xác cho mọi khách với toạ độ tuỳ ý → dò được vị trí từng tài xế đang rảnh.
        $drivers = collect($locations)
            ->filter(fn($loc) => $this->haversineKm($loc['lat'], $loc['lng'], $lat, $lng) <= $radius)
            ->shuffle()
            ->values()
            ->map(fn($loc, $i) => [
                'id'      => $i + 1,
                'lat'     => round($loc['lat'], 3),
                'lng'     => round($loc['lng'], 3),
                'bearing' => (int) round(($loc['bearing'] ?? 0) / 45) * 45,
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
