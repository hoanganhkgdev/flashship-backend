<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchCandidateFinder;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderService;
use Modules\Pricing\Services\PricingService;
use Modules\Shop\Services\ShopPricingService;

class CallCenterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-phone';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Tổng đài đặt đơn';
    protected static ?string $title           = '';
    protected static ?int    $navigationSort  = 10;

    protected static string $view = 'filament.pages.call-center';

    public static function canAccess(): bool
    {
        return !in_array(auth()->user()?->user_type, ['city_manager']);
    }

    // ─── State ───────────────────────────────────────────────────────────────

    public string $serviceType = 'delivery';
    public array  $data        = [];

    // Coords from map picker / autocomplete (skip geocoding on order)
    public ?float $pickupLat   = null;
    public ?float $pickupLng   = null;
    public ?float $deliveryLat = null;
    public ?float $deliveryLng = null;

    public ?int    $previewFee      = null;
    public int     $previewNightSurcharge = 0;
    public ?string $previewDistance = null;
    public ?string $previewStatus   = null;

    // Gán tay tài xế — $onlineDrivers đổ vào dropdown gán tay (TẤT CẢ tài xế
    // đang online trong thành phố, không giới hạn khoảng cách — tổng đài chủ
    // động chọn ai cũng được). $nearbyDrivers riêng cho chấm xanh trên bản đồ
    // (lọc đúng 4km đường thật). $assignedDriverId = tài xế tổng đài đang
    // chọn (null = để hệ thống tự chọn như trước).
    public array $onlineDrivers    = [];
    public array $nearbyDrivers    = [];
    public ?int  $assignedDriverId = null;

    // Freeship — khách không trả phí ship, nền tảng trả thay cho tài xế lúc
    // hoàn thành đơn (xem OrderService::completeOrder(), đã có sẵn cơ chế
    // này, chỉ là trang tổng đài trước giờ luôn hardcode false).
    public bool $isFreeship = false;

    public ?string $resultOrderCode = null;
    public ?string $resultError     = null;
    public ?int    $resultFee       = null;
    public ?string $resultDistance  = null;

    // ─── Service definitions ─────────────────────────────────────────────────

    public static function services(): array
    {
        return [
            'delivery' => ['label' => 'Lấy Hộ',     'icon' => 'heroicon-o-archive-box',      'color' => '#FF6B35'],
            'shopping' => ['label' => 'Mua Hộ',     'icon' => 'heroicon-o-shopping-bag',     'color' => '#7C4DFF'],
            'topup'    => ['label' => 'Nạp Tiền',   'icon' => 'heroicon-o-banknotes',        'color' => '#00C896'],
            'bike'     => ['label' => 'Xe Ôm',      'icon' => 'heroicon-o-bolt',             'color' => '#FFB300'],
            'motor'    => ['label' => 'Lái Xe Máy', 'icon' => 'heroicon-o-wrench-screwdriver','color' => '#1E88E5'],
            'car'      => ['label' => 'Lái Xe Hơi', 'icon' => 'heroicon-o-truck',            'color' => '#E53935'],
        ];
    }

    private function isRide(): bool
    {
        return in_array($this->serviceType, ['bike', 'motor', 'car']);
    }

    // Trước đây chỉ so === 'admin' — subadmin không có city_id cố định
    // (Modules\Core\Models\User::TENANT_UNRESTRICTED_TYPES gồm cả 2), nên
    // userCityId() bên dưới âm thầm rơi về mốc mặc định cứng (2) cho
    // subadmin, ghi đè mất khu vực họ thật sự chọn trên form ở placeOrder().
    // Coi subadmin như admin ở đây — cùng được tự chọn khu vực.
    public function isAdmin(): bool
    {
        return in_array(
            auth()->user()?->user_type,
            \Modules\Core\Models\User::TENANT_UNRESTRICTED_TYPES,
            true
        );
    }

    private function userCityId(): int
    {
        if ($this->isAdmin()) {
            // Mặc định = khu vực (tenant) đang đứng, vẫn chọn được khu vực
            // khác trên form (đặt đơn hộ cho khu vực khác qua tổng đài).
            return \Filament\Facades\Filament::getTenant()?->id ?? 2;
        }
        return (int) (auth()->user()?->city_id ?? 2);
    }

    // ─── Mount ───────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $request = request();

        if ($request->has('reorder')) {
            $service = $request->get('service', 'delivery');
            if (array_key_exists($service, self::services())) {
                $this->serviceType = $service;
            }

            $this->pickupLat   = $request->filled('pickup_lat')   ? (float) $request->get('pickup_lat')   : null;
            $this->pickupLng   = $request->filled('pickup_lng')   ? (float) $request->get('pickup_lng')   : null;
            $this->deliveryLat = $request->filled('delivery_lat') ? (float) $request->get('delivery_lat') : null;
            $this->deliveryLng = $request->filled('delivery_lng') ? (float) $request->get('delivery_lng') : null;

            $this->form->fill([
                'city_id'          => $request->get('city_id', $this->userCityId()),
                'pickup_address'   => $request->get('pickup_address', ''),
                'pickup_phone'     => $request->get('pickup_phone', ''),
                'delivery_address' => $request->get('delivery_address', ''),
                'delivery_phone'   => $request->get('delivery_phone', ''),
                'shopping_note'    => $service === 'shopping' ? $request->get('order_note', '') : '',
                'order_note'       => $service !== 'shopping' ? $request->get('order_note', '') : '',
                'cod_amount'       => null,
                'shipping_fee'     => null,
            ]);

            $this->refreshNearbyDrivers();
        } else {
            $this->form->fill($this->defaultFormData());
        }
    }

    private function defaultFormData(mixed $cityId = null): array
    {
        return [
            'city_id'          => $cityId ?? $this->userCityId(),
            'pickup_address'   => '',
            'pickup_phone'     => '',
            'delivery_address' => '',
            'delivery_phone'   => '',
            'shopping_note'    => '', // Mua hộ: nội dung đơn / gọi khách
            'order_note'       => '', // Lấy hộ: ghi chú tài xế
            'cod_amount'       => null,
            'shipping_fee'     => null,
        ];
    }

    // ─── Select service ──────────────────────────────────────────────────────

    public function selectService(string $key): void
    {
        if (!array_key_exists($key, self::services())) return;
        $cityId            = $this->data['city_id'] ?? null;
        $this->serviceType = $key;
        $this->pickupLat   = null;
        $this->pickupLng   = null;
        $this->deliveryLat = null;
        $this->deliveryLng = null;
        $this->previewFee      = null;
        $this->previewDistance = null;
        $this->previewStatus   = null;
        $this->resultOrderCode = null;
        $this->resultError     = null;
        $this->resultFee       = null;
        $this->resultDistance  = null;
        $this->onlineDrivers    = [];
        $this->nearbyDrivers    = [];
        $this->assignedDriverId = null;
        $this->isFreeship       = false;
        $this->form->fill($this->defaultFormData($cityId));
    }

    public function setPickupLocation(string $address, float $lat, float $lng): void
    {
        $this->data['pickup_address'] = $address;
        $this->pickupLat   = $lat;
        $this->pickupLng   = $lng;
        $this->previewFee      = null;
        $this->previewDistance = null;
        $this->previewStatus   = null;
        $this->assignedDriverId = null;
        $this->suggestShippingFee();
        $this->refreshNearbyDrivers();
    }

    public function setDeliveryLocation(string $address, float $lat, float $lng): void
    {
        $this->data['delivery_address'] = $address;
        $this->deliveryLat = $lat;
        $this->deliveryLng = $lng;
        $this->previewFee      = null;
        $this->previewDistance = null;
        $this->previewStatus   = null;
        $this->suggestShippingFee();
    }

    /**
     * Đủ 2 điểm (lấy + giao) → tự tính phí ship, chỉ để THAM KHẢO (hiện bên
     * bản đồ qua $previewFee), KHÔNG tự điền vào ô "Phí ship" của form. Tổng
     * đài tự gõ số thật muốn thu, tránh submit nhầm số gợi ý mà chưa đối
     * chiếu/đàm phán với khách.
     *
     * "Lấy Hộ"/"Mua Hộ" dùng đúng bảng giá SHOP (ShopPricingService, theo
     * cargo_type) vì đây là đơn lấy/giao hàng hộ giống bản chất đơn shop —
     * cố định cargo_type='food' (mức phổ biến nhất) vì trang này chưa có ô
     * chọn loại hàng riêng.
     * "Xe Ôm"/"Lái Xe Máy"/"Lái Xe Hơi" vẫn dùng bảng giá khách hàng
     * (PricingService) vì là chở người, không phải hàng hoá, ShopPricingService
     * không có hạng mục này.
     * Không áp dụng cho "topup" (phí không tính theo khoảng cách, mà theo số
     * tiền nạp — xem PricingService::topupFee()).
     */
    private function suggestShippingFee(): void
    {
        if ($this->serviceType === 'topup') return;
        if (!$this->pickupLat || !$this->pickupLng || !$this->deliveryLat || !$this->deliveryLng) return;

        $cityId = $this->data['city_id'] ?? null;

        if (in_array($this->serviceType, ['delivery', 'shopping'])) {
            $pricing = ShopPricingService::estimateFromCoords(
                'food',
                $this->pickupLat, $this->pickupLng,
                $this->deliveryLat, $this->deliveryLng,
                null,
                $cityId
            );
        } else {
            $pricing = PricingService::estimateFromCoords(
                $this->serviceType,
                $this->pickupLat, $this->pickupLng,
                $this->deliveryLat, $this->deliveryLng,
                $cityId
            );
        }

        $this->previewFee = $pricing['fee'];
        $this->previewNightSurcharge = $pricing['night_surcharge'] ?? 0;
    }

    /**
     * Tính lại 2 danh sách tài xế cùng lúc, từ chung 1 query online-driver:
     *  - $onlineDrivers: TẤT CẢ tài xế đang online trong thành phố, không lọc
     *    khoảng cách — đổ vào dropdown gán tay (tổng đài chủ động chọn ai
     *    cũng được, kể cả người đã liên hệ qua điện thoại dù hơi xa).
     *  - $nearbyDrivers: lọc còn trong bán kính 4km đường thật tính từ điểm
     *    lấy hàng (khớp DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM) — chỉ để hiện
     *    chấm xanh + đếm số trên bản đồ, không dùng cho dropdown gán tay nữa.
     *
     * Gọi trực tiếp trong setPickupLocation() (cùng 1 request với lúc set
     * toạ độ) — KHÔNG được gọi từ trong Livewire.hook('commit') phía JS, hook
     * đó chạy lại trên MỌI commit kể cả commit do chính việc gọi @this.call()
     * tạo ra, gây vòng lặp vô hạn (đã gặp bug này 1 lần — bản đồ chớp liên
     * tục do load lại Google Maps API hàng chục lần/giây).
     */
    private function refreshNearbyDrivers(): void
    {
        $cityId = $this->data['city_id'] ?? null;
        if (!$cityId) {
            $this->onlineDrivers = [];
            $this->nearbyDrivers = [];
            return;
        }

        $drivers = User::where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->where('is_online', true)
            ->get(['id', 'name', 'phone']);

        $this->onlineDrivers = $drivers
            ->map(fn (User $d) => ['id' => $d->id, 'name' => $d->name, 'phone' => $d->phone])
            ->values()
            ->all();

        if ($drivers->isEmpty() || !$this->pickupLat || !$this->pickupLng) {
            $this->nearbyDrivers = [];
            return;
        }

        $origins = app(DriverLocationService::class)->freshLocationsFor($drivers->pluck('id')->all());

        $roadDistances = GoogleMapService::roadDistanceBatchKm($origins, $this->pickupLat, $this->pickupLng);

        $this->nearbyDrivers = $drivers
            ->filter(fn (User $d) => ($roadDistances[$d->id] ?? null) !== null
                && $roadDistances[$d->id] <= DispatchCandidateFinder::MAX_ROAD_DISTANCE_KM)
            ->map(fn (User $d) => [
                'id'      => $d->id,
                'lat'     => $origins[$d->id]['lat'],
                'lng'     => $origins[$d->id]['lng'],
                'road_km' => round($roadDistances[$d->id], 2),
            ])
            ->sortBy('road_km')
            ->values()
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form->schema([])->statePath('data');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function cityName(): ?string
    {
        $cityId = $this->data['city_id'] ?? null;
        if (!$cityId) return null;
        return DB::table('cities')->where('id', $cityId)->value('name');
    }

    private function withCity(?string $address, ?string $cityName): string
    {
        if (!$address) return '';
        if (!$cityName || mb_stripos($address, $cityName) !== false) return $address;
        return $address . ', ' . $cityName;
    }

    // ─── Đặt đơn ─────────────────────────────────────────────────────────────

    public function placeOrder(): void
    {
        $values = $this->data;

        $this->resultOrderCode = null;
        $this->resultError     = null;
        $this->resultFee       = null;
        $this->resultDistance  = null;

        // Enforce city cho non-admin
        if (!$this->isAdmin()) {
            $values['city_id'] = $this->userCityId();
        }

        $cityId = $values['city_id'] ?? null;
        if (!$cityId || !DB::table('cities')->where('id', $cityId)->exists()) {
            $this->resultError = 'Khu vực không hợp lệ.';
            return;
        }

        $pickupLabel = match ($this->serviceType) {
            'shopping' => 'địa chỉ cửa hàng',
            'topup'    => 'điểm nạp',
            'bike', 'motor', 'car' => 'điểm đón',
            default    => 'điểm lấy hàng',
        };

        $pickupAddress = trim($values['pickup_address'] ?? '');
        if (!$pickupAddress) {
            $this->resultError = "Vui lòng nhập {$pickupLabel}.";
            return;
        }

        // Validate SĐT cho topup (dùng làm SĐT liên hệ khách)
        if ($this->serviceType === 'topup') {
            if (empty(trim($values['pickup_phone'] ?? ''))) {
                $this->resultError = 'Vui lòng nhập số điện thoại khách nạp tiền.';
                return;
            }
        }

        // Validate SĐT hành khách cho xe ôm / lái xe
        if ($this->isRide()) {
            if (empty(trim($values['pickup_phone'] ?? ''))) {
                $this->resultError = 'Vui lòng nhập số điện thoại hành khách.';
                return;
            }
        }

        // Validate shipping_fee & cod_amount
        $shippingFee = max(0, (int) ($values['shipping_fee'] ?? 0));
        $codAmount   = !empty($values['cod_amount']) ? max(0, (int) $values['cod_amount']) : null;

        try {
            $cityName = $this->cityName();

            $pickupLat = $this->pickupLat;
            $pickupLng = $this->pickupLng;

            if (!$pickupLat || !$pickupLng) {
                $this->resultError = "Vui lòng chọn {$pickupLabel} từ gợi ý hoặc bản đồ để có toạ độ chính xác.";
                return;
            }

            if ($this->serviceType === 'topup') {
                $deliveryLat = $pickupLat;
                $deliveryLng = $pickupLng;
            } else {
                $deliveryLat = $this->deliveryLat;
                $deliveryLng = $this->deliveryLng;
                if ($deliveryLat === null && !empty($values['delivery_address'])) {
                    $deliveryGeo = GoogleMapService::geocode($this->withCity($values['delivery_address'], $cityName));
                    $deliveryLat = $deliveryGeo['lat'] ?? null;
                    $deliveryLng = $deliveryGeo['lng'] ?? null;
                }
            }

            $order = Order::create([
                'code'               => '',
                'service_type'       => $this->serviceType,
                'platform'           => 'call_center',
                'city_id'            => $cityId,
                'created_by'         => auth()->id(),
                'shipping_fee'       => $shippingFee,
                'night_surcharge'    => $this->previewNightSurcharge,
                'bonus_fee'          => 0,
                'is_freeship'        => $this->isFreeship,
                'status'             => 'pending',
                'payment_method'     => 'cod',
                'sender_platform_id' => null,
                'pickup_place_name'  => null,
                'pickup_address'     => $values['pickup_address'],
                'pickup_lat'         => $pickupLat,
                'pickup_lng'         => $pickupLng,
                'pickup_phone'       => ($values['pickup_phone'] ?? null) ?: null,
                'delivery_address'   => $this->serviceType === 'topup'
                    ? $values['pickup_address']
                    : ($values['delivery_address'] ?? ''),
                'delivery_lat'       => $deliveryLat,
                'delivery_lng'       => $deliveryLng,
                'delivery_phone'     => $this->serviceType === 'topup' || $this->isRide()
                    ? (($values['pickup_phone'] ?? null) ?: null)
                    : (($values['delivery_phone'] ?? null) ?: null),
                'receiver_name'      => null,
                'order_note'         => $this->serviceType === 'shopping'
                    ? (trim($values['shopping_note'] ?? '') ?: null)
                    : (trim($values['order_note'] ?? '') ?: null),
                'cod_amount'         => $codAmount,
                'distance'           => null,
            ]);

            $order->refresh();

            if ($this->assignedDriverId) {
                // Gán tay — gán CỨNG luôn cho đúng người tổng đài chọn, đơn
                // vào thẳng mục "Đã nhận" của tài xế, không qua bước offer
                // chờ bấm nhận. Nếu thất bại (tài xế vừa bận/hết hạn nợ...)
                // thì HUỶ đơn luôn — KHÔNG tự ý chuyển sang tự động phát cho
                // cả thành phố, vì tổng đài đã chủ động chọn đúng người này
                // (có thể đã gọi điện xác nhận trước), để hệ thống tự chọn
                // người khác là sai ý định của tổng đài.
                $assignResult = app(DispatchService::class)->assignDriverDirectly($order, $this->assignedDriverId);
                if ($assignResult['success']) {
                    Notification::make()
                        ->title($assignResult['message'])
                        ->success()
                        ->send();
                } else {
                    DB::table('orders')->where('id', $order->id)->update([
                        'status'       => 'cancelled',
                        'cancel_reason' => 'admin',
                        'updated_at'   => now(),
                    ]);
                    RTDBService::clearOrder($order->code);
                    $this->resultError = "Không gán được cho tài xế đã chọn — {$assignResult['message']} Đơn #{$order->code} đã bị huỷ, vui lòng thử lại với tài xế khác.";
                    Notification::make()
                        ->title('Gán tay thất bại — đơn đã huỷ')
                        ->body($assignResult['message'])
                        ->danger()
                        ->send();
                    return;
                }
            } else {
                app(OrderService::class)->dispatchNewOrder($order->id);
            }

            $this->resultOrderCode = $order->code;
            $this->resultFee       = $shippingFee;
            $this->resultDistance  = null;

            $this->pickupLat  = null;
            $this->pickupLng  = null;
            $this->deliveryLat = null;
            $this->deliveryLng = null;
            $this->onlineDrivers    = [];
            $this->nearbyDrivers    = [];
            $this->assignedDriverId = null;
            $this->isFreeship       = false;

            $this->form->fill($this->defaultFormData($cityId));

            Notification::make()
                ->title("Đặt đơn thành công — #{$order->code}")
                ->success()
                ->send();

        } catch (\Throwable $e) {
            $this->resultError = $e->getMessage();
            Notification::make()
                ->title('Đặt đơn thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function clearResult(): void
    {
        $this->resultOrderCode = null;
        $this->resultError     = null;
        $this->resultFee       = null;
        $this->resultDistance  = null;
        $this->previewFee      = null;
        $this->previewDistance = null;
        $this->previewStatus   = null;
        $this->pickupLat       = null;
        $this->pickupLng       = null;
        $this->deliveryLat     = null;
        $this->deliveryLng     = null;
        $this->onlineDrivers    = [];
        $this->nearbyDrivers    = [];
        $this->assignedDriverId = null;
        $this->isFreeship       = false;
    }
}
