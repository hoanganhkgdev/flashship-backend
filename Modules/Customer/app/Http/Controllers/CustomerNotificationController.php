<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class CustomerNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'title', 'body', 'type', 'order_code', 'is_read', 'created_at']);

        return response()->json(['data' => $items]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        DB::table('customer_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        DB::table('customer_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }

    // Helper: tạo notification cho 1 user
    public static function create(int $userId, string $title, string $body, string $type = 'general', ?string $orderCode = null): void
    {
        DB::table('customer_notifications')->insert([
            'user_id'    => $userId,
            'title'      => $title,
            'body'       => $body,
            'type'       => $type,
            'order_code' => $orderCode,
            'created_at' => now(),
        ]);
    }

    // Helper: gửi broadcast cho tất cả customer
    public static function broadcast(string $title, string $body, ?int $cityId = null): void
    {
        $query = DB::table('users')
            ->where('user_type', 'customer')
            ->where('status', 1);

        if ($cityId) {
            $query->where('city_id', $cityId);
        }

        $userIds = $query->pluck('id');

        $rows = $userIds->map(fn ($id) => [
            'user_id'    => $id,
            'title'      => $title,
            'body'       => $body,
            'type'       => 'general',
            'order_code' => null,
            'created_at' => now(),
        ])->toArray();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customer_notifications')->insert($chunk);
        }
    }
}
