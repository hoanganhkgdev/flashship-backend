<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Announcement;
use Modules\Core\Models\City;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\Bank;
use Modules\Driver\Models\DriverLicense;
use Modules\Order\Models\Order;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\OrderDispatchLog;

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
            RTDBService::updateOrderLocation(
                $activeOrder->code,
                (float) $data['latitude'],
                (float) $data['longitude'],
            );
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
            'name'    => 'nullable|string|max:255',
            'email'   => 'nullable|email|max:255',
            'city_id' => 'nullable|integer|exists:cities,id',
            'avatar'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }
            $data['profile_photo_path'] = $request->file('avatar')->store('profile-photos', 'public');
        }
        unset($data['avatar']);

        $user->update(array_filter($data));
        $user->refresh();

        $userData = $user->toArray();
        if ($user->profile_photo_path) {
            $userData['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }

        return response()->json(['success' => true, 'message' => 'Cập nhật thành công', 'data' => ['user' => $userData]]);
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
        $since = now()->subDays(30);

        $orders = Order::where('delivery_man_id', $user->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) as total, SUM(status='completed') as completed, SUM(status='cancelled') as cancelled")
            ->first();

        $dispatch = OrderDispatchLog::where('driver_id', $user->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) as offered, SUM(result='accepted') as accepted")
            ->first();

        $offered   = (int) ($dispatch->offered ?? 0);
        $accepted  = (int) ($dispatch->accepted ?? 0);
        $completed = (int) ($orders->completed ?? 0);
        $total     = (int) ($orders->total ?? 0);

        return response()->json(['success' => true, 'data' => [
            'total'           => $total,
            'completed'       => $completed,
            'cancelled'       => (int) ($orders->cancelled ?? 0),
            'acceptance_rate' => $offered > 0 ? round($accepted / $offered * 100) : null,
            'completion_rate' => $total > 0 ? round($completed / $total * 100) : null,
        ]]);
    }

    public function score(Request $request): JsonResponse
    {
        $user  = $request->user();
        $score = (int) ($user->driver_score ?? DriverScoreService::DEFAULT_SCORE);
        $streak = (int) ($user->consecutive_completed ?? 0);

        // Xác định đợt dispatch hiện tại
        if ($score >= DriverScoreService::WAVE_1_MIN) {
            $wave = 1;
            $waveDesc = 'Ưu tiên cao — nhận đơn ngay, bán kính 5km';
            $nextWave = null;
        } elseif ($score >= DriverScoreService::WAVE_2_MIN) {
            $wave = 2;
            $waveDesc = 'Ưu tiên vừa — nhận đơn sau 2 phút, bán kính 10km';
            $nextWave = [
                'wave'         => 1,
                'min_score'    => DriverScoreService::WAVE_1_MIN,
                'points_needed' => DriverScoreService::WAVE_1_MIN - $score,
            ];
        } else {
            $wave = 3;
            $waveDesc = 'Ưu tiên thấp — nhận đơn sau 4 phút, bán kính 10km';
            $nextWave = [
                'wave'         => 2,
                'min_score'    => DriverScoreService::WAVE_2_MIN,
                'points_needed' => DriverScoreService::WAVE_2_MIN - $score,
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'score'     => $score,
            'max_score' => DriverScoreService::MAX_SCORE,
            'label'     => DriverScoreService::label($score),
            'wave'      => $wave,
            'wave_desc' => $waveDesc,
            'next_wave' => $nextWave,
            'streak'    => [
                'consecutive' => $streak,
                'bonus_at'    => DriverScoreService::STREAK_THRESHOLD,
                'bonus_pts'   => DriverScoreService::SCORE_STREAK_BONUS,
            ],
            'tips' => DriverScoreService::tips($score, $streak),
        ]]);
    }

    public function scoreHistory(Request $request): JsonResponse
    {
        $logs = \DB::table('driver_score_logs')
            ->where('driver_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['delta', 'score_before', 'score_after', 'reason', 'created_at']);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function updateBank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_code'      => 'required|string|max:50',
            'bank_name'      => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'account_name'   => 'required|string|max:255',
        ]);

        $user = $request->user();
        Bank::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($data, ['user_id' => $user->id]),
        );

        return response()->json(['success' => true, 'message' => 'Cập nhật tài khoản ngân hàng thành công']);
    }

    public function uploadLicense(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();
        $path = $request->file('image')->store('driver-licenses', 'public');

        DriverLicense::create([
            'user_id'    => $user->id,
            'image_path' => $path,
            'status'     => 'pending',
        ]);

        return response()->json([
            'success'   => true,
            'message'   => 'Tải lên thành công, đang chờ xét duyệt',
            'image_url' => url('storage/' . $path),
        ]);
    }

    public function bankLists(Request $request): JsonResponse
    {
        $banks = \DB::table('bank_lists')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'logo_url']);

        return response()->json(['success' => true, 'data' => $banks]);
    }

    public function cities(Request $request): JsonResponse
    {
        $cities = City::active()->orderBy('name')->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $cities]);
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

    public function hotspots(Request $request): JsonResponse
    {
        $user = $request->user();

        $spots = Order::where('city_id', $user->city_id)
            ->whereNotNull('pickup_lat')
            ->whereNotNull('pickup_lng')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('ROUND(pickup_lat, 2) as lat, ROUND(pickup_lng, 2) as lng, COUNT(*) as count')
            ->groupBy('lat', 'lng')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        return response()->json(['success' => true, 'data' => $spots]);
    }
}
