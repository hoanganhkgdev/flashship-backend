<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\GoogleMapService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;

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
    public ?string $previewDistance = null;
    public ?string $previewStatus   = null;

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

    public function isAdmin(): bool
    {
        return auth()->user()?->user_type === 'admin';
    }

    private function userCityId(): int
    {
        $user = auth()->user();
        if ($user?->user_type === 'admin') {
            return 2;
        }
        return (int) ($user?->city_id ?? 2);
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
    }

    public function setDeliveryLocation(string $address, float $lat, float $lng): void
    {
        $this->data['delivery_address'] = $address;
        $this->deliveryLat = $lat;
        $this->deliveryLng = $lng;
        $this->previewFee      = null;
        $this->previewDistance = null;
        $this->previewStatus   = null;
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

        // Validate delivery address cho delivery / shopping
        if (!$this->isRide() && $this->serviceType !== 'topup') {
            $deliveryAddress = trim($values['delivery_address'] ?? '');
            if (!$deliveryAddress) {
                $this->resultError = 'Vui lòng nhập địa chỉ giao hàng.';
                return;
            }
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
                if (!$this->isRide() && ($deliveryLat === null || $deliveryLng === null)) {
                    $this->resultError = 'Không tìm được toạ độ địa chỉ giao. Vui lòng chọn từ gợi ý hoặc bản đồ.';
                    return;
                }
            }

            $order = Order::create([
                'code'               => '',
                'service_type'       => $this->serviceType,
                'platform'           => 'call_center',
                'city_id'            => $cityId,
                'created_by'         => auth()->id(),
                'shipping_fee'       => $shippingFee,
                'bonus_fee'          => 0,
                'is_freeship'        => false,
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

            app(OrderService::class)->dispatchNewOrder($order->id);

            $this->resultOrderCode = $order->code;
            $this->resultFee       = $shippingFee;
            $this->resultDistance  = null;

            $this->pickupLat  = null;
            $this->pickupLng  = null;
            $this->deliveryLat = null;
            $this->deliveryLng = null;

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
    }
}
