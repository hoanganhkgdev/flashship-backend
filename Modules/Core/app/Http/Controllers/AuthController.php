<?php
namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\OtpService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;

class AuthController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string']);

        // Chặn ngay từ bước gửi mã — không thì tốn tiền SMS/ZNS vô ích và
        // người dùng điền hết form mới biết số đã có tài khoản.
        if (User::where('phone', $data['phone'])->where('user_type', 'driver')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký. Vui lòng đăng nhập hoặc dùng Quên mật khẩu.'], 422);
        }

        if (!OtpService::sendThrottled($data['phone'])) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi đến ' . $data['phone']]);
    }

    public function verifyOtpAndRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string',
            'otp'       => 'required|string|size:6',
            'name'      => 'required|string|max:255',
            'password'  => 'required|string|min:6',
            'city_id'   => 'required|integer|exists:cities,id',
            'fcm_token' => 'nullable|string',
            'device_id' => 'required|string|max:191',
        ]);

        if (!OtpService::verify($data['phone'], $data['otp'])) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        if (User::where('phone', $data['phone'])->where('user_type', 'driver')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký'], 422);
        }

        $path = null;
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('profiles', 'public');
        }

        [$user, $token] = DB::transaction(function () use ($data, $path) {
            $user = User::create([
                'name'               => $data['name'],
                'phone'              => $data['phone'],
                'password'           => bcrypt($data['password']),
                'status'             => 0,
                'user_type'          => 'driver',
                'city_id'            => $data['city_id'],
                'profile_photo_path' => $path,
                'fcm_token'          => $data['fcm_token'] ?? null,
                'driver_score'       => DriverScoreService::DEFAULT_SCORE,
            ]);
            $token = $user->createToken('api_token')->plainTextToken;
            return [$user, $token];
        });

        // Cấp luôn firebase_token theo device_id ngay lúc đăng ký (giống
        // login()) — tránh tài xế mới duyệt xong online mà chưa từng có
        // Firebase Auth, bị Security Rules chặn ghi GPS oan.
        $deviceId      = $data['device_id'] ?? null;
        $firebaseToken = null;
        if ($deviceId) {
            RTDBService::writeSessionDevice($user->id, $deviceId);
            $firebaseToken = RTDBService::createCustomAuthToken("driver_{$user->id}_{$deviceId}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công. Tài khoản đang chờ admin duyệt.',
            'data'    => ['user' => $user->load('city'), 'token' => $token, 'firebase_token' => $firebaseToken],
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data  = $request->validate(['phone' => 'required|string']);
        $phone = $data['phone'];

        if (!User::where('phone', $phone)->where('user_type', 'driver')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại chưa được đăng ký'], 422);
        }

        if (!OtpService::sendThrottled($phone, 'forgot_password')) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi tới ' . $phone]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string',
            'otp'      => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!OtpService::verify($data['phone'], $data['otp'], 'forgot_password')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        $user = User::where('phone', $data['phone'])->where('user_type', 'driver')->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không tồn tại'], 422);
        }

        $user->update(['password' => bcrypt($data['password'])]);
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Đặt lại mật khẩu thành công']);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login'     => 'required|string',
            'password'  => 'required|string',
            'fcm_token' => 'nullable|string',
            'device_id' => 'required|string|max:191',
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user  = User::where($field, $data['login'])->where('user_type', 'driver')->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['Tài khoản hoặc mật khẩu không đúng']]);
        }

        if (!in_array($user->user_type, ['driver'])) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không hợp lệ'], 403);
        }
        if ($user->status == 2) return response()->json(['success' => false, 'message' => 'Tài khoản bị khóa'], 403);

        $newFcmToken = $data['fcm_token'] ?? null;
        if ($newFcmToken) {
            User::where('fcm_token', $newFcmToken)->where('id', '!=', $user->id)->update(['fcm_token' => null]);
            $user->fcm_token = $newFcmToken;
            $user->save();
        }

        $deviceId  = $data['device_id'] ?? null;
        $tokenName = $deviceId ? "api_token_{$deviceId}" : 'api_token';
        $user->tokens()->where('name', 'like', 'api_token%')->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        // Token cũ vừa bị xoá ở trên khiến máy cũ (nếu có) không thể tự gọi
        // API báo offline được nữa (401) — xử lý luôn ở đây, lúc chắc chắn
        // biết phiên cũ đang bị thay thế, thay vì trông cậy máy cũ tự báo.
        if ($user->is_online) {
            DB::transaction(function () use ($user) {
                $user->update(['is_online' => false, 'online_since' => null]);
                \Modules\Driver\Models\DriverShiftSession::where('driver_id', $user->id)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now()]);
                $gpsSessions = \Modules\Driver\Models\DriverGpsEligibleSession::where('driver_id', $user->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->get();
                foreach ($gpsSessions as $gpsSession) {
                    $endedAt = $gpsSession->last_gps_at->copy()
                        ->addSeconds(\Modules\Driver\Services\DriverLocationService::POS_MAX_AGE_SECS)
                        ->min(now());
                    $gpsSession->update(['ended_at' => $endedAt]);
                }
            });
            RTDBService::removeDriverLocation($user->id);
            \Illuminate\Support\Facades\Log::info("[Auth] Driver #{$user->id} tự động chuyển offline do đăng nhập thiết bị mới.");
        }

        // Ghi device_id lên RTDB — thiết bị cũ sẽ tự detect và force logout
        // (qua listener), VÀ cấp thêm Firebase custom token gắn UID theo
        // đúng thiết bị này — để Security Rules chặn cứng ở tầng Firebase,
        // không phụ thuộc thiết bị cũ có nhận được tín hiệu logout kịp lúc
        // đang chạy nền hay không (xem điều tra tài xế #405/#414).
        $firebaseToken = null;
        if ($deviceId) {
            RTDBService::writeSessionDevice($user->id, $deviceId);
            // Phiên chờ xóa chỉ được xem hồ sơ/hủy yêu cầu, không cấp quyền
            // Firebase để app tiếp tục ghi GPS trực tiếp ngoài API.
            if (!$user->delete_requested_at) {
                $firebaseToken = RTDBService::createCustomAuthToken("driver_{$user->id}_{$deviceId}");
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user), 'firebase_token' => $firebaseToken],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->formatUser($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['fcm_token' => null]);
        $user->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Đăng xuất thành công']);
    }

    /**
     * Cấp lại firebase_token cho phiên ĐÃ đăng nhập sẵn — dùng lúc app tự
     * khôi phục phiên khi mở lại (không đi qua login()/verifyOtpAndRegister()
     * nên trước đây không bao giờ ký nhập Firebase). Chỉ cần Sanctum token
     * còn hiệu lực là đủ tin — nếu thiết bị này đã bị thay thế bởi 1 lần
     * đăng nhập khác, token cũ đã bị xoá lúc đó (login() xoá hết token cũ),
     * request sẽ tự 401 trước khi vào tới đây, không cần so lại session_device.
     */
    public function firebaseToken(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => 'required|string|max:191']);

        $currentDeviceId = RTDBService::getSessionDevice($request->user()->id);
        if (!$currentDeviceId || !hash_equals($currentDeviceId, $data['device_id'])) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Phiên đăng nhập đã được thay thế bởi thiết bị khác',
                'code'    => 'session_replaced',
            ], 401);
        }

        $firebaseToken = RTDBService::createCustomAuthToken(
            "driver_{$request->user()->id}_{$data['device_id']}"
        );

        return response()->json([
            'success' => true,
            'data'    => ['firebase_token' => $firebaseToken],
        ]);
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing(['city', 'bank', 'driverLicenses']);
        $data = $user->toArray();
        $data['city_name'] = $user->city?->name ?? '';
        $data['bank_name'] = $user->bank?->bank_name;
        $data['bank_account'] = $user->bank?->account_number;
        $license = $user->driverLicenses->sortByDesc('id')->first();
        if ($license) {
            $data['license_status'] = $license->status;
            $data['license_image_url'] = $license->image_path ? url('storage/' . $license->image_path) : null;
        }
        if ($user->profile_photo_path) {
            $data['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }
        return $data;
    }
}
