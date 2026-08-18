<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Customer\Services\OtpService;

class AuthController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string']);
        $phone = $this->normalizePhone($data['phone']);

        // Chặn ngay từ bước gửi mã — không thì tốn tiền OTP vô ích và người
        // dùng điền hết form mới biết số đã có tài khoản.
        if (User::where('phone', $phone)->where('user_type', 'shop')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký shop. Vui lòng đăng nhập hoặc dùng Quên mật khẩu.'], 422);
        }

        if (OtpService::recentlySent($phone, 'register')) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        OtpService::send($phone, 'register');

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi tới ' . $phone]);
    }

    public function verifyOtpAndRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'         => 'required|string',
            'otp'           => 'required|string|size:6',
            'name'          => 'required|string|max:255',
            'address'       => 'nullable|string|max:500',
            'password'      => 'required|string|min:6',
            'city_id'       => 'required|integer|exists:cities,id',
            'lat'           => 'sometimes|nullable|numeric',
            'lng'           => 'sometimes|nullable|numeric',
            'device_name'   => 'sometimes|nullable|string|max:255',
            'location'      => 'sometimes|nullable|string|max:255',
        ]);

        $phone = $this->normalizePhone($data['phone']);

        if (!OtpService::verify($phone, $data['otp'], 'register')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        if (User::where('phone', $phone)->where('user_type', 'shop')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký shop'], 422);
        }

        $user = User::create([
            'name'      => $data['name'],
            'phone'     => $phone,
            'address'   => $data['address'] ?? null,
            'password'  => bcrypt($data['password']),
            'user_type' => 'shop',
            'city_id'   => $data['city_id'] ?? null,
            'status'    => 1,
            'latitude'  => $data['lat'] ?? null,
            'longitude' => $data['lng'] ?? null,
        ]);

        $token = $this->issueToken($user, 'shop_token', $data);

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data  = $request->validate(['phone' => 'required|string']);
        $phone = $this->normalizePhone($data['phone']);

        if (!User::where('phone', $phone)->where('user_type', 'shop')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại chưa được đăng ký'], 422);
        }

        if (OtpService::recentlySent($phone, 'forgot_password')) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        OtpService::send($phone, 'forgot_password');

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi tới ' . $phone]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string',
            'otp'      => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $phone = $this->normalizePhone($data['phone']);

        if (!OtpService::verify($phone, $data['otp'], 'forgot_password')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        $user = User::where('phone', $phone)->where('user_type', 'shop')->first();

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
            'phone'       => 'required|string',
            'password'    => 'required|string',
            'fcm_token'   => 'nullable|string',
            'device_id'   => 'nullable|string',
            'device_name' => 'sometimes|nullable|string|max:255',
            'location'    => 'sometimes|nullable|string|max:255',
        ]);

        $user = User::where('phone', $this->normalizePhone($data['phone']))
            ->where('user_type', 'shop')
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => ['Số điện thoại hoặc mật khẩu không đúng']]);
        }

        if ($user->status == 2) {
            return response()->json(['success' => false, 'message' => 'Tài khoản bị khóa'], 403);
        }

        if (!empty($data['fcm_token'])) {
            User::where('fcm_token', $data['fcm_token'])->where('id', '!=', $user->id)->update(['fcm_token' => null]);
            $user->fcm_token = $data['fcm_token'];
            $user->save();
        }

        $tokenName = !empty($data['device_id']) ? "shop_token_{$data['device_id']}" : 'shop_token';
        // Chỉ xoá token CŨ của CÙNG thiết bị (trùng tên) — không xoá token
        // của thiết bị khác nữa, để hỗ trợ nhiều phiên đăng nhập song song
        // (quản lý qua GET/DELETE /shop/auth/devices).
        $user->tokens()->where('name', $tokenName)->delete();
        $token = $this->issueToken($user, $tokenName, $data);

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

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'address' => 'sometimes|nullable|string|max:500',
            'email'   => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'city_id' => 'sometimes|integer|exists:cities,id',
            'lat'     => 'sometimes|nullable|numeric',
            'lng'     => 'sometimes|nullable|numeric',
        ]);

        // Request dùng lat/lng ngắn gọn nhưng cột thật trên bảng users là
        // latitude/longitude (đã có sẵn, dùng chung với vị trí tài xế cho
        // dispatch) — map lại tên field trước khi mass-update.
        if (array_key_exists('lat', $data)) {
            $data['latitude'] = $data['lat'];
            unset($data['lat']);
        }
        if (array_key_exists('lng', $data)) {
            $data['longitude'] = $data['lng'];
            unset($data['lng']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thành công',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $request->file('image')->store("avatars/shop/{$user->id}", 'public');
        $user->update(['profile_photo_path' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật ảnh thành công',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Mật khẩu hiện tại không đúng'], 422);
        }

        $user->update(['password' => bcrypt($request->new_password)]);

        return response()->json(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
    }

    public function changePhoneSendOtp(Request $request): JsonResponse
    {
        $data     = $request->validate(['new_phone' => 'required|string']);
        $newPhone = $this->normalizePhone($data['new_phone']);
        $user     = $request->user();

        if ($newPhone === $user->phone) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại mới trùng với số hiện tại'], 422);
        }

        if (User::where('phone', $newPhone)->where('user_type', 'shop')->where('id', '!=', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được dùng bởi tài khoản shop khác'], 422);
        }

        if (OtpService::recentlySent($newPhone, 'change_phone')) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        OtpService::send($newPhone, 'change_phone');

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi tới ' . $newPhone]);
    }

    public function changePhoneVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_phone' => 'required|string',
            'otp'       => 'required|string|size:6',
        ]);
        $newPhone = $this->normalizePhone($data['new_phone']);
        $user     = $request->user();

        if (!OtpService::verify($newPhone, $data['otp'], 'change_phone')) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        // Cột phone unique toàn hệ thống (không riêng shop) — kiểm tra lại
        // ngay trước khi ghi để tránh lỗi 500 do trùng với tài khoản khác
        // loại (driver/customer) xảy ra giữa lúc gửi OTP và lúc verify.
        if (User::where('phone', $newPhone)->where('id', '!=', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được sử dụng'], 422);
        }

        $user->update(['phone' => $newPhone]);

        return response()->json([
            'success' => true,
            'message' => 'Đổi số điện thoại thành công',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    public function devices(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $devices = $request->user()->tokens()
            ->where('name', 'like', 'shop_token%')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id'             => $token->id,
                'device_name'    => $token->device_name,
                'location'       => $token->location,
                'last_active_at' => $token->last_used_at,
                'is_current'     => $token->id === $currentId,
            ]);

        return response()->json(['success' => true, 'data' => $devices]);
    }

    public function revokeDevice(Request $request, int $id): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        if ($id === $currentId) {
            return response()->json(['success' => false, 'message' => 'Không thể thu hồi phiên đang sử dụng'], 422);
        }

        $token = $request->user()->tokens()->where('id', $id)->first();

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy phiên đăng nhập'], 404);
        }

        $token->delete();

        return response()->json(['success' => true, 'message' => 'Đã thu hồi phiên đăng nhập']);
    }

    public function revokeOtherDevices(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;
        $request->user()->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json(['success' => true, 'message' => 'Đã thu hồi các phiên đăng nhập khác']);
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate(['fcm_token' => 'required|string']);
        $fcmToken = $request->fcm_token;
        User::where('fcm_token', $fcmToken)->where('id', '!=', $request->user()->id)->update(['fcm_token' => null]);
        $request->user()->update(['fcm_token' => $fcmToken]);
        return response()->json(['success' => true]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        \Modules\Order\Models\Order::where('sender_platform_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Tài khoản đã được xóa vĩnh viễn']);
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing('city');
        return [
            'id'                 => $user->id,
            'name'               => $user->name,
            'phone'              => $user->phone,
            'email'              => $user->email,
            'address'            => $user->address,
            'lat'                => $user->latitude,
            'lng'                => $user->longitude,
            'profile_photo_path' => $user->profile_photo_path,
            'avatar_url'         => $user->profile_photo_path
                ? Storage::disk('public')->url($user->profile_photo_path)
                : null,
            'user_type'          => $user->user_type,
            'city_id'            => $user->city_id,
            'city_name'          => $user->city?->name ?? '',
            'status'             => $user->status,
        ];
    }

    private function issueToken(User $user, string $tokenName, array $data): string
    {
        $newToken = $user->createToken($tokenName);

        // Gán trực tiếp thay vì update() mảng — device_name/location không
        // nằm trong $fillable gốc của Laravel\Sanctum\PersonalAccessToken
        // (chỉ có name/token/abilities/expires_at), update() sẽ âm thầm bỏ qua.
        $newToken->accessToken->device_name = $data['device_name'] ?? null;
        $newToken->accessToken->location    = $data['location'] ?? null;
        $newToken->accessToken->save();

        return $newToken->plainTextToken;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '84')) {
            $digits = '0' . substr($digits, 2);
        }
        return $digits;
    }
}
