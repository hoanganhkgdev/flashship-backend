<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ShopNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page    = max(1, (int) $request->query('page', 1));

        $total = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->count();

        $items = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['id', 'title', 'body', 'type', 'order_code', 'is_read', 'created_at'])
            ->map(fn ($n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'body'       => $n->body,
                'type'       => $n->type,
                'order_code' => $n->order_code,
                'is_read'    => (bool) $n->is_read,
                'created_at' => date('c', strtotime($n->created_at)),
            ]);

        return response()->json([
            'success'  => true,
            'data'     => $items,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['success' => true, 'count' => $count]);
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
}
