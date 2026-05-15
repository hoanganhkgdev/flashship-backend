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

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'phone'     => 'required|string',
            'password'  => 'required|string|min:6',
            'city_id'   => 'nullable|integer|exists:cities,id',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'fcm_token' => 'nullable|string',
        ]);

        if (User::where('phone', $data['phone'])->where('user_type', 'driver')->exists()) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký làm tài xế'], 422);
        }

        // Tự tìm city gần nhất nếu không có city_id nhưng có tọa độ
        if (empty($data['city_id']) && !empty($data['latitude']) && !empty($data['longitude'])) {
            $data['city_id'] = $this->findNearestCity((float)$data['latitude'], (float)$data['longitude']);
        }

        $path = null;
        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profiles', 'public');
        }

        [$user, $token] = DB::transaction(function () use ($data, $path) {
            $user = User::create([
                'name'               => $data['name'],
                'phone'              => $data['phone'],
                'password'           => bcrypt($data['password']),
                'status'             => 1,
                'user_type'          => 'driver',
                'city_id'            => $data['city_id'],
                'profile_photo_path' => $path,
                'fcm_token'          => $data['fcm_token'] ?? null,
            ]);

            $token = $user->createToken('api_token')->plainTextToken;
            return [$user, $token];
        });

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => ['user' => $user->load('city'), 'token' => $token],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login'     => 'required|string',
            'password'  => 'required|string',
            'fcm_token' => 'nullable|string',
            'device_id' => 'nullable|string',
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user  = User::where($field, $data['login'])->where('user_type', 'driver')->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['Tài khoản hoặc mật khẩu không đúng']]);
        }

        if (!in_array($user->user_type, ['driver'])) {
            return response()->json(['success' => false, 'message' => 'Tài khoản không hợp lệ'], 403);
        }
        if ($user->status == 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đang chờ admin duyệt. Vui lòng liên hệ để được hỗ trợ.',
                'code'    => 'account_pending',
            ], 403);
        }
        if ($user->status == 2) return response()->json(['success' => false, 'message' => 'Tài khoản bị khóa'], 403);
        if ($user->delete_requested_at) return response()->json(['success' => false, 'message' => 'Tài khoản đang chờ xóa'], 403);

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

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
        ]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $data  = $request->validate(['phone' => 'required|string']);
        $phone = $data['phone'];

        $existing = User::where('phone', $phone)->where('user_type', 'driver')->first();
        if ($existing && $existing->status != 0) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký'], 422);
        }

        if (OtpService::recentlySent($phone)) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chờ 60 giây trước khi gửi lại'], 429);
        }

        OtpService::send($phone);

        return response()->json(['success' => true, 'message' => 'Mã OTP đã được gửi qua Zalo đến số ' . $phone]);
    }

    public function verifyOtpAndRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string',
            'otp'       => 'required|string|size:6',
            'name'      => 'required|string|max:255',
            'password'  => 'required|string|min:6',
            'cccd'      => 'required|string|between:9,12',
            'city_id'   => 'nullable|exists:cities,id',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if (!OtpService::verify($data['phone'], $data['otp'])) {
            return response()->json(['success' => false, 'message' => 'Mã OTP không đúng hoặc đã hết hạn'], 422);
        }

        $existingDriver = User::where('phone', $data['phone'])->where('user_type', 'driver')->first();

        if ($existingDriver) {
            if ($existingDriver->status == 1) {
                return response()->json(['success' => false, 'message' => 'Số điện thoại đã được đăng ký'], 422);
            }
            $existingDriver->delete();
        }

        if (empty($data['city_id']) && !empty($data['latitude']) && !empty($data['longitude'])) {
            $data['city_id'] = $this->findNearestCity((float) $data['latitude'], (float) $data['longitude']);
        }

        $user = User::create([
            'name'      => $data['name'],
            'phone'     => $data['phone'],
            'password'  => bcrypt($data['password']),
            'cccd'      => $data['cccd'],
            'city_id'   => $data['city_id'] ?? null,
            'status'    => 0,
            'user_type' => 'driver',
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('profiles', 'public');
            $user->update(['profile_photo_path' => $path]);
        }

        return response()->json(['success' => true, 'message' => 'Đăng ký thành công! Tài khoản đang chờ admin duyệt.']);
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

    private function findNearestCity(float $lat, float $lng): ?int
    {
        $cities = \Modules\Core\Models\City::where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'lat', 'lng']);

        if ($cities->isEmpty()) {
            return \Modules\Core\Models\City::where('is_active', true)->value('id');
        }

        $nearest   = null;
        $minDist   = PHP_FLOAT_MAX;

        foreach ($cities as $city) {
            $d = $this->haversineKm($lat, $lng, (float)$city->lat, (float)$city->lng);
            if ($d < $minDist) {
                $minDist = $d;
                $nearest = $city->id;
            }
        }

        return $nearest;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
