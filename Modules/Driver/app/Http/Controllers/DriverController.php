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

        \Log::info("[Toggle] #{$user->id} PRE: is_online={$user->is_online} online_since={$user->online_since}");

        $user->is_online   = !$user->is_online;
        $user->online_since = $user->is_online ? now() : null;

        // Ghi log phiên online/offline — dùng để tính % online trong ca ở lệnh
        // drivers:score-shift-sessions cuối mỗi ca (thay cho luật "8h/ngày" cũ,
        // và thay cho phạt -15 real-time khi tắt giữa ca — giờ chỉ phạt nếu
        // tắt hẳn không bật lại tới hết ca, đánh giá ở cuối ca).
        if ($user->is_online) {
            \Modules\Driver\Models\DriverShiftSession::create([
                'driver_id'  => $user->id,
                'started_at' => now(),
            ]);
        } else {
            \Modules\Driver\Models\DriverShiftSession::where('driver_id', $user->id)
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first()?->update(['ended_at' => now()]);
        }

        if ($user->is_online) {
            // Chỉ còn là mốc "lần cuối bật online" — không có gì cập nhật lại
            // định kỳ nữa (cron sync GPS cũ đã bỏ). Không dùng để quyết định
            // nghiệp vụ ở đâu; độ mới GPS thật đọc thẳng Firebase qua
            // DriverLocationService.
            $user->last_heartbeat_at = now();
        }

        $user->save();

        RTDBService::setDriverOnlineStatus($user->id, (bool) $user->is_online);

        \Log::info("[Toggle] #{$user->id} → online={$user->is_online} online_since={$user->online_since}");

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
        $request->user()->update([
            'fcm_token' => $data['fcm_token'],
            ...(isset($data['platform']) ? ['platform' => $data['platform']] : []),
        ]);
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
        $approved = DriverLicense::where('user_id', $request->user()->id)
            ->where('status', 'approved')->exists();
        if ($approved) {
            return response()->json([
                'success' => false,
                'message' => 'Bằng lái đã được xác minh, không thể tải lên lại.',
            ], 422);
        }

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
        $approved = DriverCccdImage::where('user_id', $request->user()->id)
            ->where('status', 'approved')->exists();
        if ($approved) {
            return response()->json([
                'success' => false,
                'message' => 'CCCD đã được xác minh, không thể tải lên lại.',
            ], 422);
        }

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
            'shift_ids.*' => 'integer|exists:shifts,id',
        ]);

        $shifts = \Modules\Core\Models\Shift::whereIn('id', $data['shift_ids'])->get();

        if ($shifts->contains(fn ($s) => (int) $s->city_id !== (int) $user->city_id)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Có ca không thuộc khu vực của bạn',
            ], 422));
        }

        // So le từng cặp — coi khung giờ là nửa-mở [start, end), 2 ca sát nhau
        // (giờ kết thúc ca này = giờ bắt đầu ca kia) KHÔNG tính là trùng.
        foreach ($shifts as $i => $a) {
            $aStart = \Carbon\Carbon::parse($a->start_time);
            $aEnd   = \Carbon\Carbon::parse($a->end_time)->lessThanOrEqualTo($aStart) ? \Carbon\Carbon::parse($a->end_time)->addDay() : \Carbon\Carbon::parse($a->end_time);

            foreach ($shifts as $j => $b) {
                if ($i >= $j) continue;
                $bStart = \Carbon\Carbon::parse($b->start_time);
                $bEnd   = \Carbon\Carbon::parse($b->end_time)->lessThanOrEqualTo($bStart) ? \Carbon\Carbon::parse($b->end_time)->addDay() : \Carbon\Carbon::parse($b->end_time);

                if ($aStart->lessThan($bEnd) && $bStart->lessThan($aEnd)) {
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

        if ($user->registeredShifts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn đã đăng ký ca rồi. Muốn đổi ca vui lòng gửi yêu cầu để admin duyệt.',
                'code'    => 'already_registered',
            ], 422);
        }

        $shiftIds = $this->validateShiftSelection($request, $user);

        $user->registeredShifts()->sync($shiftIds);

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

}
