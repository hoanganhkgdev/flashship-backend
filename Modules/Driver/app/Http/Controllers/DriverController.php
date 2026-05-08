<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Announcement;
use Modules\Order\Events\DriverLocationUpdatedEvent;
use Modules\Order\Models\Order;

class DriverController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['city', 'bank', 'driverLicenses', 'wallet']);

        $data = $user->toArray();
        $data['city_name']    = $user->city?->name ?? '';
        $data['balance']      = $user->wallet?->balance ?? 0;
        $data['bank_name']    = $user->bank?->bank_name;
        $data['bank_account'] = $user->bank?->account_number;
        $license = $user->driverLicenses->sortByDesc('id')->first();
        if ($license) {
            $data['license_status']    = $license->status;
            $data['license_image_url'] = $license->image_path ? url('storage/' . $license->image_path) : null;
        }
        if ($user->profile_photo_path) {
            $data['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }

        return response()->json(['success' => true, 'data' => ['user' => $data]]);
    }

    public function toggleOnline(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->is_online = !$user->is_online;
        $user->save();
        // TODO: Firebase update driver online status

        return response()->json([
            'success'   => true,
            'message'   => $user->is_online ? 'Bạn đang online' : 'Bạn đang offline',
            'is_online' => $user->is_online,
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $driver = $request->user();
        $driver->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);

        $activeOrder = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->select('code')
            ->first();

        if ($activeOrder) {
            broadcast(new DriverLocationUpdatedEvent(
                $activeOrder->code,
                (float) $data['latitude'],
                (float) $data['longitude'],
            ));
        }

        return response()->json(['success' => true]);
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => 'required|string']);
        $request->user()->update(['fcm_token' => $data['fcm_token']]);
        return response()->json(['success' => true, 'message' => 'FCM Token đã cập nhật']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => 'nullable|string|max:255',
            'avatar' => 'nullable|image|max:2048',
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $data['profile_photo_path'] = $request->file('avatar')->store('profile-photos', 'public');
        }
        unset($data['avatar']);

        $user->update(array_filter($data));

        return response()->json(['success' => true, 'message' => 'Cập nhật thành công', 'data' => ['user' => $user->fresh()]]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Mật khẩu hiện tại không đúng'], 400);
        }

        $user->update(['password' => bcrypt($data['new_password'])]);
        return response()->json(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
    }

    public function stats(Request $request): JsonResponse
    {
        $user  = $request->user();
        $stats = \Modules\Order\Models\Order::where('delivery_man_id', $user->id)
            ->selectRaw("COUNT(*) as total, SUM(status='completed') as completed, SUM(status='cancelled') as cancelled")
            ->first();

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);
        return response()->json([
            'success' => true,
            'data'    => $notifications->items(),
            'meta'    => ['has_more' => $notifications->hasMorePages()],
        ]);
    }

    public function markNotificationAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($id === 'all') {
            $user->unreadNotifications->markAsRead();
        } else {
            $user->notifications()->where('id', $id)->first()?->markAsRead();
        }
        return response()->json(['success' => true]);
    }

    public function requestDeleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->delete_requested_at) {
            return response()->json(['success' => false, 'message' => 'Bạn đã gửi yêu cầu xóa tài khoản'], 422);
        }
        $user->update(['delete_requested_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Đã gửi yêu cầu xóa tài khoản']);
    }

    public function cancelDeleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->delete_requested_at) {
            return response()->json(['success' => false, 'message' => 'Không có yêu cầu xóa tài khoản'], 422);
        }
        $user->update(['delete_requested_at' => null]);
        return response()->json(['success' => true, 'message' => 'Đã hủy yêu cầu xóa tài khoản']);
    }

    public function announcements(Request $request): JsonResponse
    {
        $cityId = $request->user()->city_id;

        $items = Announcement::activeFor('driver', $cityId)
            ->select('id', 'title', 'content', 'type', 'city_id', 'created_at')
            ->with('city:id,name')
            ->limit(5)
            ->get();

        return response()->json(['data' => $items]);
    }
}
