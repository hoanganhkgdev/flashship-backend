<?php
namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

class AdminController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $field = filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user  = User::where($field, $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Tài khoản hoặc mật khẩu không đúng']]);
        }

        if (!in_array($user->user_type, ['admin', 'subadmin'])) {
            return response()->json(['success' => false, 'message' => 'Không có quyền truy cập'], 403);
        }

        $user->tokens()->where('name', 'admin_token')->delete();
        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json(['success' => true, 'data' => ['token' => $token, 'user' => $user]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $request->user()]);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['driver:id,name,phone', 'city:id,name'])->latest();

        if ($request->has('status'))  $query->where('status', $request->status);
        if ($request->has('city_id')) $query->where('city_id', $request->city_id);
        if ($request->has('service_type')) $query->where('service_type', $request->service_type);
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('code', 'like', "%{$search}%")->orWhere('delivery_phone', 'like', "%{$search}%"));
        }

        $orders = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $orders->items(),
            'meta'    => ['current_page' => $orders->currentPage(), 'has_more' => $orders->hasMorePages(), 'total' => $orders->total()],
        ]);
    }

    public function showOrder(int $id): JsonResponse
    {
        $order = Order::with(['driver', 'city', 'histories'])->find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        return response()->json(['success' => true, 'data' => $order]);
    }

    public function assignDriver(Request $request, int $id): JsonResponse
    {
        $data  = $request->validate(['driver_id' => 'required|integer|exists:users,id']);
        $order = Order::findOrFail($id);

        $order->update([
            'delivery_man_id'          => $data['driver_id'],
            'dispatching_to_driver_id' => null,
            'status'                   => 'assigned',
        ]);

        return response()->json(['success' => true, 'message' => 'Đã phân công tài xế', 'data' => $order->fresh()]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $query = User::where('user_type', 'driver')->with(['city', 'wallet'])->latest();

        if ($request->has('city_id'))  $query->where('city_id', $request->city_id);
        if ($request->has('is_online')) $query->where('is_online', (bool) $request->is_online);
        if ($request->has('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }

        $drivers = $query->paginate(20);
        return response()->json(['success' => true, 'data' => $drivers->items(), 'meta' => ['total' => $drivers->total(), 'has_more' => $drivers->hasMorePages()]]);
    }
}
