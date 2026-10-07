<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Jobs\ExpireOrderMarketListingJob;
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

    public function shouldOpen(Order $order): bool
    {
        if (! OperationalSettings::orderMarketEnabled($order->city_id) || ! $order->dispatch_started_at) return false;
        $elapsed = (int) abs(now()->diffInSeconds($order->dispatch_started_at));
        return (int) $order->dispatch_attempts >= OperationalSettings::marketAttemptsBeforeOpen($order->city_id)
            || $elapsed >= OperationalSettings::marketWaitSecondsBeforeOpen($order->city_id);
    }

    public function open(Order $order): ?OrderMarketListing
    {
        if ($order->status !== 'pending' || ! $order->city_id) return null;
        $reason = (int) $order->dispatch_attempts >= OperationalSettings::marketAttemptsBeforeOpen($order->city_id)
            ? 'attempts' : 'timeout';

        $listing = DB::transaction(function () use ($order, $reason) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending') return null;
            $existing = OrderMarketListing::where('order_id', $fresh->id)->lockForUpdate()->first();
            if ($existing?->status === 'open') return $existing;

            $now = now();
            $listing = OrderMarketListing::updateOrCreate(['order_id' => $fresh->id], [
                'city_id' => $fresh->city_id, 'status' => 'open', 'open_reason' => $reason,
                'offer_attempts' => $fresh->dispatch_attempts, 'opened_at' => $now,
                'expires_at' => $now->copy()->addMinutes(OperationalSettings::marketExpireMinutes($fresh->city_id)),
                'claimed_at' => null, 'claimed_by' => null,
            ]);
            $fresh->update(['dispatching_to_driver_id' => null, 'offer_viewed_at' => null]);
            OrderHistory::create(['order_id' => $fresh->id, 'type' => 'market_opened',
                'description' => "Đưa vào Chợ đơn sau {$fresh->dispatch_attempts} lượt phát",
                'metadata' => ['reason' => $reason]]);
            return $listing;
        });

        if ($listing) {
            Redis::del("dispatch:retry_pending:{$order->id}");
            RTDBService::pingOrderMarket((int) $order->city_id);
            ExpireOrderMarketListingJob::dispatch($listing->id)->delay($listing->expires_at);
        }
        return $listing;
    }

    public function listForDriver(User $driver): array
    {
        if (! OperationalSettings::orderMarketEnabled($driver->city_id) || ! $this->baseEligible($driver)) return [];
        $location = $this->locations->freshLocationsFor([$driver->id])[$driver->id] ?? null;
        if (! $location) return [];
        $maxKm = OperationalSettings::marketMaxPickupDistanceKm($driver->city_id);

        return OrderMarketListing::with('order')
            ->where('city_id', $driver->city_id)->where('status', 'open')->where('expires_at', '>', now())
            ->latest('opened_at')->get()->map(function (OrderMarketListing $listing) use ($location, $maxKm) {
                $o = $listing->order;
                if (! $o || $o->status !== 'pending' || ! is_numeric($o->pickup_lat) || ! is_numeric($o->pickup_lng)) return null;
                $km = GoogleMapService::haversineKm($location['lat'], $location['lng'], $o->pickup_lat, $o->pickup_lng);
                if ($km > $maxKm) return null;
                return [
                    'id' => $o->id, 'code' => $o->code, 'service_type' => $o->service_type,
                    'pickup_address' => $o->pickup_address ?? '', 'delivery_address' => $o->delivery_address ?? '',
                    'pickup_distance_km' => round($km, 1), 'distance' => $o->distance ? (float) $o->distance : null,
                    'shipping_fee' => (int) $o->shipping_fee, 'bonus_fee' => (int) $o->bonus_fee,
                    'night_surcharge' => (int) $o->night_surcharge, 'cod_amount' => (int) ($o->cod_amount ?? 0),
                    'is_freeship' => (bool) $o->is_freeship, 'opened_at' => $listing->opened_at?->toIso8601String(),
                    'expires_at' => $listing->expires_at?->toIso8601String(),
                ];
            })->filter()->values()->all();
    }

    public function claim(Order $order, User $driver): array
    {
        if (! $this->baseEligible($driver)) return $this->error('Bạn hiện không đủ điều kiện nhận đơn.', 403);
        $location = $this->locations->freshLocationsFor([$driver->id])[$driver->id] ?? null;
        if (! $location || ! is_numeric($order->pickup_lat) || ! is_numeric($order->pickup_lng)) return $this->error('Không có vị trí GPS mới để nhận đơn.', 409);
        $km = GoogleMapService::haversineKm($location['lat'], $location['lng'], $order->pickup_lat, $order->pickup_lng);
        if ($km > OperationalSettings::marketMaxPickupDistanceKm($order->city_id)) return $this->error('Bạn đang ở ngoài phạm vi nhận đơn này.', 409);

        $result = DB::transaction(function () use ($order, $driver) {
            $lockedDriver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $listing = OrderMarketListing::where('order_id', $fresh->id)->lockForUpdate()->first();
            if ($fresh->status !== 'pending' || ! $listing || $listing->status !== 'open' || $listing->expires_at <= now()) return 'taken';
            if ((int) $fresh->city_id !== (int) $lockedDriver->city_id || ($fresh->service_type === 'car' && ! $lockedDriver->has_car_license)) return 'ineligible';
            $active = Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->lockForUpdate()->get();
            if ($active->count() >= OperationalSettings::maxActiveOrdersPerDriver($fresh->city_id)
                || ($active->count() === 1 && ! StackedOrderPolicy::allows($fresh, $active->first()))) return 'busy';

            $fresh->update(['status' => 'assigned', 'delivery_man_id' => $driver->id, 'dispatching_to_driver_id' => null]);
            $listing->update(['status' => 'claimed', 'claimed_at' => now(), 'claimed_by' => $driver->id]);
            OrderHistory::create(['order_id' => $fresh->id, 'user_id' => $driver->id, 'type' => 'market_claimed',
                'description' => "{$driver->name} nhận đơn từ Chợ đơn"]);
            return $fresh;
        });

        if (is_string($result)) return $this->error($result === 'taken' ? 'Đơn này vừa được tài xế khác nhận.' : 'Bạn không còn đủ điều kiện nhận đơn này.', 409);
        if (DB::table('cities')->where('id', $result->city_id)->value('is_rain_mode')) {
            $result->update(['rain_bonus_eligible' => true, 'rain_bonus_amount' => OperationalSettings::rainBonusAmount($result->city_id)]);
        }
        DB::table('users')->where('id', $driver->id)->update(['last_order_accepted_at' => now()]);
        Redis::del("dispatch:retry_pending:{$result->id}");
        RTDBService::updateOrderStatus($result->code, 'assigned');
        RTDBService::pingOrderMarket((int) $result->city_id);
        broadcast(new \App\Events\DispatchStateChanged());
        return ['success' => true, 'order' => app(OrderService::class)->formatOrderForDriver($result->fresh()), 'status' => 200];
    }

    public function close(Order $order, string $status = 'cancelled'): void
    {
        // Giữ tương thích trong khoảng rolling deploy khi code mới đã lên
        // nhưng migration chưa kịp chạy; tính năng mặc định vẫn đang tắt.
        if (! Schema::hasTable('order_market_listings')) return;
        $changed = OrderMarketListing::where('order_id', $order->id)->where('status', 'open')->update(['status' => $status, 'updated_at' => now()]);
        if ($changed && $order->city_id) RTDBService::pingOrderMarket((int) $order->city_id);
    }

    public function expire(int $listingId): void
    {
        $listing = DB::transaction(function () use ($listingId) {
            $listing = OrderMarketListing::whereKey($listingId)->lockForUpdate()->first();
            if (! $listing || $listing->status !== 'open' || $listing->expires_at > now()) return null;
            $listing->update(['status' => 'expired']);
            return $listing;
        });
        if ($listing) {
            RTDBService::pingOrderMarket((int) $listing->city_id);
            $order = Order::find($listing->order_id);
            if ($order?->status === 'pending') app(DispatchService::class)->markNoDriver($order);
        }
    }

    private function baseEligible(User $driver): bool
    {
        if ($driver->user_type !== 'driver' || ! $driver->is_online || (int) $driver->status !== 1) return false;
        if ($driver->score_suspended_until && $driver->score_suspended_until > now()) return false;
        if ($driver->debts()->where('status', 'overdue')->exists()) return false;
        return ! DB::table('driver_leave_requests')->where('driver_id', $driver->id)->whereDate('leave_date', today())->exists();
    }

    private function error(string $message, int $status): array { return ['success' => false, 'message' => $message, 'status' => $status]; }
}
