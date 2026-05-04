<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Pricing\Services\PricingService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('sender_platform_id', $request->user()->id)
            ->where('platform', 'customer_app')
            ->with('driver:id,name,phone')
            ->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $orders->items(),
            'meta'    => ['current_page' => $orders->currentPage(), 'has_more' => $orders->hasMorePages(), 'total' => $orders->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_type'     => 'required|in:delivery,shopping,topup,bike,motor,car',
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
            'store_name'       => 'nullable|string',
            'payment_method'   => 'nullable|in:cod,prepaid,wallet',
            'cod_amount'       => 'nullable|integer|min:0',
            'scheduled_at'     => 'nullable|date',
        ]);

        $user = $request->user();

        try {
            if (isset($data['pickup_lat'], $data['pickup_lng'], $data['delivery_lat'], $data['delivery_lng'])) {
                $pricing = PricingService::estimateFromCoords(
                    $data['service_type'],
                    (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                    (float) $data['delivery_lat'], (float) $data['delivery_lng']
                );
            } else {
                $pricing = PricingService::estimateFromAddresses(
                    $data['service_type'], $data['pickup_address'], $data['delivery_address'], $user->city?->name
                );
            }

            $order = Order::create([
                'code'             => 'FS' . now()->format('ymdHis') . strtoupper(Str::random(3)),
                'sender_name'      => $data['pickup_name'] ?? '',
                'pickup_phone'     => $data['pickup_phone'] ?? '',
                'pickup_address'   => $data['pickup_address'],
                'pickup_lat'       => $data['pickup_lat'] ?? null,
                'pickup_lng'       => $data['pickup_lng'] ?? null,
                'receiver_name'    => $data['delivery_name'] ?? '',
                'delivery_phone'   => $data['delivery_phone'],
                'delivery_address' => $data['delivery_address'],
                'delivery_lat'     => $data['delivery_lat'] ?? null,
                'delivery_lng'     => $data['delivery_lng'] ?? null,
                'service_type'     => $data['service_type'],
                'order_note'       => $data['order_note'] ?? '',
                'store_name'       => $data['store_name'] ?? null,
                'payment_method'   => $data['payment_method'] ?? 'prepaid',
                'cod_amount'       => ($data['payment_method'] ?? '') === 'cod' ? ($data['cod_amount'] ?? 0) : null,
                'city_id'          => $user->city_id,
                'shipping_fee'     => $pricing['fee'],
                'distance'         => $pricing['distance_km'],
                'bonus_fee'        => 0,
                'is_freeship'      => false,
                'status'           => 'pending',
                'platform'         => 'customer_app',
                'sender_platform_id' => $user->id,
                'scheduled_at'     => $data['scheduled_at'] ?? null,
            ]);

            // Trigger dispatch after response
            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                app(OrderService::class)->dispatchNewOrder($orderId);
            })->afterResponse();

            return response()->json(['success' => true, 'message' => 'Đặt đơn thành công', 'data' => $this->formatOrder($order)], 201);
        } catch (\Throwable $e) {
            Log::error('Customer createOrder: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra, vui lòng thử lại'], 500);
        }
    }

    public function show(string $code, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'customer_app')
            ->with('driver:id,name,phone')->first();

        if (!$order) return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);

        return response()->json(['success' => true, 'data' => $this->formatOrder($order, true)]);
    }

    public function cancel(string $code, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'customer_app')->first();

        if (!$order) return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        if ($order->status !== 'pending') return response()->json(['success' => false, 'message' => 'Chỉ có thể hủy đơn khi chưa có tài xế nhận'], 400);

        $order->update(['status' => 'cancelled']);
        // TODO: Firebase removeOrder($order)

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
            ->where('status', 'completed')->first();

        if (!$order) return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        if ($order->driver_rating) return response()->json(['success' => false, 'message' => 'Bạn đã đánh giá đơn hàng này'], 400);

        $order->update(['driver_rating' => $data['rating'], 'driver_rating_note' => $data['note'] ?? null]);

        return response()->json(['success' => true, 'message' => 'Cảm ơn đánh giá của bạn']);
    }

    private function formatOrder(Order $order, bool $withTracking = false): array
    {
        $result = [
            'id'               => $order->id,
            'code'             => $order->code,
            'status'           => $order->status,
            'service_type'     => $order->service_type,
            'pickup_address'   => $order->pickup_address,
            'pickup_phone'     => $order->pickup_phone,
            'sender_name'      => $order->sender_name,
            'delivery_address' => $order->delivery_address,
            'delivery_phone'   => $order->delivery_phone,
            'receiver_name'    => $order->receiver_name,
            'shipping_fee'     => $order->shipping_fee,
            'distance_km'      => $order->distance,
            'order_note'       => $order->order_note,
            'store_name'       => $order->store_name,
            'payment_method'   => $order->payment_method,
            'cod_amount'       => $order->cod_amount,
            'driver_rating'    => $order->driver_rating,
            'scheduled_at'     => $order->scheduled_at?->toIso8601String(),
            'created_at'       => $order->created_at->toIso8601String(),
            'driver'           => $order->driver ? ['name' => $order->driver->name, 'phone' => $order->driver->phone] : null,
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
