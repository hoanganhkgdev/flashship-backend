<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\URL;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

/** Một cấu trúc offer duy nhất cho RTDB, FCM fallback và API reconciliation. */
final class DispatchOfferPayload
{
    public static function make(Order $order, OrderDispatchLog $log): array
    {
        $urlExpiry = now()->addSeconds(max(
            10,
            ($log->expires_at?->timestamp ?? now()->timestamp) - now()->timestamp + 10,
        ));
        $receiptUrl = URL::temporarySignedRoute(
            'api.dispatch.offer.received',
            $urlExpiry,
            ['dispatchLog' => $log->id],
        );
        $viewUrl = URL::temporarySignedRoute(
            'api.dispatch.offer.viewed',
            $urlExpiry,
            ['dispatchLog' => $log->id],
        );
        $isRainMode = (bool) City::whereKey($order->city_id)->value('is_rain_mode');

        return [
            'order_id' => $order->id,
            'order_code' => $order->code,
            'offered_at' => $log->offered_at?->timestamp,
            'expires_at' => $log->expires_at?->timestamp,
            'service_type' => $order->service_type,
            'pickup_address' => $order->pickup_address ?? '',
            'pickup_place_name' => $order->pickup_place_name,
            'pickup_name' => $order->sender_name ?? '',
            'pickup_phone' => $order->pickup_phone ?? '',
            'pickup_lat' => $order->pickup_lat ? (float) $order->pickup_lat : null,
            'pickup_lng' => $order->pickup_lng ? (float) $order->pickup_lng : null,
            'delivery_address' => $order->delivery_address ?? '',
            'delivery_place_name' => $order->delivery_place_name,
            'delivery_phone' => $order->delivery_phone ?? '',
            'receiver_name' => $order->receiver_name ?? '',
            'delivery_lat' => $order->delivery_lat ? (float) $order->delivery_lat : null,
            'delivery_lng' => $order->delivery_lng ? (float) $order->delivery_lng : null,
            'order_note' => $order->order_note ?? '',
            'store_name' => $order->store_name ?? '',
            'platform' => $order->platform ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type,
            'cargo_type' => $order->cargo_type,
            'cargo_note' => $order->cargo_note,
            'cargo_weight' => $order->cargo_weight ? (float) $order->cargo_weight : null,
            'is_batch' => (bool) ($order->is_batch ?? false),
            'stops_count' => $order->is_batch ? count($order->stops ?? []) : 0,
            'stops' => $order->is_batch ? ($order->stops ?? []) : [],
            'shipping_fee' => (int) ($order->shipping_fee ?? 0),
            'discount_amount' => (int) ($order->discount_amount ?? 0),
            'voucher_code' => $order->voucher_code,
            'bonus_fee' => (int) ($order->bonus_fee ?? 0),
            'night_surcharge' => (int) ($order->night_surcharge ?? 0),
            'payment_method' => $order->payment_method ?? 'prepaid',
            'cod_amount' => (int) ($order->cod_amount ?? 0),
            'customer_phone' => $order->sender?->phone ?? '',
            'is_rain_mode' => $isRainMode,
            'decision_seconds' => OperationalSettings::offerDecisionSeconds($order->city_id),
            'receipt_url' => $receiptUrl,
            'view_url' => $viewUrl,
            ...($isRainMode ? ['rain_bonus_amount' => OperationalSettings::rainBonusAmount($order->city_id)] : []),
        ];
    }
}
