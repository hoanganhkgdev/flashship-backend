<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Jobs\RouteNewOrderJob;
use Modules\Order\Models\Order;
use Tests\TestCase;

class CustomerSelfPickOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_customer_order_is_committed_and_queued_for_routing(): void
    {
        Queue::fake();
        [$city, $customer] = $this->customerInCity();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/customer/orders', [
            'service_type' => 'topup',
            'topup_amount' => 100_000,
            'pickup_address' => '10 Nguyễn Trung Trực',
            'pickup_lat' => 10.0123,
            'pickup_lng' => 105.0812,
            'delivery_address' => '10 Nguyễn Trung Trực',
            'delivery_lat' => 10.0123,
            'delivery_lng' => 105.0812,
            'delivery_phone' => '0900000000',
        ])->assertCreated()->assertJsonPath('success', true);

        $order = Order::where('sender_platform_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertSame($city->id, $order->city_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame(10.0123, $order->pickup_lat);
        Queue::assertPushed(RouteNewOrderJob::class, fn (RouteNewOrderJob $job) => $job->orderId === $order->id);
        $this->assertSame((string) $order->id, $response->json('data.code'));
    }

    public function test_customer_cannot_create_an_order_without_exact_coordinates(): void
    {
        [, $customer] = $this->customerInCity();
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', [
            'service_type' => 'delivery',
            'pickup_address' => 'Điểm lấy chỉ có chữ',
            'delivery_address' => 'Điểm giao chỉ có chữ',
            'delivery_phone' => '0900000000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array{City, User} */
    private function customerInCity(): array
    {
        $city = City::create([
            'name' => 'Customer self pick',
            'slug' => 'customer-self-pick-'.uniqid(),
            'is_active' => true,
        ]);
        $customer = User::create([
            'name' => 'Customer',
            'email' => uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'customer',
            'status' => 1,
            'city_id' => $city->id,
        ]);

        return [$city, $customer];
    }
}
