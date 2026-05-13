<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Customer\Services\OtpService;

class AuthController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string',
            'type'  => 'required|in:register,reset_password,login',
        ]);

        if ($data['type'] === 'register' && User::where('phone', $data['phone'])->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký'], 422);
        }

        if ($data['type'] === 'reset_password' && !User::where('phone', $data['phone'])->where('user_type', 'customer')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại chưa đăng ký'], 422);
        }

        if (OtpService::recentlySent($data['phone'], $data['type'])) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        $code = OtpService::send($data['phone'], $data['type']);

        $response = ['success' => true, 'message' => 'Mã OTP đã được gửi đến ' . $data['phone']];
        if (config('app.env') !== 'production') {
            $response['otp_code'] = $code;
        }
        return response()->json($response);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'                     => 'required|string',
            'otp_code'                  => 'required|string|size:6',
            'new_password'              => 'required|string|min:6|confirmed',
        ]);

        if (!OtpService::verify($data['phone'], $data['otp_code'], 'reset_password')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không đúng hoặc đã hết hạn'], 422);
        }

        $user = User::where('phone', $data['phone'])->where('user_type', 'customer')->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không tồn tại'], 404);
        }

        $user->update(['password' => bcrypt($data['new_password'])]);

        return response()->json(['success' => true, 'message' => 'Đặt lại mật khẩu thành công']);
    }

    public function phoneLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string',
            'otp_code' => 'required|string|size:6',
        ]);

        if (!OtpService::verify($data['phone'], $data['otp_code'], 'login')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không đúng hoặc đã hết hạn'], 422);
        }

        $phone = $this->normalizePhone($data['phone']);
        $user  = User::where('phone', $phone)->first();

        if (!$user) {
            $verificationToken = encrypt(json_encode([
                'phone'   => $phone,
                'expires' => now()->addMinutes(15)->timestamp,
            ]));
            return response()->json([
                'success'            => true,
                'needs_profile'      => true,
                'verification_token' => $verificationToken,
            ]);
        }

        if ($user->status == 2) {
            return response()->json(['success' => false, 'message' => 'Tài khoản bị khóa'], 403);
        }

        if (!in_array($user->user_type, ['customer', 'shop'])) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không hợp lệ'], 403);
        }

        $user->tokens()->where('name', 'like', 'customer_token%')->delete();
        $token = $user->createToken('customer_token')->plainTextToken;

        return response()->json([
            'success'       => true,
            'needs_profile' => false,
            'data'          => ['token' => $token, 'user' => $this->formatUser($user)],
        ]);
    }

    public function completeProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_token' => 'required|string',
            'name'               => 'required|string|max:255',
            'email'              => 'nullable|email|unique:users,email',
            'city_id'            => 'nullable|integer|exists:cities,id',
            'latitude'           => 'nullable|numeric',
            'longitude'          => 'nullable|numeric',
        ]);

        try {
            $payload = json_decode(decrypt($data['verification_token']), true);
        } catch (\Exception) {
            return response()->json(['success' => false, 'message' => 'Token không hợp lệ'], 422);
        }

        if (!$payload || ($payload['expires'] ?? 0) < now()->timestamp) {
            return response()->json(['success' => false, 'message' => 'Token đã hết hạn, vui lòng xác thực lại'], 422);
        }

        $phone = $payload['phone'];

        if (User::where('phone', $phone)->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký'], 422);
        }

        if (empty($data['city_id']) && !empty($data['latitude']) && !empty($data['longitude'])) {
            $data['city_id'] = $this->findNearestCity((float)$data['latitude'], (float)$data['longitude']);
        }

        $user = User::create([
            'name'      => $data['name'],
            'phone'     => $phone,
            'password'  => bcrypt(\Illuminate\Support\Str::random(32)),
            'email'     => $data['email'] ?? null,
            'user_type' => 'customer',
            'city_id'   => $data['city_id'] ?? null,
            'status'    => 1,
        ]);

        $token = $user->createToken('customer_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
        ], 201);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'phone'          => 'required|string|unique:users,phone',
            'password'       => 'required|string|min:6',
            'city_id'        => 'nullable|integer|exists:cities,id',
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'firebase_token' => 'required|string',
        ]);

        if (!$this->verifyFirebasePhone($data['firebase_token'], $data['phone'])) {
            return response()->json(['success' => false, 'message' => 'Xác thực số điện thoại thất bại'], 422);
        }

        if (empty($data['city_id']) && !empty($data['latitude']) && !empty($data['longitude'])) {
            $data['city_id'] = $this->findNearestCity((float)$data['latitude'], (float)$data['longitude']);
        }

        $user = User::create([
            'name'      => $data['name'],
            'phone'     => $data['phone'],
            'password'  => bcrypt($data['password']),
            'user_type' => 'customer',
            'city_id'   => $data['city_id'] ?? null,
            'status'    => 1,
        ]);

        $token = $user->createToken('customer_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
        ], 201);
    }

    private function verifyFirebasePhone(string $idToken, string $phone): bool
    {
        $apiKey = config('services.firebase.web_api_key');
        try {
            $response = Http::post(
                "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={$apiKey}",
                ['idToken' => $idToken]
            );
            if (!$response->successful()) return false;

            $firebasePhone = $response->json('users.0.phoneNumber', '');
            return $this->normalizePhone($firebasePhone) === $this->normalizePhone($phone);
        } catch (\Exception) {
            return false;
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        // +84901234567 → 0901234567
        if (strlen($digits) === 11 && str_starts_with($digits, '84')) {
            $digits = '0' . substr($digits, 2);
        }
        return $digits;
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string',
            'password'  => 'required|string',
            'fcm_token' => 'nullable|string',
            'device_id' => 'nullable|string',
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => ['Số điện thoại hoặc mật khẩu không đúng']]);
        }

        if (!in_array($user->user_type, ['customer', 'shop'])) {
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
        $tokenName = $deviceId ? "customer_token_{$deviceId}" : 'customer_token';
        $user->tokens()->where('name', 'like', 'customer_token%')->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
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

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        // Huỷ các đơn đang chờ (pending) của khách trước khi xóa
        \Modules\Order\Models\Order::where('sender_platform_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        // Xóa toàn bộ token
        $user->tokens()->delete();

        // Xóa tài khoản
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Tài khoản đã được xóa vĩnh viễn']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'email'   => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'city_id' => 'sometimes|integer|exists:cities,id',
        ]);

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thành công',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate(['fcm_token' => 'required|string']);
        $fcmToken = $request->fcm_token;

        // Remove token from any other user to avoid duplicate deliveries.
        User::where('fcm_token', $fcmToken)->where('id', '!=', $request->user()->id)->update(['fcm_token' => null]);

        $request->user()->update(['fcm_token' => $fcmToken]);

        return response()->json(['success' => true]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $request->file('image')->store("avatars/{$user->id}", 'public');
        $user->update(['profile_photo_path' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật ảnh đại diện thành công',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu hiện tại không đúng',
            ], 422);
        }

        $user->update(['password' => bcrypt($request->new_password)]);

        return response()->json([
            'success' => true,
            'message' => 'Đổi mật khẩu thành công',
        ]);
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing('city');
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'phone'               => $user->phone,
            'email'               => $user->email,
            'profile_photo_path'  => $user->profile_photo_path,
            'avatar_url'          => $user->profile_photo_path
                                        ? Storage::disk('public')->url($user->profile_photo_path)
                                        : null,
            'user_type'           => $user->user_type,
            'city_id'             => $user->city_id,
            'city_name'           => $user->city?->name ?? '',
            'status'              => $user->status,
        ];
    }

    private function findNearestCity(float $lat, float $lng): ?int
    {
        $cities = \Modules\Core\Models\City::where('is_active', true)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get(['id', 'lat', 'lng']);

        if ($cities->isEmpty()) {
            return \Modules\Core\Models\City::where('is_active', true)->value('id');
        }

        $nearest = null;
        $minDist = PHP_FLOAT_MAX;
        foreach ($cities as $city) {
            $dLat = deg2rad((float)$city->lat - $lat);
            $dLng = deg2rad((float)$city->lng - $lng);
            $d    = $dLat * $dLat + $dLng * $dLng;
            if ($d < $minDist) { $minDist = $d; $nearest = $city->id; }
        }
        return $nearest;
    }
}
