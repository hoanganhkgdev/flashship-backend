<?php

namespace Modules\Customer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackCustomerEvent
{
    // Map: "METHOD /path/pattern" => "tên event"
    private const EVENT_MAP = [
        'GET api/customer/auth/me'           => 'app_open',
        'GET api/customer/pricing/estimate'  => 'view_pricing',
        'GET api/customer/drivers/nearby'    => 'check_drivers',
        'GET api/customer/vouchers'          => 'view_vouchers',
        'GET api/customer/orders'            => 'view_order_list',
        'POST api/customer/orders'           => 'place_order',
        'POST api/customer/orders/{}/cancel' => 'cancel_order',
        'POST api/customer/orders/{}/rate'   => 'rate_order',
        'GET api/customer/orders/{}'         => 'view_order_detail',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    // Chạy SAU khi response đã gửi → không làm chậm app
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        if (!$user || $user->user_type !== 'customer') {
            return;
        }

        $event = $this->resolveEvent($request);
        if (!$event) {
            return;
        }

        DB::table('customer_events')->insert([
            'user_id'    => $user->id,
            'event'      => $event,
            'created_at' => now(),
        ]);
    }

    private function resolveEvent(Request $request): ?string
    {
        $method = $request->method();
        $path   = $request->path(); // "api/customer/orders/ABC123/cancel"

        // Chuẩn hoá: thay segment không phải keyword bằng {}
        $normalized = preg_replace_callback(
            '/\/([^\/]+)/',
            fn($m) => $this->isKeyword($m[1]) ? '/' . $m[1] : '/{}',
            '/' . $path
        );
        $key = $method . ' ' . ltrim($normalized, '/');

        return self::EVENT_MAP[$key] ?? null;
    }

    // Các segment tĩnh trong URL — không bị thay bằng {}
    private function isKeyword(string $segment): bool
    {
        return in_array($segment, [
            'api', 'customer', 'auth', 'me', 'orders', 'pricing',
            'estimate', 'drivers', 'nearby', 'vouchers', 'cancel',
            'rate', 'notifications', 'addresses', 'support', 'legal',
        ]);
    }
}
