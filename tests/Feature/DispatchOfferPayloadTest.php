<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchOfferPayload;
use Tests\TestCase;

class DispatchOfferPayloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reconciliation_payload_contains_every_field_needed_to_restore_the_offer_screen(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create([
            'name' => 'Offer sync',
            'slug' => 'offer-sync-'.uniqid(),
            'is_active' => true,
        ]);
        $customer = User::create([
            'name' => 'Customer',
            'email' => uniqid().'@customer.test',
            'phone' => '0912345678',
            'password' => 'test-password',
            'user_type' => 'customer',
            'status' => 1,
            'city_id' => $city->id,
        ]);
        $driver = User::create([
            'name' => 'Driver',
            'email' => uniqid().'@driver.test',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'SYNC-'.uniqid(),
            'status' => 'pending',
            'city_id' => $city->id,
            'sender_platform_id' => $customer->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Pickup',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
            'delivery_address' => 'Delivery',
            'delivery_lat' => 10.01,
            'delivery_lng' => 105.01,
            'shipping_fee' => 25000,
            'payment_method' => 'cod',
            'cod_amount' => 100000,
        ]);
        $log = OrderDispatchLog::create([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds(15),
            'result' => 'pending',
        ]);

        $payload = DispatchOfferPayload::make($order->load('sender'), $log);

        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame($order->code, $payload['order_code']);
        $this->assertSame($log->expires_at->timestamp, $payload['expires_at']);
        $this->assertSame(25000, $payload['shipping_fee']);
        $this->assertSame(100000, $payload['cod_amount']);
        $this->assertSame('0912345678', $payload['customer_phone']);
        $this->assertSame(OperationalSettings::offerDecisionSeconds($city->id), $payload['decision_seconds']);
        $this->assertStringContainsString('/dispatch/offers/'.$log->id.'/received', $payload['receipt_url']);
        $this->assertStringContainsString('/dispatch/offers/'.$log->id.'/viewed', $payload['view_url']);
    }
}
