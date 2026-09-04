<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\OtpService;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\Bank;
use Modules\Driver\Models\DriverCccdImage;
use Modules\Driver\Models\DriverLicense;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Order\Models\Order;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Models\OrderDispatchLog;

class DriverController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['city', 'bank', 'driverLicenses', 'driverCccdImages', 'wallet']);

        $data = $user->toArray();
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

        $data = $request->validate([
            // Không bắt buộc để app cũ vẫn dùng được cơ chế toggle trong giai
            // đoạn chuyển tiếp. App mới luôn gửi boolean đích rõ ràng.
            'is_online' => 'sometimes|required|boolean',
        ]);
        $hasExplicitState = array_key_exists('is_online', $data);

        // lockForUpdate() bên trong transaction: chỉ 1 request bật/tắt online
        // của đúng tài xế này được xử lý tại 1 thời điểm — tránh 2 request gần
        // như đồng thời (double-tap, app gọi trùng do mạng chậm...) cùng đọc
        // is_online=false rồi cùng tạo phiên DriverShiftSession mới thay vì
        // đóng phiên cũ, sinh 2 phiên chồng lấp. Phiên chồng lấp khiến
        // ScoreShiftSessionsCommand cộng trùng thời gian online, có thể ra
        // 100% online cho cả ca dù tài xế thật sự có lúc offline (thấy rõ ở
        // tài khoản test khu Rạch Giá — bật/tắt online liên tục lúc test).
        $result = DB::transaction(function () use ($user, $data, $hasExplicitState) {
            $locked = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $targetOnline = $hasExplicitState
                ? (bool) $data['is_online']
                : !(bool) $locked->is_online;

            \Log::info("[Toggle] #{$locked->id} PRE: is_online={$locked->is_online} online_since={$locked->online_since}");

            // Idempotent: retry cùng một request không được đảo trạng thái
            // lần thứ hai hoặc tạo/đóng thêm phiên chấm công.
            if ((bool) $locked->is_online === $targetOnline && $targetOnline) {
                return ['user' => $locked];
            }

            if ($targetOnline) {
                if (\Modules\Driver\Models\DriverLeaveRequest::where('driver_id', $locked->id)
                    ->whereDate('leave_date', today())->exists()) {
                    return ['error' => ['message' => 'Bạn đang được ghi nhận nghỉ phép hôm nay nên không thể bật online.', 'status' => 403]];
                }
                $approved = DriverCccdImage::where('user_id', $locked->id)
                    ->where('status', 'approved')
                    ->exists();
                if (!$approved) {
                    return ['error' => [
                        'message' => 'Bạn cần tải lên và được duyệt CCCD trước khi có thể hoạt động.',
                        'status' => 403,
                    ]];
                }

                $overdueDebt = $locked->debts()->where('status', 'overdue')->first();
                if ($overdueDebt) {
                    $remaining = $overdueDebt->amount_due - $overdueDebt->amount_paid;
                    return ['error' => [
                        'message' => 'Bạn có công nợ quá hạn ' . number_format($remaining, 0, ',', '.') . '₫. Vui lòng thanh toán trước khi hoạt động.',
                        'code' => 'debt_overdue',
                        'status' => 403,
                    ]];
                }
            }

            $locked->is_online    = $targetOnline;
            $locked->online_since = $locked->is_online ? now() : null;

            // Ghi log phiên online/offline — dùng để tính % online trong ca ở
            // lệnh drivers:score-shift-sessions cuối mỗi ca (thay cho luật
            // "8h/ngày" cũ, và thay cho phạt -15 real-time khi tắt giữa ca —
            // giờ chỉ phạt nếu tắt hẳn không bật lại tới hết ca, đánh giá ở
            // cuối ca).
            $offers = collect();
            if ($locked->is_online) {
                \Modules\Driver\Models\DriverShiftSession::create([
                    'driver_id'  => $locked->id,
                    'started_at' => now(),
                ]);
                // Chỉ còn là mốc "lần cuối bật online" — không có gì cập nhật
                // lại định kỳ nữa (cron sync GPS cũ đã bỏ). Không dùng để
                // quyết định nghiệp vụ ở đâu; độ mới GPS thật đọc thẳng
                // Firebase qua DriverLocationService.
                $locked->last_heartbeat_at = now();
            } else {
                $offers = Order::where('status', 'pending')
                    ->where('dispatching_to_driver_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                foreach ($offers as $offer) {
                    $offer->update(['dispatching_to_driver_id' => null, 'offer_viewed_at' => null]);
                    OrderDispatchLog::where('order_id', $offer->id)
                        ->where('driver_id', $locked->id)
                        ->where('result', 'pending')
                        ->update(['result' => 'expired', 'responded_at' => now()]);
                }
                // Đóng TẤT CẢ phiên đang mở, không chỉ phiên gần nhất — nếu
                // có sót lại phiên chồng lấp nào (bug cũ, hoặc lỗi khác trong
                // tương lai) mà chỉ đóng đúng 1 phiên (->first()) thì phiên
                // còn lại sẽ mở mãi mãi, ăn "online" vào mọi ca sau này vô
                // thời hạn — nặng hơn cả lỗi chồng lấp ban đầu.
                \Modules\Driver\Models\DriverShiftSession::where('driver_id', $locked->id)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now()]);
                $gpsSessions = \Modules\Driver\Models\DriverGpsEligibleSession::where('driver_id', $locked->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->get();
                foreach ($gpsSessions as $gpsSession) {
                    $endedAt = $gpsSession->last_gps_at->copy()
                        ->addSeconds(\Modules\Driver\Services\DriverLocationService::POS_MAX_AGE_SECS)
                        ->min(now());
                    $gpsSession->update(['ended_at' => $endedAt]);
                }
            }

            $locked->save();

            \Log::info("[Toggle] #{$locked->id} → online={$locked->is_online} online_since={$locked->online_since}");

            return ['user' => $locked, 'offers' => $offers];
        });

        if (isset($result['error'])) {
            $error = $result['error'];
            return response()->json([
                'success' => false,
                'message' => $error['message'],
                ...(isset($error['code']) ? ['code' => $error['code']] : []),
            ], $error['status']);
        }

        /** @var User $user */
        $user = $result['user'];

        RTDBService::setDriverOnlineStatus($user->id, (bool) $user->is_online);
        foreach ($result['offers'] ?? [] as $offer) {
            RTDBService::clearDriverOffer($user->id, $offer->id);
            try {
                \Illuminate\Support\Facades\Redis::eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    1,
                    "dispatch:lock:driver:{$user->id}",
                    (string) $offer->id,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Toggle] Không xóa được dispatch lock khi offline', [
                    'driver_id' => $user->id, 'order_id' => $offer->id, 'message' => $e->getMessage(),
                ]);
            }
            dispatch(function () use ($offer) {
                app(\Modules\Order\Services\DispatchService::class)->sendToNextDriver($offer->fresh());
            })->afterResponse();
        }

        return response()->json([
            'success'      => true,
            'message'      => $user->is_online ? 'Bạn đang online' : 'Bạn đang offline',
            'is_online'    => $user->is_online,
            'online_since' => $user->online_since?->toIso8601String(),
        ]);
    }

    /**
     * Endpoint này KHÔNG còn được dùng làm nguồn toạ độ chính thức — app gửi
     * cố định mỗi 30s bằng _lastLat/_lastLng lưu trong bộ nhớ, không đọc GPS
     * mới, nên nếu stream GPS phía app bị hệ điều hành đóng băng (Android
     * Doze/tiết kiệm pin khi khoá màn hình) thì endpoint này cứ gửi lặp lại
     * đúng 1 toạ độ cũ mỗi 30s mãi mãi. Trước đây điều này ghi đè lên cả
     * users.latitude/longitude lẫn Firebase RTDB (kèm bump "updated_at"),
     * khiến dữ liệu cũ trông "vừa mới cập nhật" và qua mặt luôn bộ lọc
     * khoảng cách khi phát đơn (xem vụ đơn #12117 — tài xế cách thật ~5.8km
     * nhưng hệ thống tin nhầm 3.27km, lọt qua trần 4km).
     *
     * Nguồn toạ độ chính thức giờ là Firebase RTDB — ghi trực tiếp từ
     * LocationService (stream GPS thật, chỉ bắn khi di chuyển ≥10m), được
     * dispatch/map đọc thẳng tại thời điểm cần (không còn đồng bộ qua MySQL).
     * Endpoint này chỉ còn giữ lại để không phá app cũ đang gọi, và lưu log
     * đối chiếu.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'bearing'   => 'nullable|numeric',
        ]);

        $driver = $request->user();

        DriverLocationLog::create([
            'driver_id' => $driver->id,
            'latitude'  => $data['latitude'],
            'longitude' => $data['longitude'],
            'bearing'   => $data['bearing'] ?? $driver->bearing,
            'source'    => 'api',
        ]);

        return response()->json(['success' => true]);
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => 'required|string',
            'platform'  => 'nullable|in:ios,android',
        ]);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            // Một token thiết bị chỉ thuộc một tài khoản; nếu không logout
            // tài khoản A rồi login B trên cùng máy sẽ nhận thông báo chéo.
            User::where('fcm_token', $data['fcm_token'])
                ->where('id', '!=', $user->id)->update(['fcm_token' => null]);
            $user->update([
                'fcm_token' => $data['fcm_token'],
                ...(isset($data['platform']) ? ['platform' => $data['platform']] : []),
            ]);
        });
        return response()->json(['success' => true, 'message' => 'FCM Token đã cập nhật']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'nullable|string|max:255',
            'email'         => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'avatar'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'vehicle_type'  => 'nullable|in:motorbike,car',
            'license_plate' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        if (isset($data['vehicle_type']) || isset($data['license_plate'])) {
            if ($user->is_online || Order::where('delivery_man_id', $user->id)
                ->whereIn('status', ['assigned', 'processing'])->exists()) {
                return response()->json(['success' => false, 'message' => 'Hãy offline và hoàn thành các đơn đang giao trước khi đổi thông tin xe.'], 422);
            }
            $targetVehicle = $data['vehicle_type'] ?? $user->vehicle_type;
            $targetPlate = trim($data['license_plate'] ?? $user->license_plate ?? '');
            if ($targetVehicle === 'car' && (!$user->has_car_license || $targetPlate === '')) {
                return response()->json(['success' => false, 'message' => 'Xe ô tô cần bằng lái đã duyệt và biển số hợp lệ.'], 422);
            }
            if (isset($data['license_plate'])) $data['license_plate'] = mb_strtoupper($targetPlate);
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
            $data['profile_photo_path'] = $request->file('avatar')->store('profile-photos', 'public');
            $data['avatar_updated_at']  = now();
        }
        unset($data['avatar']);

        $newAvatar = $data['profile_photo_path'] ?? null;
        $oldAvatar = null;
        try {
            DB::transaction(function () use ($user, &$data, &$oldAvatar) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (isset($data['name']) && $data['name'] !== $locked->name) {
                    if ($locked->name_updated_at) abort(response()->json(['success' => false, 'message' => 'Tên chỉ được thay đổi một lần.'], 403));
                    $data['name_updated_at'] = now();
                } else {
                    unset($data['name']);
                }
                if (isset($data['profile_photo_path'])) $oldAvatar = $locked->profile_photo_path;
                $locked->update(array_filter($data, fn($v) => $v !== null));
            });
        } catch (\Throwable $e) {
            if ($newAvatar) Storage::disk('public')->delete($newAvatar);
            throw $e;
        }
        if ($oldAvatar && $oldAvatar !== $newAvatar) Storage::disk('public')->delete($oldAvatar);
        $user->refresh();

        $userData = $user->toArray();
        if ($user->profile_photo_path) {
            $userData['profile_photo_url'] = url('storage/' . $user->profile_photo_path);
        }

        return response()->json(['success' => true, 'message' => 'Cập nhật thành công', 'data' => ['user' => $userData]]);
    }

    // Type riêng cho OTP đổi mật khẩu — tách khỏi mặc định 'register' để
    // không bị luồng đăng ký khác (gửi OTP cùng type cho cùng SĐT) ghi đè/
    // vô hiệu hoá lẫn nhau (OtpService::send() set used=true toàn bộ OTP cũ
    // cùng phone+type mỗi lần gửi mới).
    private const OTP_TYPE_CHANGE_PASSWORD = 'change_password';

    public function sendChangePasswordOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!OtpService::sendThrottled($user->phone, self::OTP_TYPE_CHANGE_PASSWORD)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng chờ 60 giây trước khi gửi lại',
            ], 429);
        }

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

        // Giới hạn thử sai OTP — endpoint yêu cầu token hợp lệ nên rủi ro
        // thấp, nhưng nếu token cũ bị lộ (thiết bị chưa logout) thì không
        // giới hạn sẽ cho brute-force hết 1 triệu mã trong 10 phút hiệu lực.
        $rateLimitKey = 'driver-change-password-otp:' . $user->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Nhập sai OTP quá nhiều lần, vui lòng gửi lại mã mới sau ít phút',
            ], 429);
        }

        if (!OtpService::verify($user->phone, $data['otp'], self::OTP_TYPE_CHANGE_PASSWORD)) {
            RateLimiter::hit($rateLimitKey, 600);
            return response()->json(['success' => false, 'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn'], 422);
        }

        RateLimiter::clear($rateLimitKey);
        DB::transaction(function () use ($user, $data, $request) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update(['password' => Hash::make($data['new_password'])]);

            // Giữ phiên đang chủ động đổi mật khẩu, thu hồi mọi token còn
            // lại (token cũ/thiết bị khác) để mật khẩu mới có hiệu lực bảo
            // mật ngay, không đợi các phiên đó tự hết hạn.
            $currentTokenId = $request->user()->currentAccessToken()?->id;
            $tokens = $locked->tokens();
            if ($currentTokenId) $tokens->where('id', '!=', $currentTokenId);
            $tokens->delete();
        });
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
            // Chỉ tính offer mà tài xế đã thực sự tương tác: accept, decline,
            // hoặc đã mở nhưng để hết giờ. Offer chưa từng mở có thể do FCM,
            // mạng/GPS chết và không được dùng làm mẫu số tỷ lệ nhận.
            ->where(fn ($q) => $q->whereIn('result', ['accepted', 'declined'])
                ->orWhereNotNull('viewed_at'))
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
            'bank_code'      => ['required', 'string', 'max:50', Rule::exists('bank_lists', 'code')->where('is_active', true)],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'account_name'   => 'required|string|max:255',
        ]);

        $user = $request->user();
        $bankName = DB::table('bank_lists')->where('code', $data['bank_code'])->value('name');
        DB::transaction(function () use ($user, $data, $bankName) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Bank::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'user_id' => $user->id,
                    'bank_code' => $data['bank_code'],
                    'bank_name' => $bankName,
                    'account_number' => $data['account_number'],
                    'account_name' => mb_strtoupper(trim($data['account_name'])),
                ],
            );
        });

        return response()->json(['success' => true, 'message' => 'Cập nhật tài khoản ngân hàng thành công']);
    }

    public function uploadLicense(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();
        $path = $request->file('image')->store('driver-licenses', 'public');
        $oldPath = null;

        try {
            DB::transaction(function () use ($user, $path, &$oldPath) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (DriverLicense::where('user_id', $user->id)
                    ->where('status', DriverLicense::STATUS_APPROVED)->lockForUpdate()->exists()) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Bằng lái đã được xác minh, không thể tải lên lại.',
                    ], 422));
                }
                $document = DriverLicense::where('user_id', $user->id)
                    ->latest('id')->lockForUpdate()->first();

                if ($document) {
                    $oldPath = $document->image_path;
                    $document->update([
                        'image_path' => $path,
                        'status' => DriverLicense::STATUS_PENDING,
                        'rejection_reason' => null,
                    ]);
                    DriverLicense::where('user_id', $user->id)
                        ->where('id', '!=', $document->id)
                        ->where('status', DriverLicense::STATUS_PENDING)
                        ->update(['status' => DriverLicense::STATUS_REJECTED, 'rejection_reason' => 'Đã được thay thế bằng hồ sơ mới']);
                } else {
                    DriverLicense::create(['user_id' => $user->id, 'image_path' => $path, 'status' => DriverLicense::STATUS_PENDING]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        if ($oldPath && $oldPath !== $path) Storage::disk('public')->delete($oldPath);

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

        $user = $request->user();
        $path = $request->file('image')->store('driver-cccd', 'public');
        $oldPath = null;

        try {
            DB::transaction(function () use ($user, $path, &$oldPath) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (DriverCccdImage::where('user_id', $user->id)
                    ->where('status', DriverCccdImage::STATUS_APPROVED)->lockForUpdate()->exists()) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'CCCD đã được xác minh, không thể tải lên lại.',
                    ], 422));
                }
                $document = DriverCccdImage::where('user_id', $user->id)
                    ->latest('id')->lockForUpdate()->first();

                if ($document) {
                    $oldPath = $document->image_path;
                    $document->update([
                        'image_path' => $path,
                        'status' => DriverCccdImage::STATUS_PENDING,
                        'rejection_reason' => null,
                    ]);
                    DriverCccdImage::where('user_id', $user->id)
                        ->where('id', '!=', $document->id)
                        ->where('status', DriverCccdImage::STATUS_PENDING)
                        ->update(['status' => DriverCccdImage::STATUS_REJECTED, 'rejection_reason' => 'Đã được thay thế bằng hồ sơ mới']);
                } else {
                    DriverCccdImage::create(['user_id' => $user->id, 'image_path' => $path, 'status' => DriverCccdImage::STATUS_PENDING]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        if ($oldPath && $oldPath !== $path) Storage::disk('public')->delete($oldPath);

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
            'success'           => true,
            'data'              => $shifts,
            'current_shift_ids' => $user->registeredShifts()->pluck('shifts.id'),
        ]);
    }

    /** Validate mảng shift_id: ít nhất 1 ca, đúng khu vực tài xế, không trùng giờ nhau. */
    private function validateShiftSelection(Request $request, User $user): array
    {
        $data = $request->validate([
            'shift_ids'   => 'required|array|min:1',
            'shift_ids.*' => 'integer|distinct|exists:shifts,id',
        ]);

        $shifts = \Modules\Core\Models\Shift::whereIn('id', $data['shift_ids'])->get();

        if ($shifts->contains(fn ($s) => (int) $s->city_id !== (int) $user->city_id)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Có ca không thuộc khu vực của bạn',
            ], 422));
        }

        // Biểu diễn ca trên vòng 24h thành 1-2 đoạn phút. Ca qua đêm
        // 22:00-06:00 thành [22:00,24:00) + [00:00,06:00), nhờ vậy phát hiện
        // đúng nó trùng ca 05:00-10:00. Hai ca sát mép vẫn không coi là trùng.
        $segments = function ($shift): array {
            [$startHour, $startMinute] = array_map('intval', explode(':', $shift->start_time));
            [$endHour, $endMinute] = array_map('intval', explode(':', $shift->end_time));
            $start = $startHour * 60 + $startMinute;
            $end = $endHour * 60 + $endMinute;
            if ($end > $start) return [[$start, $end]];
            return [[$start, 1440], [0, $end]];
        };

        foreach ($shifts as $i => $a) {
            foreach ($shifts as $j => $b) {
                if ($i >= $j) continue;

                $overlaps = collect($segments($a))->contains(function ($aPart) use ($segments, $b) {
                    return collect($segments($b))->contains(
                        fn ($bPart) => $aPart[0] < $bPart[1] && $bPart[0] < $aPart[1]
                    );
                });
                if ($overlaps) {
                    abort(response()->json([
                        'success' => false,
                        'message' => "Ca \"{$a->name}\" và \"{$b->name}\" bị trùng giờ nhau",
                    ], 422));
                }
            }
        }

        return $data['shift_ids'];
    }

    public function selectShift(Request $request): JsonResponse
    {
        $user = $request->user();
        $shiftIds = $this->validateShiftSelection($request, $user);

        $registered = \DB::transaction(function () use ($user, $shiftIds) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            if ($user->registeredShifts()->exists()) return false;
            $user->registeredShifts()->sync($shiftIds);
            return true;
        });

        if (!$registered) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn đã đăng ký ca rồi. Muốn đổi ca vui lòng gửi yêu cầu để admin duyệt.',
                'code'    => 'already_registered',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã đăng ký ca làm việc',
        ]);
    }

    public function submitShiftChangeRequest(Request $request): JsonResponse
    {
        $user = $request->user();

        // Khoá theo tài xế trong lúc kiểm tra + tạo — nếu không, bấm gửi 2
        // lần liên tiếp nhanh sẽ khiến cả 2 request cùng đọc thấy "chưa có
        // gì pending" (request sau chưa kịp thấy request trước vừa tạo),
        // tạo ra 2 yêu cầu đổi ca trùng nhau cùng chờ duyệt.
        $result = \DB::transaction(function () use ($request, $user) {
            User::where('id', $user->id)->lockForUpdate()->first();

            $pending = \Modules\Driver\Models\DriverShiftChangeRequest::where('driver_id', $user->id)
                ->where('status', 'pending')->exists();
            if ($pending) {
                return ['status' => 422, 'body' => [
                    'success' => false,
                    'message' => 'Bạn đang có 1 yêu cầu đổi ca chờ duyệt, vui lòng đợi xử lý xong.',
                ]];
            }

            $shiftIds = $this->validateShiftSelection($request, $user);

            \Modules\Driver\Models\DriverShiftChangeRequest::create([
                'driver_id' => $user->id,
                'shift_ids' => $shiftIds,
                'status'    => 'pending',
            ]);

            return ['status' => 200, 'body' => [
                'success' => true,
                'message' => 'Đã gửi yêu cầu đổi ca, chờ admin duyệt',
            ]];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function shiftChangeRequestStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = \Modules\Driver\Models\DriverShiftChangeRequest::where('driver_id', $user->id)
            ->latest()->first();

        return response()->json([
            'success' => true,
            'data'    => $latest,
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

    public function requestDeleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $result = DB::transaction(function () use ($user) {
            $driver = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($driver->delete_requested_at) return ['error' => 'Bạn đã gửi yêu cầu xóa tài khoản'];

            if (Order::where('delivery_man_id', $driver->id)
                ->whereIn('status', ['assigned', 'processing'])->lockForUpdate()->exists()) {
                return ['error' => 'Bạn đang có đơn chưa hoàn thành'];
            }

            $debt = \Modules\Driver\Models\DriverDebt::where('driver_id', $driver->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->whereRaw('amount_due > amount_paid')->lockForUpdate()->exists();
            if ($debt) return ['error' => 'Bạn cần thanh toán hết công nợ trước khi yêu cầu xóa tài khoản'];

            $wallet = \Modules\Driver\Models\DriverWallet::where('driver_id', $driver->id)
                ->lockForUpdate()->first();
            if ($wallet && (float) $wallet->balance != 0.0) {
                return ['error' => 'Số dư ví phải bằng 0 trước khi yêu cầu xóa tài khoản'];
            }

            if (\Modules\Driver\Models\WithdrawRequest::where('driver_id', $driver->id)
                ->where('status', 'pending')->lockForUpdate()->exists()) {
                return ['error' => 'Bạn đang có yêu cầu rút tiền chờ xử lý'];
            }

            $offers = Order::where('dispatching_to_driver_id', $driver->id)
                ->where('status', 'pending')->lockForUpdate()->get();
            foreach ($offers as $offer) {
                $offer->update(['dispatching_to_driver_id' => null, 'offer_viewed_at' => null]);
                OrderDispatchLog::where('order_id', $offer->id)
                    ->where('driver_id', $driver->id)->where('result', 'pending')
                    ->update(['result' => 'expired', 'responded_at' => now()]);
            }

            \Modules\Driver\Models\DriverShiftSession::where('driver_id', $driver->id)
                ->whereNull('ended_at')->lockForUpdate()->update(['ended_at' => now()]);
            $gpsSessions = \Modules\Driver\Models\DriverGpsEligibleSession::where('driver_id', $driver->id)
                ->whereNull('ended_at')->lockForUpdate()->get();
            foreach ($gpsSessions as $session) {
                $endedAt = $session->last_gps_at->copy()
                    ->addSeconds(\Modules\Driver\Services\DriverLocationService::POS_MAX_AGE_SECS)
                    ->min(now());
                $session->update(['ended_at' => $endedAt]);
            }

            $driver->update([
                'delete_requested_at' => now(),
                'is_online' => false,
                'online_since' => null,
                'fcm_token' => null,
            ]);
            return ['offers' => $offers];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        RTDBService::removeDriverLocation($user->id);
        try {
            \Illuminate\Support\Facades\Redis::del("dispatch:lock:driver:{$user->id}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[DeleteAccount] Không xóa được Redis lock', [
                'driver_id' => $user->id, 'message' => $e->getMessage(),
            ]);
        }
        foreach ($result['offers'] as $offer) {
            RTDBService::clearDriverOffer($user->id, $offer->id);
            app(\Modules\Order\Services\DispatchService::class)->sendToNextDriver($offer->fresh());
        }

        return response()->json(['success' => true, 'message' => 'Đã gửi yêu cầu xóa tài khoản. Bạn có thể hủy yêu cầu trong thời gian chờ xử lý.']);
    }

    public function cancelDeleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $cancelled = DB::transaction(function () use ($user) {
            $driver = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (!$driver->delete_requested_at) return false;
            $driver->update(['delete_requested_at' => null, 'is_online' => false, 'online_since' => null]);
            return true;
        });
        if (!$cancelled) return response()->json(['success' => false, 'message' => 'Không có yêu cầu xóa tài khoản'], 422);
        return response()->json(['success' => true, 'message' => 'Đã hủy yêu cầu xóa tài khoản']);
    }

}
