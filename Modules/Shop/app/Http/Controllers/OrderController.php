<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Voucher;
use Modules\Core\Models\VoucherUsage;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Shop\Http\Requests\StoreBatchOrderRequest;
use Modules\Shop\Http\Requests\StoreOrderRequest;
use Modules\Shop\Services\ShopPricingService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Order::where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->with('driver:id,name,phone,latitude,longitude,profile_photo_path')
            ->latest();

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                    ->orWhere('delivery_phone', 'like', "%{$q}%")
                    ->orWhere('receiver_name', 'like', "%{$q}%")
                    ->orWhere('sender_name', 'like', "%{$q}%")
                    ->orWhere('pickup_phone', 'like', "%{$q}%")
                    ->orWhere('delivery_address', 'like', "%{$q}%");
            });
        }

        $orders = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders->map(fn ($o) => $this->formatOrder($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'has_more' => $orders->hasMorePages(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $base = Order::where('sender_platform_id', $userId)->where('platform', 'shop_app');

        $validated = $request->validate([
            'period' => 'nullable|string|in:today,week,month',
        ]);
        $period = $validated['period'] ?? 'week';
        $periodStart = match ($period) {
            'today' => now()->startOfDay(),
            'month' => now()->startOfMonth(),
            default => now()->startOfWeek(), // week
        };

        // total/active/completed/cancelled/revenue/by_cargo_type lọc theo
        // period — clone riêng từ $base thay vì gán where thẳng lên $base,
        // vì $dailyOrders bên dưới cũng clone từ $base và PHẢI giữ nguyên
        // không phụ thuộc period (biểu đồ xu hướng 7 ngày cố định).
        $periodBase = (clone $base)->where('created_at', '>=', $periodStart);

        $total = (clone $periodBase)->count();
        $active = (clone $periodBase)->whereIn('status', ['pending', 'assigned', 'processing', 'on_the_way'])->count();
        $completed = (clone $periodBase)->where('status', 'completed')->count();
        $cancelled = (clone $periodBase)->where('status', 'cancelled')->count();
        $revenue = (clone $periodBase)->where('status', 'completed')->sum('shipping_fee');

        // Hôm nay
        $today = now()->startOfDay();
        $todayBase = (clone $base)->whereDate('created_at', $today);
        $todayOrders = (clone $todayBase)->count();
        $todayActive = (clone $todayBase)->whereIn('status', ['pending', 'assigned', 'processing', 'on_the_way'])->count();
        $todayCompleted = (clone $todayBase)->where('status', 'completed')->count();
        $todayRevenue = (clone $todayBase)->where('status', 'completed')->sum('shipping_fee');

        // Thống kê theo loại hàng
        $byCargoType = (clone $periodBase)->where('status', 'completed')
            ->selectRaw('cargo_type, COUNT(*) as count')
            ->groupBy('cargo_type')
            ->pluck('count', 'cargo_type');

        // 7 ngày gần nhất — cố định, KHÔNG áp dụng period
        $dailyOrders = (clone $base)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(CASE WHEN status = \'completed\' THEN shipping_fee ELSE 0 END) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'total' => $total,
                'active' => $active,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'revenue' => (int) $revenue,
                'today' => [
                    'orders' => $todayOrders,
                    'active' => $todayActive,
                    'completed' => $todayCompleted,
                    'revenue' => (int) $todayRevenue,
                ],
                'by_cargo_type' => $byCargoType,
                'daily' => $dailyOrders,
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $request->user();

        if (! $user->city_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản chưa được gán thành phố. Vui lòng liên hệ hỗ trợ.',
            ], 422);
        }

        try {
            $cargoType = $data['cargo_type'] ?? 'food';
            $weightKg = isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null;

            if (isset($data['pickup_lat'], $data['pickup_lng'], $data['delivery_lat'], $data['delivery_lng'])) {
                $pricing = ShopPricingService::estimateFromCoords(
                    $cargoType,
                    (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                    (float) $data['delivery_lat'], (float) $data['delivery_lng'],
                    $weightKg,
                    $user->city_id
                );
            } else {
                $pricing = ShopPricingService::estimateFromAddresses(
                    $cargoType,
                    $data['pickup_address'],
                    $data['delivery_address'],
                    $weightKg,
                    $user->city_id
                );
            }

            $nightSurcharge = (int) ($pricing['night_surcharge'] ?? 0);

            // Voucher, order và usage phải commit/rollback cùng nhau. Nếu
            // Order::create() hoặc VoucherUsage::create() lỗi thì used_count
            // cũng được rollback, không làm mất lượt voucher của shop.
            $order = \DB::transaction(function () use ($data, $user, $pricing, $nightSurcharge) {
                $applied = $this->tryApplyVoucher($data['voucher_code'] ?? null, $user, $pricing['fee']);
                $voucherCode = $applied['code'] ?? null;
                $discountAmount = $applied['discount_amount'] ?? 0;
                $isFreeship = $applied['is_freeship'] ?? false;
                $shippingFee = $pricing['fee'] - $discountAmount;

                $order = Order::create([
                    'code' => '',
                    'sender_name' => ! empty($data['pickup_name']) ? $data['pickup_name'] : null,
                    'store_name' => $user->name,
                    'pickup_phone' => ! empty($data['pickup_phone']) ? $data['pickup_phone'] : null,
                    'pickup_address' => $data['pickup_address'],
                    'pickup_place_name' => $data['pickup_place_name'] ?? null,
                    'pickup_lat' => $data['pickup_lat'] ?? null,
                    'pickup_lng' => $data['pickup_lng'] ?? null,
                    'receiver_name' => $data['delivery_name'] ?? '',
                    'delivery_phone' => $data['delivery_phone'],
                    'delivery_address' => $data['delivery_address'],
                    'delivery_place_name' => $data['delivery_place_name'] ?? null,
                    'delivery_lat' => $data['delivery_lat'] ?? null,
                    'delivery_lng' => $data['delivery_lng'] ?? null,
                    'service_type' => 'delivery',
                    'shop_service_type' => filter_var($data['is_outbound'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 'shop_delivery' : 'shop_pickup',
                    'order_note' => $data['order_note'] ?? '',
                    'cargo_type' => $data['cargo_type'] ?? 'food',
                    'cargo_note' => $data['cargo_note'] ?? null,
                    'cargo_weight' => isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null,
                    'payment_method' => 'cod',
                    'cod_amount' => $data['cod_amount'] ?? null,
                    'city_id' => $user->city_id,
                    'shipping_fee' => $shippingFee,
                    'night_surcharge' => $nightSurcharge,
                    'distance' => $pricing['distance_km'],
                    'voucher_code' => $voucherCode,
                    'discount_amount' => $discountAmount,
                    'bonus_fee' => 0,
                    'is_freeship' => $isFreeship,
                    'status' => 'pending',
                    'platform' => 'shop_app',
                    'sender_platform_id' => $user->id,
                ]);

                if ($applied) {
                    VoucherUsage::create([
                        'voucher_id' => $applied['voucher']->id,
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'used_at' => now(),
                    ]);
                }

                return $order;
            });

            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                app(OrderService::class)->dispatchNewOrder($orderId);
            })->afterResponse();

            return response()->json([
                'success' => true,
                'message' => 'Tạo đơn thành công',
                'data' => $this->formatOrder($order),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Shop createOrder: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra, vui lòng thử lại'], 500);
        }
    }

    // ── Đơn gộp nhiều điểm ────────────────────────────────────────────────────

    public function storeBatch(StoreBatchOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $request->user();
        if (! $user->city_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản chưa được gán thành phố.',
            ], 422);
        }

        try {
            $cargoType = $data['cargo_type'] ?? 'food';
            $weightKg = isset($data['cargo_weight']) ? (float) $data['cargo_weight'] : null;
            $pickupLat = $data['pickup_lat'] ?? null;
            $pickupLng = $data['pickup_lng'] ?? null;

            // Tính phí từng stop độc lập (shop → stop)
            $stops = [];
            $totalFee = 0;

            foreach ($data['stops'] as $i => $stop) {
                if ($pickupLat && isset($stop['lat'], $stop['lng'])) {
                    $pricing = ShopPricingService::estimateFromCoords(
                        $cargoType,
                        (float) $pickupLat, (float) $pickupLng,
                        (float) $stop['lat'], (float) $stop['lng'],
                        $weightKg,
                        $user->city_id
                    );
                } else {
                    $pricing = ShopPricingService::estimateFromAddresses(
                        $cargoType, $data['pickup_address'], $stop['address'], $weightKg, $user->city_id
                    );
                }

                $stops[] = [
                    'seq' => $i + 1,
                    'address' => $stop['address'],
                    'lat' => $stop['lat'] ?? null,
                    'lng' => $stop['lng'] ?? null,
                    'phone' => $stop['phone'],
                    'name' => $stop['name'] ?? '',
                    'cod_amount' => $stop['cod_amount'] ?? null,
                    'note' => $stop['note'] ?? '',
                    'fee' => $pricing['fee'],
                    'distance_km' => $pricing['distance_km'],
                    'delivered_at' => null,
                ];
                $totalFee += $pricing['fee'];
            }

            $firstStop = $stops[0];

            $order = \DB::transaction(function () use ($data, $user, $totalFee, $firstStop, $stops, $pickupLat, $pickupLng, $cargoType, $weightKg) {
                $applied = $this->tryApplyVoucher($data['voucher_code'] ?? null, $user, $totalFee);
                $voucherCode = $applied['code'] ?? null;
                $discountAmount = $applied['discount_amount'] ?? 0;
                $isFreeship = $applied['is_freeship'] ?? false;
                $shippingFee = $totalFee - $discountAmount;

                $order = Order::create([
                    'code' => '',
                    'sender_name' => $data['pickup_name'] ?? $user->name,
                    'store_name' => $user->name,
                    'pickup_phone' => $data['pickup_phone'] ?? $user->phone,
                    'pickup_address' => $data['pickup_address'],
                    'pickup_lat' => $pickupLat,
                    'pickup_lng' => $pickupLng,
                    // Điểm giao chính = stop đầu tiên
                    'receiver_name' => $firstStop['name'],
                    'delivery_phone' => $firstStop['phone'],
                    'delivery_address' => $firstStop['address'],
                    'delivery_lat' => $firstStop['lat'],
                    'delivery_lng' => $firstStop['lng'],
                    'service_type' => 'delivery',
                    'shop_service_type' => filter_var($data['is_outbound'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 'shop_delivery' : 'shop_pickup',
                    'order_note' => $data['order_note'] ?? '',
                    'cargo_type' => $cargoType,
                    'cargo_weight' => $weightKg,
                    'is_batch' => true,
                    'shop_service_type' => 'shop_batch',
                    'stops' => $stops,
                    'payment_method' => 'cod',
                    'cod_amount' => array_sum(array_column($stops, 'cod_amount')),
                    'city_id' => $user->city_id,
                    'shipping_fee' => $shippingFee,
                    'night_surcharge' => 0,
                    'distance' => $stops[0]['distance_km'],
                    'voucher_code' => $voucherCode,
                    'discount_amount' => $discountAmount,
                    'bonus_fee' => 0,
                    'is_freeship' => $isFreeship,
                    'status' => 'pending',
                    'platform' => 'shop_app',
                    'sender_platform_id' => $user->id,
                ]);

                if ($applied) {
                    VoucherUsage::create([
                        'voucher_id' => $applied['voucher']->id,
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'used_at' => now(),
                    ]);
                }

                return $order;
            });

            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                app(OrderService::class)->dispatchNewOrder($orderId);
            })->afterResponse();

            return response()->json([
                'success' => true,
                'message' => 'Tạo đơn gộp thành công',
                'data' => $this->formatOrder($order),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Shop storeBatch: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra'], 500);
        }
    }

    /**
     * Khoá dòng voucher + kiểm tra lại toàn bộ điều kiện BÊN TRONG transaction
     * trước khi increment used_count — nếu chỉ check-rồi-increment (như trước
     * đây) thì 2 request gần như đồng thời (bấm tạo đơn 2 lần liên tiếp) đều
     * đọc thấy "còn lượt dùng" trước khi cái nào kịp tăng used_count, có thể
     * vượt usage_limit/per_user_limit.
     *
     * @return array{voucher: Voucher, code: string, discount_amount: int, is_freeship: bool}|null
     */
    private function tryApplyVoucher(?string $code, $user, int $shippingFee): ?array
    {
        if (empty($code)) {
            return null;
        }

        $voucher = Voucher::where('code', strtoupper(trim($code)))->lockForUpdate()->first();

        if (! $voucher || ! $voucher->is_active
            || ! in_array($voucher->audience, ['all', 'shop'])
            || ($voucher->user_id && $voucher->user_id != $user->id)
            || ($voucher->expires_at && ! $voucher->expires_at->isFuture())
            || ($voucher->usage_limit && $voucher->used_count >= $voucher->usage_limit)
            || ($voucher->per_user_limit && $voucher->usageCountByUser($user->id) >= $voucher->per_user_limit)
            || ($voucher->service_types && ! in_array('delivery', $voucher->service_types))
            || ($voucher->city_id && $voucher->city_id != $user->city_id)
            || ($voucher->min_order_value && $shippingFee < $voucher->min_order_value)
        ) {
            return null;
        }

        $isFreeship = $voucher->type === 'freeship';
        $discountAmount = match ($voucher->type) {
            'freeship' => $shippingFee,
            'percent' => (int) round($shippingFee * $voucher->value / 100),
            default => (int) $voucher->value,
        };
        if ($voucher->max_discount) {
            $discountAmount = min($discountAmount, $voucher->max_discount);
        }
        $discountAmount = min($discountAmount, $shippingFee);

        $voucher->increment('used_count');

        return [
            'voucher' => $voucher,
            'code' => $voucher->code,
            'discount_amount' => $discountAmount,
            'is_freeship' => $isFreeship,
        ];
    }

    public function deliverStop(string $code, int $seq, Request $request): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('platform', 'shop_app')
            ->where('is_batch', true)
            ->first();

        if (! $order) {
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
        if (! $found) {
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

        if (! $order) {
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

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        // Trạng thái order và lượt voucher phải thay đổi cùng nhau.
        // Compare-and-swap vẫn ngăn race với lúc dispatch gán tài xế.
        $updated = \DB::transaction(function () use ($order) {
            $updated = \DB::table('orders')
                ->where('id', $order->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            if (! $updated || ! $order->voucher_code) {
                return $updated;
            }

            $usageDeleted = VoucherUsage::where('order_id', $order->id)->delete();
            if ($usageDeleted) {
                Voucher::where('code', $order->voucher_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }

            return $updated;
        });

        if (! $updated) {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể hủy đơn khi chưa có tài xế nhận'], 400);
        }

        try {
            RTDBService::clearOrder($order->code);
            if ($order->dispatching_to_driver_id) {
                RTDBService::clearDriverOffer($order->dispatching_to_driver_id);
            }
        } catch (\Throwable $e) {
            // DB là nguồn sự thật; lỗi cleanup realtime không được
            // biến một lần hủy đã commit thành response thất bại.
            Log::warning('Shop cancelOrder RTDB cleanup failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Đã hủy đơn hàng']);
    }

    public function rate(string $code, Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'note' => 'nullable|string|max:500',
        ]);

        $order = Order::where('code', $code)
            ->where('sender_platform_id', $request->user()->id)
            ->where('status', 'completed')
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }
        if ($order->driver_rating) {
            return response()->json(['success' => false, 'message' => 'Bạn đã đánh giá đơn hàng này rồi.'], 400);
        }
        if ($order->completed_at && $order->completed_at->diffInHours(now()) > 24) {
            return response()->json(['success' => false, 'message' => 'Đã quá 24 giờ, không thể đánh giá đơn hàng này.'], 400);
        }

        // whereNull('driver_rating') giữ vai trò khoá atomic chống double-rate
        // race — không còn cần đọc số dòng bị ảnh hưởng vì rating không còn
        // kích hoạt tác dụng phụ nào (điểm tài xế) nữa.
        \DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('driver_rating')
            ->update([
                'driver_rating' => $data['rating'],
                'driver_rating_note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);

        return response()->json(['success' => true, 'message' => 'Cảm ơn đánh giá của bạn']);
    }

    private function formatOrder(Order $order, bool $withTracking = false): array
    {
        $result = [
            'id' => $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'cancel_reason' => $order->cancel_reason,
            'service_type' => $order->service_type,
            'pickup_address' => $order->pickup_address,
            'pickup_place_name' => $order->pickup_place_name,
            'pickup_lat' => $order->pickup_lat ? (float) $order->pickup_lat : null,
            'pickup_lng' => $order->pickup_lng ? (float) $order->pickup_lng : null,
            'pickup_phone' => $order->pickup_phone,
            'sender_name' => $order->sender_name,
            'store_name' => $order->store_name,
            'delivery_address' => $order->delivery_address,
            'delivery_place_name' => $order->delivery_place_name,
            'delivery_lat' => $order->delivery_lat ? (float) $order->delivery_lat : null,
            'delivery_lng' => $order->delivery_lng ? (float) $order->delivery_lng : null,
            'delivery_phone' => $order->delivery_phone,
            'receiver_name' => $order->receiver_name,
            'shipping_fee' => $order->shipping_fee,
            'voucher_code' => $order->voucher_code,
            'discount_amount' => $order->discount_amount ?? 0,
            'distance_km' => $order->distance ? (float) $order->distance : null,
            'order_note' => $order->order_note,
            'cargo_type' => $order->cargo_type ?? 'food',
            'cargo_note' => $order->cargo_note,
            'cargo_weight' => $order->cargo_weight ? (float) $order->cargo_weight : null,
            'payment_method' => $order->payment_method,
            'cod_amount' => $order->cod_amount,
            'night_surcharge' => $order->night_surcharge ?? 0,
            'driver_rating' => $order->driver_rating,
            'is_batch' => (bool) $order->is_batch,
            'shop_service_type' => $order->shop_service_type ?? null,
            'stops' => $order->stops ?? [],
            'created_at' => $order->created_at->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            // Toạ độ tài xế KHÔNG trả qua field này nữa — cột MySQL đã đông
            // cứng vĩnh viễn từ khi bỏ cron sync GPS. Nguồn duy nhất là
            // tracking.driver_location_path (Firebase), xem bên dưới.
            'driver' => $order->driver ? [
                'id' => $order->driver->id,
                'name' => $order->driver->name,
                'phone' => $order->driver->phone,
                'avatar_url' => $order->driver->profile_photo_path
                    ? asset('storage/'.$order->driver->profile_photo_path)
                    : null,
            ] : null,
        ];

        if ($withTracking) {
            $result['tracking'] = [
                'firebase_db_url' => config('services.firebase.database_url'),
                'rtdb_path' => "/orders/{$order->code}",
                'driver_location_path' => $order->delivery_man_id ? "/locations/driver_{$order->delivery_man_id}" : null,
            ];
        }

        return $result;
    }
}
