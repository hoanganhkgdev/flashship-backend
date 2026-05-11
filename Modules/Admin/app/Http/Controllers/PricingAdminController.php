<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLicense;
use Modules\Pricing\Models\PricingConfig;
use Modules\Pricing\Services\PricingService;

class PricingAdminController extends Controller
{
    // ── Danh sách bảng giá ────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $configs = PricingConfig::orderBy('service_type')->get();
        return response()->json(['success' => true, 'data' => $configs]);
    }

    // ── Xem chi tiết 1 dịch vụ ───────────────────────────────────────────────

    public function show(string $serviceType): JsonResponse
    {
        $config = PricingConfig::where('service_type', $serviceType)->first();
        if (!$config) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy cấu hình'], 404);
        }
        return response()->json(['success' => true, 'data' => $config]);
    }

    // ── Cập nhật cấu hình giá ─────────────────────────────────────────────────
    //
    // Body cho từng loại:
    //
    // [slab] delivery / shopping:
    //   { label, is_active,
    //     config_json: { type:"slab", slabs:[{max_km,fee},...], over_max_per_km } }
    //
    // [tiered_linear] bike:
    //   { label, is_active,
    //     config_json: { type:"tiered_linear", base_km, base_fee, per_km_fee,
    //                    higher_from_km, higher_per_km_fee } }
    //
    // [linear] motor / car:
    //   { label, is_active,
    //     config_json: { type:"linear", base_km, base_fee, per_km_fee } }
    //
    // [topup]:
    //   { label, is_active,
    //     config_json: { type:"topup", tiers:[{max_amount,fee},...],
    //                    over_max_per_unit, over_max_fee_step } }

    public function update(Request $request, string $serviceType): JsonResponse
    {
        if (!in_array($serviceType, PricingService::serviceTypes())) {
            return response()->json(['success' => false, 'message' => 'Loại dịch vụ không hợp lệ'], 422);
        }

        $data = $request->validate([
            'label'                              => 'sometimes|string|max:100',
            'is_active'                          => 'sometimes|boolean',
            'config_json'                        => 'sometimes|array',
            'config_json.type'                   => 'required_with:config_json|in:slab,linear,tiered_linear,topup',

            // Slab
            'config_json.slabs'                  => 'required_if:config_json.type,slab|array|min:1',
            'config_json.slabs.*.max_km'         => 'required_if:config_json.type,slab|numeric|min:0',
            'config_json.slabs.*.fee'            => 'required_if:config_json.type,slab|integer|min:0',
            'config_json.over_max_per_km'        => 'required_if:config_json.type,slab|integer|min:0',

            // Linear
            'config_json.base_km'                => 'required_if:config_json.type,linear,tiered_linear|numeric|min:0',
            'config_json.base_fee'               => 'required_if:config_json.type,linear,tiered_linear|integer|min:0',
            'config_json.per_km_fee'             => 'required_if:config_json.type,linear,tiered_linear|integer|min:0',

            // Tiered linear (bike)
            'config_json.higher_from_km'         => 'required_if:config_json.type,tiered_linear|numeric|min:0',
            'config_json.higher_per_km_fee'      => 'required_if:config_json.type,tiered_linear|integer|min:0',

            // Topup
            'config_json.tiers'                  => 'required_if:config_json.type,topup|array|min:1',
            'config_json.tiers.*.max_amount'     => 'required_if:config_json.type,topup|integer|min:0',
            'config_json.tiers.*.fee'            => 'required_if:config_json.type,topup|integer|min:0',
            'config_json.over_max_per_unit'      => 'required_if:config_json.type,topup|integer|min:1',
            'config_json.over_max_fee_step'      => 'required_if:config_json.type,topup|integer|min:0',
        ]);

        $config = PricingConfig::firstOrNew(['service_type' => $serviceType]);
        $config->service_type = $serviceType;

        if (isset($data['label']))       $config->label     = $data['label'];
        if (isset($data['is_active']))   $config->is_active = $data['is_active'];

        if (!empty($data['config_json'])) {
            $config->config_json = $data['config_json'];

            // Sync summary fields từ config_json để dễ đọc
            $this->syncSummaryFields($config, $data['config_json']);
        }

        $config->save();

        return response()->json(['success' => true, 'data' => $config->fresh()]);
    }

    private function syncSummaryFields(PricingConfig $config, array $cfg): void
    {
        match ($cfg['type']) {
            'slab' => (function () use ($config, $cfg) {
                $first = $cfg['slabs'][0] ?? null;
                $config->base_km    = $first ? $first['max_km'] : 0;
                $config->base_fee   = $first ? $first['fee']   : 0;
                $config->per_km_fee = $cfg['over_max_per_km'] ?? 0;
                $config->min_fee    = $first ? $first['fee']   : 0;
            })(),
            'linear', 'tiered_linear' => (function () use ($config, $cfg) {
                $config->base_km    = $cfg['base_km'];
                $config->base_fee   = $cfg['base_fee'];
                $config->per_km_fee = $cfg['per_km_fee'];
                $config->min_fee    = $cfg['base_fee'];
            })(),
            'topup' => (function () use ($config, $cfg) {
                $first = $cfg['tiers'][0] ?? null;
                $config->base_km    = 0;
                $config->base_fee   = $first ? $first['fee'] : 0;
                $config->per_km_fee = 0;
                $config->min_fee    = $first ? $first['fee'] : 0;
            })(),
            default => null,
        };
    }

    // ── Bật / tắt dịch vụ ────────────────────────────────────────────────────

    public function toggle(string $serviceType): JsonResponse
    {
        $config = PricingConfig::where('service_type', $serviceType)->first();
        if (!$config) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy cấu hình'], 404);
        }

        $config->update(['is_active' => !$config->is_active]);

        $state = $config->is_active ? 'Đã bật' : 'Đã tắt';
        return response()->json(['success' => true, 'message' => "{$state} dịch vụ {$config->label}", 'data' => $config]);
    }

    // ── Tính thử phí ─────────────────────────────────────────────────────────

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_type' => 'required|in:delivery,shopping,topup,bike,motor,car',
            'distance_km'  => 'required_unless:service_type,topup|numeric|min:0',
            'topup_amount' => 'required_if:service_type,topup|integer|min:1000',
            'city_id'      => 'nullable|integer|exists:cities,id',
        ]);

        $cityId = $data['city_id'] ?? null;

        if ($data['service_type'] === 'topup') {
            $fee = PricingService::topupFee((int) $data['topup_amount'], $cityId);
            return response()->json(['success' => true, 'data' => [
                'service_type' => 'topup',
                'fee'          => $fee,
            ]]);
        }

        $result = PricingService::estimate($data['service_type'], (float) $data['distance_km'], $cityId);
        return response()->json(['success' => true, 'data' => $result]);
    }

    // ── Bằng lái ô tô ────────────────────────────────────────────────────────

    public function carLicenses(Request $request): JsonResponse
    {
        $query = DriverLicense::with('user:id,name,phone,city_id')->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $licenses = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $licenses->items(),
            'meta'    => ['total' => $licenses->total(), 'has_more' => $licenses->hasMorePages()],
        ]);
    }

    public function approveCarLicense(Request $request, int $driverId): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $driver = User::where('id', $driverId)->where('user_type', 'driver')->first();
        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy tài xế'], 404);
        }

        $license = DriverLicense::where('user_id', $driverId)->latest()->first();
        if (!$license) {
            return response()->json(['success' => false, 'message' => 'Tài xế chưa nộp bằng lái'], 404);
        }

        $license->update(['status' => $data['status']]);

        $message = $data['status'] === 'approved'
            ? 'Đã duyệt — tài xế có thể nhận đơn Lái Hộ Ô Tô'
            : 'Đã từ chối bằng lái ô tô';

        return response()->json(['success' => true, 'message' => $message, 'data' => $license->fresh()]);
    }
}
