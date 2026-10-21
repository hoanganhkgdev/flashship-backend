<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Services\RTDBService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderHistory;
use Modules\Order\Models\OrderMarketListing;

class OrderMarketService
{
    public function __construct(private readonly DriverLocationService $locations) {}

    public function isOpen(Order $order): bool
    {
        return OrderMarketListing::where('order_id', $order->id)->where('status', 'open')->exists();
    }

    /** Đưa đơn mới lên danh sách để tài xế tự nhận — đường phát đơn duy nhất; đơn nằm đó đến khi có người nhận hoặc bị huỷ/gán tay. */
    public function openForSelfPick(Order $order): ?OrderMarketListing
    {
        DB::table('orders')->where('id', $order->id)->where('status', 'pending')->update([
            'dispatch_started_at' => $order->dispatch_started_at ?? now(),
            'cancel_reason' => null,
            'updated_at' => now(),
        ]);

        $fresh = $order->fresh();

        return $fresh ? $this->open($fresh) : null;
    }

    public function open(Order $order, string $reason = 'self_pick'): ?OrderMarketListing
    {
        if ($order->status !== 'pending' || ! $order->city_id) {
            return null;
        }
        $openedNow = false;

        $listing = DB::transaction(function () use ($order, $reason, &$openedNow) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending') {
                return null;
            }
            $existing = OrderMarketListing::where('order_id', $fresh->id)->lockForUpdate()->first();
            if ($existing?->status === 'open') {
                return $existing;
            }

            $now = now();
            $openedNow = true;
            $listing = OrderMarketListing::updateOrCreate(['order_id' => $fresh->id], [
                'city_id' => $fresh->city_id, 'status' => 'open', 'open_reason' => $reason,
                'offer_attempts' => $fresh->dispatch_attempts, 'opened_at' => $now,
                'expires_at' => null,
                'claimed_at' => null, 'claimed_by' => null,
            ]);
            $fresh->update(['offer_viewed_at' => null]);
            OrderHistory::create(['order_id' => $fresh->id, 'type' => 'market_opened',
                'description' => 'Đơn được đưa lên danh sách đơn mới',
                'metadata' => ['reason' => $reason]]);

            return $listing;
        });

        if ($listing && $openedNow) {
            $this->publishToFirebase($order->fresh() ?? $order, $listing);
            RTDBService::pingOrderMarket((int) $order->city_id);
            $this->notifyEligibleDrivers($order->fresh());
        }

        return $listing;
    }

    /** @return array<int, string> */
    public function eligibleNotificationTokens(Order $order): array
    {
        $drivers = User::query()
            ->where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotNull('fcm_token')->where('fcm_token', '!=', '')
            ->when(OperationalSettings::debtEnabled($order->city_id), fn ($query) => $query
                ->where(fn ($driver) => $driver->where('debt_enabled', false)
                    ->orWhereDoesntHave('debts', fn ($debts) => $debts->where('status', 'overdue'))))
            ->has('orders', '<', OperationalSettings::maxActiveOrdersPerDriver($order->city_id), 'and',
                fn ($orders) => $orders->whereIn('status', ['assigned', 'processing']))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('driver_leave_requests')
                ->whereColumn('driver_leave_requests.driver_id', 'users.id')->whereDate('leave_date', today()))
            ->when($order->service_type === 'car', fn ($query) => $query->where('has_car_license', true))
            ->get(['id', 'fcm_token']);

        return $drivers->pluck('fcm_token')->filter()->unique()->values()->all();
    }

    private function notifyEligibleDrivers(Order $order): void
    {
        $tokens = $this->eligibleNotificationTokens($order);
        if (! $tokens) {
            return;
        }

        FCMService::getInstance()->broadcast(
            $tokens,
            'Có đơn mới',
            'Mở danh sách đơn mới để xem tuyến và nhận đơn.',
            ['type' => 'order_market', 'order_id' => (string) $order->id, 'order_code' => (string) $order->code],
        );
    }

    /** Dữ liệu một đơn trên danh sách đơn mới — dùng chung cho API và nhánh Firebase. */
    public function payloadFor(Order $o, OrderMarketListing $listing): array
    {
        $isRainMode = (bool) DB::table('cities')->where('id', $o->city_id)->value('is_rain_mode');

        return [
            'id' => $o->id, 'code' => $o->code, 'service_type' => $o->service_type,
            'pickup_address' => $o->pickup_address ?? '', 'delivery_address' => $o->delivery_address ?? '',
            'order_note' => $o->order_note,
            'pickup_lat' => is_numeric($o->pickup_lat) ? (float) $o->pickup_lat : null,
            'pickup_lng' => is_numeric($o->pickup_lng) ? (float) $o->pickup_lng : null,
            'distance' => $o->distance ? (float) $o->distance : null,
            'shipping_fee' => (int) $o->shipping_fee, 'bonus_fee' => (int) $o->bonus_fee,
            'night_surcharge' => (int) $o->night_surcharge, 'cod_amount' => (int) ($o->cod_amount ?? 0),
            'is_rain_mode' => $isRainMode,
            'rain_bonus_amount' => $isRainMode ? OperationalSettings::rainBonusAmount($o->city_id) : 0,
            'is_freeship' => (bool) $o->is_freeship,
            'voucher_code' => $o->voucher_code,
            'discount_amount' => (int) ($o->discount_amount ?? 0),
            'opened_at' => $listing->opened_at?->toIso8601String(),
            'expires_at' => null,
        ];
    }

    /** Đưa/gỡ đơn khỏi nhánh Firebase mà app tài xế nghe trực tiếp. */
    public function publishToFirebase(Order $order, ?OrderMarketListing $listing): void
    {
        if (! $order->city_id) {
            return;
        }
        RTDBService::setMarketOrder(
            (int) $order->city_id,
            (int) $order->id,
            $listing ? $this->payloadFor($order, $listing) : null,
        );
    }

    public function listForDriver(User $driver): array
    {
        if (! $this->baseEligible($driver)) {
            return [];
        }
        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing'])->count();
        // Tài xế đang cầm đơn vẫn được nhận thêm đơn tự chọn cho tới khi chạm
        // giới hạn số đơn active của khu vực.
        if ($activeCount >= OperationalSettings::maxActiveOrdersPerDriver($driver->city_id)) {
            return [];
        }
        // GPS chỉ dùng để hiện khoảng cách tới điểm lấy; không còn giới hạn bán kính.
        $location = $this->locations->freshLocationsFor([$driver->id])[$driver->id] ?? null;

        return OrderMarketListing::with('order')
            ->where('city_id', $driver->city_id)->where('status', 'open')
            ->whereHas('order', fn ($orders) => $orders
                ->where('status', 'pending')
                ->whereColumn('orders.city_id', 'order_market_listings.city_id'))
            ->when(! $driver->has_car_license, fn ($query) => $query->whereHas(
                'order', fn ($orders) => $orders->where('service_type', '!=', 'car')
            ))
            ->latest('opened_at')->get()->map(function (OrderMarketListing $listing) use ($location) {
                $o = $listing->order;
                if (! $o || $o->status !== 'pending') {
                    return null;
                }
                $km = $location && is_numeric($o->pickup_lat) && is_numeric($o->pickup_lng)
                    ? GoogleMapService::haversineKm($location['lat'], $location['lng'], $o->pickup_lat, $o->pickup_lng)
                    : null;

                // Đơn không hết hạn; vẫn gửi expires_at lùi dần (luôn còn 1 giờ) để các
                // bản app tài xế cũ — vốn ẩn đơn khi hết hạn — tiếp tục hiện đơn.
                return array_merge($this->payloadFor($o, $listing), [
                    'pickup_distance_km' => $km !== null ? round($km, 1) : null,
                    'expires_at' => now()->addHour()->toIso8601String(),
                ]);
            })->filter()->values()->all();
    }

    public function claim(Order $order, User $driver): array
    {
        // Retry sau khi request trước đã commit nhưng điện thoại mất phản hồi
        // phải trả lại đúng kết quả thành công. Nếu kiểm tra Online/GPS trước,
        // chính tài xế vừa nhận đơn có thể bị báo nhầm "không đủ điều kiện".
        if ($this->alreadyClaimedBy($order, $driver)) {
            return $this->claimSuccess($order);
        }

        $result = DB::transaction(function () use ($order, $driver) {
            $lockedDriver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $listing = OrderMarketListing::where('order_id', $fresh->id)->lockForUpdate()->first();

            // Hai request cùng lúc đều có thể đọc thấy pending trước khi vào
            // transaction. Request thắng gán đơn; request còn lại chờ lock và
            // phải nhận lại success nếu cũng thuộc chính tài xế đó.
            if ($this->alreadyClaimedBy($fresh, $lockedDriver)) {
                return ['state' => 'already_claimed', 'order' => $fresh];
            }
            if ($fresh->status !== 'pending' || ! $listing || $listing->status !== 'open') {
                return ['state' => 'taken'];
            }
            if (! $this->baseEligible($lockedDriver)
                || (int) $fresh->city_id !== (int) $lockedDriver->city_id
                || ($fresh->service_type === 'car' && ! $lockedDriver->has_car_license)) {
                return ['state' => 'ineligible'];
            }
            $active = Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->lockForUpdate()->get();
            // Cho nhận thêm khi đang cầm đơn, miễn chưa đủ số đơn active tối đa.
            if ($active->count() >= OperationalSettings::maxActiveOrdersPerDriver($fresh->city_id)) {
                return ['state' => 'busy'];
            }

            // Đơn tự chọn là một đơn độc lập, không có vai trò chính/phụ.
            $fresh->update([
                'status' => 'assigned', 'delivery_man_id' => $driver->id,
                'driver_order_role' => 'market', 'main_order_id' => null,
                'extra_claimed_count' => 0,
                'bundle_window_expires_at' => null,
            ]);
            $listing->update(['status' => 'claimed', 'claimed_at' => now(), 'claimed_by' => $driver->id]);
            OrderHistory::create(['order_id' => $fresh->id, 'user_id' => $driver->id, 'type' => 'market_claimed',
                'description' => "{$driver->name} nhận đơn từ danh sách đơn mới"]);

            return ['state' => 'claimed', 'order' => $fresh];
        });

        if ($result['state'] !== 'claimed' && $result['state'] !== 'already_claimed') {
            $message = match ($result['state']) {
                'taken' => 'Đơn này vừa được tài xế khác nhận.',
                'busy' => 'Bạn đã nhận đủ số đơn tối đa nên chưa thể nhận thêm đơn tự chọn.',
                default => 'Bạn không còn đủ điều kiện nhận đơn này.',
            };

            return $this->error($message, $result['state'] === 'ineligible' ? 403 : 409);
        }

        /** @var Order $claimedOrder */
        $claimedOrder = $result['order'];
        if ($result['state'] === 'already_claimed') {
            return $this->claimSuccess($claimedOrder);
        }

        $result = $claimedOrder;
        if (DB::table('cities')->where('id', $result->city_id)->value('is_rain_mode')) {
            $result->update(['rain_bonus_eligible' => true, 'rain_bonus_amount' => OperationalSettings::rainBonusAmount($result->city_id)]);
        }
        DB::table('users')->where('id', $driver->id)->update(['last_order_accepted_at' => now()]);
        RTDBService::updateOrderStatus($result->code, 'assigned');
        $this->publishToFirebase($result, null);
        RTDBService::pingOrderMarket((int) $result->city_id);

        // Khách nhận RTDB cho màn đang mở, FCM cho app nền và inbox để xem lại.
        $customer = User::find($result->sender_platform_id);
        if ($customer) {
            try {
                if ($customer->fcm_token) {
                    FCMService::getInstance()->sendOrderStatusUpdate(
                        $customer->fcm_token,
                        $result->code,
                        'assigned',
                    );
                }
                CustomerNotificationController::create(
                    $customer->id,
                    "Đơn #{$result->code}",
                    'Tài xế đã nhận đơn và đang trên đường đến',
                    'order_status',
                    $result->code,
                );
            } catch (\Throwable $e) {
                // Assignment đã commit; lỗi kênh thông báo không được biến
                // lần claim thành thất bại để tài xế bấm lại.
                Log::warning('[OrderMarket] Không thông báo được cho khách sau khi nhận đơn', [
                    'order_id' => $result->id,
                    'customer_id' => $customer->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->claimSuccess($result->fresh());
    }

    private function alreadyClaimedBy(Order $order, User $driver): bool
    {
        return $order->status === 'assigned'
            && (int) $order->delivery_man_id === (int) $driver->id
            && $order->driver_order_role === 'market';
    }

    private function claimSuccess(Order $order): array
    {
        return [
            'success' => true,
            'order' => app(OrderService::class)->formatOrderForDriver($order),
            'status' => 200,
        ];
    }

    public function close(Order $order, string $status = 'cancelled'): void
    {
        // Giữ tương thích trong khoảng rolling deploy khi code mới đã lên
        // nhưng migration chưa kịp chạy; tính năng mặc định vẫn đang tắt.
        if (! Schema::hasTable('order_market_listings')) {
            return;
        }
        $changed = OrderMarketListing::where('order_id', $order->id)->where('status', 'open')->update(['status' => $status, 'updated_at' => now()]);
        if ($changed && $order->city_id) {
            $this->publishToFirebase($order, null);
            RTDBService::pingOrderMarket((int) $order->city_id);
        }
    }

    private function baseEligible(User $driver): bool
    {
        if ($driver->user_type !== 'driver' || ! $driver->is_online || (int) $driver->status !== 1) {
            return false;
        }
        if ($driver->debtApplies() && $driver->debts()->where('status', 'overdue')->exists()) {
            return false;
        }

        return ! DB::table('driver_leave_requests')->where('driver_id', $driver->id)->whereDate('leave_date', today())->exists();
    }

    private function error(string $message, int $status): array
    {
        return ['success' => false, 'message' => $message, 'status' => $status];
    }
}
