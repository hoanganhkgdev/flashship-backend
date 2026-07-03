<?php

namespace Modules\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackShopEvent
{
    private const EVENT_MAP = [
        'GET api/shop/auth/me'                     => 'app_open',
        'GET api/shop/pricing/estimate'            => 'view_pricing',
        'POST api/shop/pricing/estimate-batch'     => 'view_pricing_batch',
        'GET api/shop/vouchers'                    => 'view_vouchers',
        'GET api/shop/orders'                      => 'view_order_list',
        'GET api/shop/orders/stats'                => 'view_order_stats',
        'POST api/shop/orders'                     => 'place_order',
        'POST api/shop/orders/batch'               => 'place_order_batch',
        'GET api/shop/orders/{}'                   => 'view_order_detail',
        'POST api/shop/orders/{}/cancel'           => 'cancel_order',
        'POST api/shop/orders/{}/rate'             => 'rate_order',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        if (!$user || $user->user_type !== 'shop') {
            return;
        }

        $event = $this->resolveEvent($request);
        if (!$event) {
            return;
        }

        DB::table('customer_events')->insert([
            'user_id'    => $user->id,
            'platform'   => 'shop',
            'event'      => $event,
            'created_at' => now(),
        ]);
    }

    private function resolveEvent(Request $request): ?string
    {
        $method = $request->method();
        $path   = $request->path();

        $normalized = preg_replace_callback(
            '/\/([^\/]+)/',
            fn($m) => $this->isKeyword($m[1]) ? '/' . $m[1] : '/{}',
            '/' . $path
        );
        $key = $method . ' ' . ltrim($normalized, '/');

        return self::EVENT_MAP[$key] ?? null;
    }

    private function isKeyword(string $segment): bool
    {
        return in_array($segment, [
            'api', 'shop', 'auth', 'me', 'orders', 'pricing',
            'estimate', 'estimate-batch', 'batch', 'stats', 'vouchers',
            'cancel', 'rate', 'notifications', 'addresses', 'stops',
        ]);
    }
}
