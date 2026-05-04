<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'phone'     => 'required|string|unique:users,phone',
            'password'  => 'required|string|min:6',
            'city_id'   => 'nullable|integer|exists:cities,id',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'fcm_token' => 'nullable|string',
        ]);

        // Tự tìm city gần nhất theo GPS nếu không có city_id
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
            'fcm_token' => $data['fcm_token'] ?? null,
        ]);

        $token = $user->createToken('customer_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => ['token' => $token, 'user' => $this->formatUser($user)],
        ], 201);
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

    private function formatUser(User $user): array
    {
        $user->loadMissing('city');
        return [
            'id'        => $user->id,
            'name'      => $user->name,
            'phone'     => $user->phone,
            'user_type' => $user->user_type,
            'city_id'   => $user->city_id,
            'city_name' => $user->city?->name ?? '',
            'status'    => $user->status,
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
