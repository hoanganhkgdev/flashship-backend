<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Shop\Services\ShopPricingService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->with('driver:id,name,phone,latitude,longitude,profile_photo_path')
            ->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $orders->map(fn($o) => $this->formatOrder($o)),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'has_more'     => $orders->hasMorePages(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $base   = Order::where('sender_platform_id', $userId)->where('platform', 'shop_app');

        $total     = (clone $base)->count();
        $active    = (clone $base)->whereIn('status', ['pending','assigned','processing','on_the_way'])->count();
        $completed = (clone $base)->where('status', 'completed')->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();
        $revenue   = (clone $base)->where('status', 'completed')->sum('shipping_fee');

        // Hôm nay
        $today          = now()->startOfDay();
        $todayBase      = (clone $base)->whereDate('created_at', $today);
        $todayOrders    = (clone $todayBase)->count();
        $todayActive    = (clone $todayBase)->whereIn('status', ['pending','assigned','processing','on_the_way'])->count();
        $todayRevenue   = (clone $todayBase)->where('status', 'completed')->sum('shipping_fee');

        // Thống kê theo loại hàng
        $byCargoType = (clone $base)->where('status', 'completed')
            ->selectRaw('cargo_type, COUNT(*) as count')
            ->groupBy('cargo_type')
            ->pluck('count', 'cargo_type');

        // 7 ngày gần nhất
        $dailyOrders = (clone $base)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(CASE WHEN status = \'completed\' THEN shipping_fee ELSE 0 END) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'         => $total,
                'active'        => $active,
                'completed'     => $completed,
                'cancelled'     => $cancelled,
                'revenue'       => (int) $revenue,
                'today'         => [
                    'orders'  => $todayOrders,
                    'active'  => $todayActive,
                    'revenue' => (int) $todayRevenue,
                ],
                'by_cargo_type' => $byCargoType,
                'daily'         => $dailyOrders,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup_address'   => 'required|string',
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'pickup_phone'     => 'nullable|string',
            'pickup_name'      => 'nullable|string',
            'delivery_address' => 'required|string',
            'delivery_lat'     => 'nullable|numeric',
            'delivery_lng'     => 'nullable|numeric',
            'delivery_phone'   => 'required|string',
            'delivery_name'    => 'nullable|string',
            'order_note'       => 'nullable|string',
            'cargo_type'       => 'nullable|in:food,flowers,parcel',
            'cargo_note'       => 'nullable|string|max:500',
            'cargo_weight'     => 'nullable|numeric|min:0.1|max:999',
            'cod_amount'       => 'nullable|integer|min:0',
            'scheduled_at'     => 'nullable|date',
        ]);

        $user = $request->user();

        if (!$user->city_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản chưa được gán thành phố. Vui lòng liên hệ hỗ trợ.',
            ], 422);
        }

        try {
            $cargoType = $data['cargo_type'] ?? 'food';
            $weightKg  = isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null;

            if (isset($data['pickup_lat'], $data['pickup_lng'], $data['delivery_lat'], $data['delivery_lng'])) {
                $pricing = ShopPricingService::estimateFromCoords(
                    $cargoType,
                    (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                    (float) $data['delivery_lat'], (float) $data['delivery_lng'],
                    $weightKg
                );
            } else {
                $pricing = ShopPricingService::estimateFromAddresses(
                    $cargoType,
                    $data['pickup_address'],
                    $data['delivery_address'],
                    $weightKg
                );
            }

            $nightSurcharge = (int) ($pricing['night_surcharge'] ?? 0);

            $order = Order::create([
                'code'             => '',
                'sender_name'      => !empty($data['pickup_name']) ? $data['pickup_name'] : null,
                'store_name'       => $user->name,
                'pickup_phone'     => !empty($data['pickup_phone']) ? $data['pickup_phone'] : null,
                'pickup_address'   => $data['pickup_address'],
                'pickup_lat'       => $data['pickup_lat'] ?? null,
                'pickup_lng'       => $data['pickup_lng'] ?? null,
                'receiver_name'    => $data['delivery_name'] ?? '',
                'delivery_phone'   => $data['delivery_phone'],
                'delivery_address' => $data['delivery_address'],
                'delivery_lat'     => $data['delivery_lat'] ?? null,
                'delivery_lng'     => $data['delivery_lng'] ?? null,
                'service_type'     => 'delivery',
                'order_note'       => $data['order_note'] ?? '',
                'cargo_type'       => $data['cargo_type'] ?? 'food',
                'cargo_note'       => $data['cargo_note'] ?? null,
                'cargo_weight'     => isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null,
                'payment_method'   => 'cod',
                'cod_amount'       => $data['cod_amount'] ?? null,
                'city_id'          => $user->city_id,
                'shipping_fee'     => $pricing['fee'],
                'night_surcharge'  => $nightSurcharge,
                'distance'         => $pricing['distance_km'],
                'bonus_fee'        => 0,
                'is_freeship'      => false,
                'status'           => 'pending',
                'platform'         => 'shop_app',
                'sender_platform_id' => $user->id,
                'scheduled_at'     => $data['scheduled_at'] ?? null,
            ]);

            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                app(OrderService::class)->dispatchNewOrder($orderId);
            })->afterResponse();

            return response()->json([
                'success' => true,
                'message' => 'Tạo đơn thành công',
                'data'    => $this->formatOrder($order),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Shop createOrder: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra, vui lòng thử lại'], 500);
        }
    }

    // ── Đơn gộp nhiều điểm ────────────────────────────────────────────────────

    public function storeBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup_address'     => 'required|string',
            'pickup_lat'         => 'nullable|numeric',
            'pickup_lng'         => 'nullable|numeric',
            'pickup_phone'       => 'nullable|string',
            'pickup_name'        => 'nullable|string',
            'cargo_type'         => 'nullable|in:food,flowers,parcel',
            'cargo_weight'       => 'nullable|numeric|min:0',
            'order_note'         => 'nullable|string',
            'stops'              => 'required|array|min:1|max:10',
            'stops.*.address'    => 'required|string',
            'stops.*.lat'        => 'nullable|numeric',
            'stops.*.lng'        => 'nullable|numeric',
            'stops.*.phone'      => 'required|string',
            'stops.*.name'       => 'nullable|string',
            'stops.*.cod_amount' => 'nullable|integer|min:0',
            'stops.*.note'       => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user->city_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản chưa được gán thành phố.',
            ], 422);
        }

        try {
            $cargoType = $data['cargo_type'] ?? 'food';
            $weightKg  = isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null;
            $pickupLat = $data['pickup_lat'] ?? null;
            $pickupLng = $data['pickup_lng'] ?? null;

            // Tính phí từng stop độc lập (shop → stop)
            $stops    = [];
            $totalFee = 0;

            foreach ($data['stops'] as $i => $stop) {
                if ($pickupLat && isset($stop['lat'], $stop['lng'])) {
                    $pricing = ShopPricingService::estimateFromCoords(
                        $cargoType,
                        (float) $pickupLat, (float) $pickupLng,
                        (float) $stop['lat'], (float) $stop['lng'],
                        $weightKg
                    );
                } else {
                    $pricing = ShopPricingService::estimateFromAddresses(
                        $cargoType, $data['pickup_address'], $stop['address'], $weightKg
                    );
                }

                $stops[] = [
                    'seq'          => $i + 1,
                    'address'      => $stop['address'],
                    'lat'          => $stop['lat'] ?? null,
                    'lng'          => $stop['lng'] ?? null,
                    'phone'        => $stop['phone'],
                    'name'         => $stop['name'] ?? '',
                    'cod_amount'   => $stop['cod_amount'] ?? null,
                    'note'         => $stop['note'] ?? '',
                    'fee'          => $pricing['fee'],
                    'distance_km'  => $pricing['distance_km'],
                    'delivered_at' => null,
                ];
                $totalFee += $pricing['fee'];
            }

            $firstStop = $stops[0];

            $order = Order::create([
                'code'             => '',
                'sender_name'      => $data['pickup_name'] ?? $user->name,
                'store_name'       => $user->name,
                'pickup_phone'     => $data['pickup_phone'] ?? $user->phone,
                'pickup_address'   => $data['pickup_address'],
                'pickup_lat'       => $pickupLat,
                'pickup_lng'       => $pickupLng,
                // Điểm giao chính = stop đầu tiên
                'receiver_name'    => $firstStop['name'],
                'delivery_phone'   => $firstStop['phone'],
                'delivery_address' => $firstStop['address'],
                'delivery_lat'     => $firstStop['lat'],
                'delivery_lng'     => $firstStop['lng'],
                'service_type'     => 'delivery',
                'order_note'       => $data['order_note'] ?? '',
                'cargo_type'       => $cargoType,
                'cargo_weight'     => $weightKg,
                'is_batch'         => true,
                'stops'            => $stops,
                'payment_method'   => 'cod',
                'cod_amount'       => array_sum(array_column($stops, 'cod_amount')),
                'city_id'          => $user->city_id,
                'shipping_fee'     => $totalFee,
                'night_surcharge'  => 0,
                'distance'         => $stops[0]['distance_km'],
                'bonus_fee'        => 0,
                'is_freeship'      => false,
                'status'           => 'pending',
                'platform'         => 'shop_app',
                'sender_platform_id' => $user->id,
            ]);

            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                app(OrderService::class)->dispatchNewOrder($orderId);
            })->afterResponse();

            return response()->json([
                'success' => true,
                'message' => 'Tạo đơn gộp thành công',
                'data'    => $this->formatOrder($order),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Shop storeBatch: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra'], 500);
        }
    }

    public function deliverStop(string $code, int $seq, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->where('is_batch', true)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        $stops = $order->stops ?? [];
        $found = false;
        foreach ($stops as &$stop) {
            if ((int) $stop['seq'] === $seq) {
                $stop['delivered_at'] = now()->toIso8601String();
                $found = true;
                break;
            }
        }
        if (!$found) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy điểm giao'], 404);
        }

        $order->update(['stops' => $stops]);

        return response()->json(['success' => true, 'message' => "Đã giao điểm $seq"]);
    }

    public function show(string $code, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->with('driver:id,name,phone,latitude,longitude,profile_photo_path')
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatOrder($order, true)]);
    }

    public function cancel(string $code, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể hủy đơn khi chưa có tài xế nhận'], 400);
        }

        $dispatchingDriverId = $order->dispatching_to_driver_id;

        $order->update(['status' => 'cancelled']);
        RTDBService::clearOrder($order->code);

        if ($dispatchingDriverId) {
            RTDBService::clearDriverOffer($dispatchingDriverId);
        }

        return response()->json(['success' => true, 'message' => 'Đã hủy đơn hàng']);
    }

    public function rate(string $code, Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'note'   => 'nullable|string|max:500',
        ]);

        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('status', 'completed')
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }
        if ($order->driver_rating) {
            return response()->json(['success' => false, 'message' => 'Bạn đã đánh giá đơn hàng này rồi.'], 400);
        }
        if ($order->completed_at && $order->completed_at->diffInHours(now()) > 24) {
            return response()->json(['success' => false, 'message' => 'Đã quá 24 giờ, không thể đánh giá đơn hàng này.'], 400);
        }

        $affected = \DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('driver_rating')
            ->update([
                'driver_rating'      => $data['rating'],
                'driver_rating_note' => $data['note'] ?? null,
                'updated_at'         => now(),
            ]);

        if ($affected > 0 && $order->delivery_man_id) {
            DriverScoreService::onRated($order->delivery_man_id, (int) $data['rating']);
        }

        return response()->json(['success' => true, 'message' => 'Cảm ơn đánh giá của bạn']);
    }

    private function formatOrder(Order $order, bool $withTracking = false): array
    {
        $result = [
            'id'               => $order->id,
            'code'             => $order->code,
            'status'           => $order->status,
            'cancel_reason'    => $order->cancel_reason,
            'service_type'     => $order->service_type,
            'pickup_address'   => $order->pickup_address,
            'pickup_lat'       => $order->pickup_lat    ? (float) $order->pickup_lat    : null,
            'pickup_lng'       => $order->pickup_lng    ? (float) $order->pickup_lng    : null,
            'pickup_phone'     => $order->pickup_phone,
            'sender_name'      => $order->sender_name,
            'store_name'       => $order->store_name,
            'delivery_address' => $order->delivery_address,
            'delivery_lat'     => $order->delivery_lat  ? (float) $order->delivery_lat  : null,
            'delivery_lng'     => $order->delivery_lng  ? (float) $order->delivery_lng  : null,
            'delivery_phone'   => $order->delivery_phone,
            'receiver_name'    => $order->receiver_name,
            'shipping_fee'     => $order->shipping_fee,
            'distance_km'      => $order->distance ? (float) $order->distance : null,
            'order_note'       => $order->order_note,
            'cargo_type'       => $order->cargo_type ?? 'food',
            'cargo_note'       => $order->cargo_note,
            'cargo_weight'     => $order->cargo_weight ? (float) $order->cargo_weight : null,
            'payment_method'   => $order->payment_method,
            'cod_amount'       => $order->cod_amount,
            'night_surcharge'  => $order->night_surcharge ?? 0,
            'driver_rating'    => $order->driver_rating,
            'is_batch'         => (bool) $order->is_batch,
            'stops'            => $order->stops ?? [],
            'scheduled_at'     => $order->scheduled_at?->toIso8601String(),
            'created_at'       => $order->created_at->toIso8601String(),
            'driver'           => $order->driver ? [
                'id'         => $order->driver->id,
                'name'       => $order->driver->name,
                'phone'      => $order->driver->phone,
                'avatar_url' => $order->driver->profile_photo_path
                    ? asset('storage/' . $order->driver->profile_photo_path)
                    : null,
                'latitude'   => $order->driver->latitude  ? (float) $order->driver->latitude  : null,
                'longitude'  => $order->driver->longitude ? (float) $order->driver->longitude : null,
            ] : null,
        ];

        if ($withTracking) {
            $result['tracking'] = [
                'firebase_db_url'      => config('services.firebase.database_url'),
                'rtdb_path'            => "/flashship_main/orders/city_{$order->city_id}/order_{$order->id}",
                'driver_location_path' => $order->delivery_man_id ? "/flashship_main/locations/driver_{$order->delivery_man_id}" : null,
            ];
        }

        return $result;
    }
}
