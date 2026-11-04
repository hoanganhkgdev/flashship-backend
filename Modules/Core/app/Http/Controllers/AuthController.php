<?php
namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\FirebasePhoneVerifier;
use Modules\Core\Services\RTDBService;

class AuthController extends Controller
{
    /** Sai mật khẩu bấy nhiêu lần thì khoá đăng nhập tài khoản đó trong LOGIN_LOCK_SECONDS. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_LOCK_SECONDS = 900;

    /**
     * Kiểm tra số điện thoại TRƯỚC khi app xin Firebase gửi SMS — tránh tốn tiền
     * SMS cho số đã đăng ký (register) hoặc chưa có tài khoản (forgot_password).
     */
    public function checkPhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'   => 'required|string',
            'purpose' => 'required|in:register,forgot_password',
        ]);

        $exists = User::where('phone', FirebasePhoneVerifier::normalize($data['phone']))->where('user_type', 'driver')->exists();

        if ($data['purpose'] === 'register' && $exists) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký. Vui lòng đăng nhập hoặc dùng Quên mật khẩu.'], 422);
        }
        if ($data['purpose'] === 'forgot_password' && !$exists) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại chưa được đăng ký'], 422);
        }

        return response()->json(['success' => true]);
    }

    public function verifyOtpAndRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string',
            'id_token'  => 'required|string',
            'name'      => 'required|string|max:255',
            'password'  => 'required|string|min:6',
            'city_id'   => 'required|integer|exists:cities,id',
            'fcm_token' => 'nullable|string',
            'device_id' => 'required|string|max:191',
            // Chỉ ảnh, lưu vào thư mục công khai: không nhận HTML/SVG… (chạy được trên tên miền app).
            'avatar'    => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        // Lưu và tìm theo một dạng duy nhất (0xxxxxxxxx): "84912…" và "0912…" là cùng một số.
        $data['phone'] = FirebasePhoneVerifier::normalize($data['phone']);

        if (FirebasePhoneVerifier::verifiedPhone($data['id_token']) !== $data['phone']) {
            return response()->json(['success' => false, 'message' => 'Xác minh số điện thoại không hợp lệ hoặc đã hết hạn'], 422);
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
                'city_id'            => (int) $data['city_id'],
                'profile_photo_path' => $path,
                'fcm_token'          => $data['fcm_token'] ?? null,
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
            $firebaseToken = RTDBService::createDriverAuthToken($user, $deviceId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công. Tài khoản đang chờ admin duyệt.',
            'data'    => ['user' => $user->load('city'), 'token' => $token, 'firebase_token' => $firebaseToken],
        ], 201);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string',
            'id_token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);
        $data['phone'] = FirebasePhoneVerifier::normalize($data['phone']);

        if (FirebasePhoneVerifier::verifiedPhone($data['id_token']) !== $data['phone']) {
            return response()->json(['success' => false, 'message' => 'Xác minh số điện thoại không hợp lệ hoặc đã hết hạn'], 422);
        }

        $user = User::where('phone', $data['phone'])->where('user_type', 'driver')->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không tồn tại'], 422);
        }

        $user->update(['password' => bcrypt($data['password'])]);
        $user->tokens()->delete();
        $this->forceDriverOffline($user);
        RTDBService::removeDriverLocation($user->id);
        // Máy đang đăng nhập (có thể là người lạ — lý do đặt lại mật khẩu) phải thoát thật: xoá token API chưa
        // đủ vì phiên Firebase vẫn ghi được GPS. Thu hồi phiên Firebase rồi đổi thiết bị giữ phiên → app máy đó
        // nhận tín hiệu và tự đăng xuất; Rules từ chối ghi.
        RTDBService::revokeDriverSessions($user->id);
        RTDBService::writeSessionDevice($user->id, 'reset-'.\Illuminate\Support\Str::random(12));

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
        $login = $field === 'phone' ? FirebasePhoneVerifier::normalize($data['login']) : mb_strtolower(trim($data['login']));

        // Giới hạn đoán mật khẩu THEO TÀI KHOẢN (giới hạn chung theo IP không đủ: đổi IP là đoán tiếp).
        $limitKey = 'driver-login:'.$field.':'.$login;
        if (RateLimiter::tooManyAttempts($limitKey, self::LOGIN_MAX_ATTEMPTS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($limitKey) / 60));

            return response()->json([
                'success' => false,
                'code' => 'too_many_attempts',
                'message' => "Nhập sai quá nhiều lần. Vui lòng thử lại sau {$minutes} phút hoặc dùng Quên mật khẩu.",
            ], 429);
        }

        $user  = User::where($field, $login)->where('user_type', 'driver')->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($limitKey, self::LOGIN_LOCK_SECONDS);
            throw ValidationException::withMessages(['login' => ['Tài khoản hoặc mật khẩu không đúng']]);
        }
        RateLimiter::clear($limitKey);

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
        $wasOnline = (bool) $user->is_online;
        $this->forceDriverOffline($user);
        RTDBService::removeDriverLocation($user->id);
        if ($wasOnline) {
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
                $firebaseToken = RTDBService::createDriverAuthToken($user, $deviceId);
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
        $this->forceDriverOffline($user);
        $user->update(['fcm_token' => null]);
        $user->currentAccessToken()->delete();
        RTDBService::removeDriverLocation($user->id);
        return response()->json(['success' => true, 'message' => 'Đăng xuất thành công']);
    }

    private function forceDriverOffline(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->update([
                'is_online' => false,
                'online_since' => null,
            ]);
            \Modules\Driver\Models\DriverShiftSession::where('driver_id', $user->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        });
        $user->is_online = false;
        $user->online_since = null;
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

        try {
            $currentDeviceId = RTDBService::getSessionDevice($request->user()->id);
        } catch (\Throwable $e) {
            // Mất mạng tới Firebase ≠ bị máy khác đăng nhập đè: giữ nguyên phiên,
            // app tự xin lại firebase_token ở lần sau.
            \Illuminate\Support\Facades\Log::warning('[RTDB] getSessionDevice failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Không kết nối được máy chủ realtime, vui lòng thử lại',
            ], 503);
        }

        if (!$currentDeviceId || !hash_equals($currentDeviceId, $data['device_id'])) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Phiên đăng nhập đã được thay thế bởi thiết bị khác',
                'code'    => 'session_replaced',
            ], 401);
        }

        $firebaseToken = RTDBService::createDriverAuthToken($request->user(), $data['device_id']);

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
            $data['license_image_url'] = \App\Support\KycFile::url($license->image_path);
        }
        if ($user->profile_photo_path) {
            $data['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }
        if ($user->user_type === 'driver') {
            $data['max_active_orders'] = \Modules\Core\Services\OperationalSettings::maxActiveOrdersPerDriver($user->city_id);
        }
        return $data;
    }
}
