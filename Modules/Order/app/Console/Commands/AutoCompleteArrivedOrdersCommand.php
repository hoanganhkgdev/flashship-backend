<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;

class AutoCompleteArrivedOrdersCommand extends Command
{
    const AUTO_COMPLETE_GRACE_MINUTES = 3;

    protected $signature = 'orders:auto-complete-arrived';

    protected $description = 'Tự hoàn thành đơn sau khi tài xế đã ở gần điểm giao nhưng không bấm hoàn thành';

    public function handle(DriverLocationService $locations, OrderService $orders): int
    {
        $processing = Order::query()
            ->with('driver')
            ->where('status', 'processing')
            ->where('is_batch', false)
            ->whereNotNull('delivery_man_id')
            ->whereNotNull('delivery_lat')
            ->whereNotNull('delivery_lng')
            ->get();

        $freshLocations = $locations->freshLocationsFor(
            $processing->pluck('delivery_man_id')->map(fn ($id) => (int) $id)->unique()->all()
        );

        foreach ($processing as $order) {
            if ($order->delivery_arrived_at
                && $order->delivery_arrived_at->lte(now()->subMinutes(self::AUTO_COMPLETE_GRACE_MINUTES))) {
                $result = $orders->completeOrder($order, $order->driver, true);
                if ($result['success']) {
                    DB::table('orders')->where('id', $order->id)->update(['auto_completed_at' => now()]);
                    Log::info('[OrderAutoComplete] completed after verified arrival', [
                        'order_id' => $order->id,
                        'driver_id' => $order->delivery_man_id,
                    ]);
                }
                continue;
            }

            if ($order->delivery_arrived_at) {
                continue;
            }

            $location = $freshLocations[(int) $order->delivery_man_id] ?? null;
            if (! $location) {
                continue;
            }

            $distanceKm = GoogleMapService::haversineKm(
                (float) $location['lat'],
                (float) $location['lng'],
                (float) $order->delivery_lat,
                (float) $order->delivery_lng,
            );
            if ($distanceKm > OrderService::COMPLETION_RADIUS_KM) {
                continue;
            }

            $marked = DB::table('orders')
                ->where('id', $order->id)
                ->where('status', 'processing')
                ->whereNull('delivery_arrived_at')
                ->update(['delivery_arrived_at' => now()]);
            if (! $marked) {
                continue;
            }

            if ($order->driver?->fcm_token) {
                try {
                    FCMService::getInstance()->sendDeliveryReminder($order->driver->fcm_token, $order->code);
                    DB::table('orders')->where('id', $order->id)
                        ->update(['delivery_reminder_sent_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('[OrderAutoComplete] arrival reminder failed', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
