<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\OtpService;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\DriverGeoService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\Bank;
use Modules\Driver\Models\DriverCccdImage;
use Modules\Driver\Models\DriverLicense;
use Modules\Order\Models\Order;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\OrderDispatchLog;

class DriverController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['city', 'bank', 'driverLicenses', 'driverCccdImages', 'wallet']);

        $data = $user->applyTodayOnline($user->toArray());
        $data['city_name']    = $user->city?->name ?? '';
        $data['balance']      = $user->wallet?->balance ?? 0;
        $data['bank_code']     = $user->bank?->bank_code;
        $data['bank_name']     = $user->bank?->bank_name;
        $data['bank_account']  = $user->bank?->account_number;
        $data['bank_holder']   = $user->bank?->account_name;
        $license = $user->driverLicenses->sortByDesc('id')->first();
        if ($license) {
            $data['license_status']            = $license->status;
            $data['license_image_url']         = $license->image_path ? url('storage/' . $license->image_path) : null;
            $data['license_rejection_reason']  = $license->status === 'rejected' ? $license->rejection_reason : null;
        }
        if ($user->profile_photo_path) {
            $data['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }
        $cccd = $user->driverCccdImages->sortByDesc('id')->first();
        $data['cccd_image_status']            = $cccd?->status;
        $data['cccd_image_url']               = $cccd?->image_path ? url('storage/' . $cccd->image_path) : null;
        $data['cccd_image_rejection_reason']  = $cccd?->status === 'rejected' ? $cccd->rejection_reason : null;
        $data['name_locked']          = (bool) $user->name_updated_at;
        $avatarCooldown               = $user->avatar_updated_at && $user->avatar_updated_at->diffInDays(now()) < 30;
        $data['avatar_locked']        = $avatarCooldown;
        $data['avatar_next_update_at'] = $avatarCooldown
            ? $user->avatar_updated_at->addDays(30)->toIso8601String()
            : null;

        return response()->json(['success' => true, 'data' => ['user' => $data]]);
    }

    public function toggleOnline(Request $request): JsonResponse
    {
        $user = $request->user();

        // Chỉ cho phép bật online nếu CCCD đã được duyệt
        if (!$user->is_online) {
            $approved = DriverCccdImage::where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if (!$approved) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn cần tải lên và được duyệt CCCD trước khi có thể hoạt động.',
                ], 403);
            }

            // Chặn bật online khi có công nợ quá hạn
            $overdueDebt = $user->debts()->where('status', 'overdue')->first();
            if ($overdueDebt) {
                $remaining = $overdueDebt->amount_due - $overdueDebt->amount_paid;
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn có công nợ quá hạn ' . number_format($remaining, 0, ',', '.') . '₫. Vui lòng thanh toán trước khi hoạt động.',
                    'code'    => 'debt_overdue',
                ], 403);
            }
        }

        // Vi phạm ca: tắt online giữa ca đã đăng ký → phạt nặng
        if ($user->is_online && $user->shift_id) {
            $shift = \Modules\Core\Models\Shift::find($user->shift_id);
            if ($shift && $shift->is_active && $shift->isNowInShift()) {
                DriverScoreService::onShiftViolation($user->id);
                \Log::warning("[Shift] #{$user->id} tắt online giữa {$shift->name} → -15 điểm");
            }
        }

        // Tích lũy thời gian online khi chuyển sang offline
        \Log::info("[Toggle] #{$user->id} PRE: is_online={$user->is_online} online_since={$user->online_since} daily_online_seconds={$user->daily_online_seconds}");
        if ($user->is_online && $user->online_since) {
            $now         = now();
            $today       = $now->toDateString();
            $windowStart = User::onlineWindowStart($now);

            // Chỉ tính phần phiên nằm trong [6:30 sáng nay, now].
            // now < 6:30 (vùng chết 00:00–6:30) → session này không tính giây nào.
            if ($now->greaterThan($windowStart)) {
                $sessionStart   = $user->online_since->greaterThan($windowStart)
                    ? $user->online_since
                    : $windowStart;
                $sessionSeconds = (int) max(0, $sessionStart->diffInSeconds($now, false));
            } else {
                $sessionSeconds = 0;
            }

            $existingSeconds = ($user->daily_online_date === $today)
                ? (int) ($user->daily_online_seconds ?? 0)
                : 0;

            $user->daily_online_seconds = $existingSeconds + $sessionSeconds;
            $user->daily_online_date    = $today;
        }

        $user->is_online   = !$user->is_online;
        $user->online_since = $user->is_online ? now() : null;

        // Bật online sang ngày mới → reset bộ đếm giờ online tích luỹ của hôm trước
        // (khối tích luỹ ở trên chỉ chạy khi TẮT online nên không reset được ở đây).
        if ($user->is_online && $user->daily_online_date !== now()->toDateString()) {
            $user->daily_online_seconds = 0;
            $user->daily_online_date    = now()->toDateString();
        }

        $user->save();

        RTDBService::setDriverOnlineStatus($user->id, (bool) $user->is_online);

        if ($user->city_id) {
            if ($user->is_online && $user->latitude && $user->longitude) {
                DriverGeoService::add($user->id, $user->city_id, (float) $user->latitude, (float) $user->longitude);
            } else {
                DriverGeoService::remove($user->id, $user->city_id);
            }
        }

        $responseSeconds = (int) ($user->daily_online_seconds ?? 0);
        \Log::info("[Toggle] #{$user->id} → online={$user->is_online} daily_online_seconds={$responseSeconds} online_since={$user->online_since}");

        return response()->json([
            'success'              => true,
            'message'             => $user->is_online ? 'Bạn đang online' : 'Bạn đang offline',
            'is_online'           => $user->is_online,
            'online_since'        => $user->online_since?->toIso8601String(),
            'daily_online_seconds' => $responseSeconds,
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'bearing'   => 'nullable|numeric',
        ]);

        $driver = $request->user();
        $driver->update([
            'latitude'         => $data['latitude'],
            'longitude'        => $data['longitude'],
            'bearing'          => $data['bearing'] ?? $driver->bearing,
            'last_location_at' => now(),
        ]);

        if ($driver->city_id) {
            DriverGeoService::add($driver->id, $driver->city_id, (float) $data['latitude'], (float) $data['longitude']);
        }

        RTDBService::updateDriverLocation($driver->id, [
            'lat'          => (float) $data['latitude'],
            'lng'          => (float) $data['longitude'],
            'bearing'      => isset($data['bearing']) ? (float) $data['bearing'] : null,
            'city_id'      => $driver->city_id,
            'name'         => $driver->name,
            'phone'        => $driver->phone,
            'driver_score' => (int) ($driver->driver_score ?? 100),
        ]);

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
            'name'          => 'nullable|string|max:255',
            'email'         => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'city_id'       => 'nullable|integer|exists:cities,id',
            'avatar'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'vehicle_type'  => 'nullable|in:motorbike,car',
            'license_plate' => 'nullable|string|max:20',
        ]);

        $user = $request->user();

        // Tên chỉ được đổi 1 lần
        if (isset($data['name']) && $data['name'] !== $user->name) {
            if ($user->name_updated_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tên chỉ được thay đổi một lần.',
                ], 403);
            }
            $data['name_updated_at'] = now();
        } else {
            unset($data['name']);
        }

        // Avatar chỉ được upload 1 lần
        if ($request->hasFile('avatar')) {
            $lastUpdate = $user->avatar_updated_at;
            if ($lastUpdate && $lastUpdate->diffInDays(now()) < 30) {
                $nextAllowed = $lastUpdate->addDays(30);
                $daysLeft    = (int) ceil(now()->floatDiffInDays($nextAllowed));
                return response()->json([
                    'success' => false,
                    'message' => "Ảnh đại diện chỉ được thay đổi 1 tháng 1 lần. Còn {$daysLeft} ngày nữa.",
                ], 403);
            }
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }
            $data['profile_photo_path'] = $request->file('avatar')->store('profile-photos', 'public');
            $data['avatar_updated_at']  = now();
        }
        unset($data['avatar']);

        $user->update(array_filter($data, fn($v) => $v !== null));
        $user->refresh();

        $userData = $user->toArray();
        if ($user->profile_photo_path) {
            $userData['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }

        return response()->json(['success' => true, 'message' => 'Cập nhật thành công', 'data' => ['user' => $userData]]);
    }

    public function sendChangePasswordOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        if (OtpService::recentlySent($user->phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng chờ 60 giây trước khi gửi lại',
            ], 429);
        }

        OtpService::send($user->phone);

        return response()->json([
            'success' => true,
            'message' => 'Mã OTP đã được gửi qua Zalo đến số ' . $user->phone,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp'                      => 'required|string|size:6',
            'new_password'             => 'required|string|min:6',
            'new_password_confirmation' => 'required|same:new_password',
        ]);

        $user = $request->user();

        if (!OtpService::verify($user->phone, $data['otp'])) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        $user->update(['password' => Hash::make($data['new_password'])]);
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

        return response()->json(['success' => true, 'data' => [
            'score'     => $score,
            'max_score' => DriverScoreService::MAX_SCORE,
            'label'     => DriverScoreService::label($score),
            'tips'      => DriverScoreService::tips($score),
        ]]);
    }

    public function scoreHistory(Request $request): JsonResponse
    {
        $perPage = 10;
        $page    = max(1, (int) $request->query('page', 1));

        $total = \DB::table('driver_score_logs')
            ->where('driver_id', $request->user()->id)
            ->count();

        $logs = \DB::table('driver_score_logs')
            ->where('driver_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['delta', 'score_before', 'score_after', 'reason', 'created_at']);

        return response()->json([
            'success'  => true,
            'data'     => $logs,
            'has_more' => ($page * $perPage) < $total,
        ]);
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

    public function uploadCccdImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $path = $request->file('image')->store('driver-cccd', 'public');

        DriverCccdImage::create([
            'user_id'    => $request->user()->id,
            'image_path' => $path,
            'status'     => 'pending',
        ]);

        return response()->json([
            'success'   => true,
            'message'   => 'Tải lên thành công, đang chờ xét duyệt',
            'image_url' => url('storage/' . $path),
        ]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $user = $request->user();

        $shifts = \Modules\Core\Models\Shift::active()
            ->forCity($user->city_id)
            ->orderBy('start_time')
            ->get(['id', 'code', 'name', 'start_time', 'end_time']);

        return response()->json([
            'success'        => true,
            'data'           => $shifts,
            'current_shift_id' => $user->shift_id,
        ]);
    }

    public function selectShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        $user = $request->user();
        $user->update(['shift_id' => $data['shift_id'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => $data['shift_id'] ? 'Đã đăng ký ca làm việc' : 'Đã huỷ đăng ký ca',
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
