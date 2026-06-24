<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\GoogleMapService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Pricing\Services\PricingService;

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

    private function hasCargoFields(): bool
    {
        return in_array($this->serviceType, ['delivery', 'shopping']);
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
                'city_id'          => $request->get('city_id', 2),
                'pickup_address'   => $request->get('pickup_address', ''),
                'pickup_phone'     => $request->get('pickup_phone', ''),
                'delivery_address' => $request->get('delivery_address', ''),
                'delivery_phone'   => $request->get('delivery_phone', ''),
                'shopping_note'    => '',
                'order_note'       => $request->get('order_note', ''),
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
            'city_id'          => $cityId ?? 2,
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

    // ─── Form ────────────────────────────────────────────────────────────────

    public function form(Form $form): Form
    {
        $cities = DB::table('cities')->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn($c) => [$c->id => $c->name])->toArray();

        return $form->schema([

            // Khu vực
            Select::make('city_id')
                ->label('Khu vực')
                ->options($cities)
                ->placeholder('Chọn khu vực...')
                ->required()
                ->searchable()
                ->live(),

            // ── MUA HỘ: Nội dung đơn / Gọi khách ───────────────────────────
            Section::make('Đơn hàng cần mua')
                ->icon('heroicon-o-shopping-bag')
                ->hidden(fn() => $this->serviceType !== 'shopping')
                ->schema([
                    Textarea::make('shopping_note')
                        ->label('Nội dung / Gọi khách')
                        ->placeholder('VD: 1 hộp cơm gà xối mỡ lấy đùi chiên nước mắm ở Huỳnh Trân 3/2...')
                        ->rows(3)
                        ->required(fn() => $this->serviceType === 'shopping')
                        ->columnSpanFull(),
                    TextInput::make('pickup_address')
                        ->label('Địa chỉ cửa hàng')
                        ->placeholder('Tên quán hoặc địa chỉ để tính phí...')
                        ->extraInputAttributes(['id' => 'cc-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openMapPicker')),
                        ])
                        ->columnSpanFull(),
                ]),

            // ── LẤY HỘ: Điểm lấy đơn ───────────────────────────────────────
            Section::make('Điểm lấy đơn')
                ->icon('heroicon-o-building-storefront')
                ->hidden(fn() => $this->serviceType !== 'delivery')
                ->schema([
                    TextInput::make('pickup_address')
                        ->label('Địa chỉ lấy')
                        ->placeholder('Nhập địa chỉ hoặc chọn trên bản đồ...')
                        ->required(fn() => $this->serviceType === 'delivery')
                        ->extraInputAttributes(['id' => 'cc-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openMapPicker')),
                        ]),
                    TextInput::make('pickup_phone')
                        ->label('SĐT lấy hàng')
                        ->tel()
                        ->placeholder('0xxx xxx xxx'),
                ])->columns(2),

            // ── XE ÔM / LÁI XE (bike, motor, car) ──────────────────────────
            Section::make('Điểm rước')
                ->icon('heroicon-o-map-pin')
                ->hidden(fn() => !$this->isRide())
                ->schema([
                    TextInput::make('pickup_address')
                        ->label('Địa chỉ rước')
                        ->placeholder('Nhập địa chỉ hoặc chọn trên bản đồ...')
                        ->required(fn() => $this->isRide())
                        ->extraInputAttributes(['id' => 'cc-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openMapPicker')),
                        ]),
                    TextInput::make('pickup_phone')
                        ->label('SĐT khách')
                        ->tel()
                        ->placeholder('0xxx xxx xxx')
                        ->required(fn() => $this->isRide()),
                ])->columns(2),

            Section::make('Điểm đến')
                ->icon('heroicon-o-flag')
                ->hidden(fn() => !$this->isRide())
                ->schema([
                    TextInput::make('delivery_address')
                        ->label('Địa chỉ đến')
                        ->placeholder('Nhập địa chỉ hoặc chọn trên bản đồ...')
                        ->required(fn() => $this->isRide())
                        ->extraInputAttributes(['id' => 'cc-delivery-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openDeliveryMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openDeliveryMapPicker')),
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('Tiền xe & ghi chú')
                ->icon('heroicon-o-banknotes')
                ->hidden(fn() => !$this->isRide())
                ->schema([
                    TextInput::make('shipping_fee')
                        ->label('Tiền xe')
                        ->numeric()
                        ->placeholder('Bấm Tính phí hoặc nhập thủ công')
                        ->suffix('đ'),
                    Textarea::make('order_note')
                        ->label('Ghi chú')
                        ->placeholder('Ghi chú cho tài xế...')
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),

            // ── NẠP TIỀN ────────────────────────────────────────────────────
            Section::make('Thông tin nạp tiền')
                ->icon('heroicon-o-credit-card')
                ->hidden(fn() => $this->serviceType !== 'topup')
                ->schema([
                    TextInput::make('cod_amount')
                        ->label('Số tiền nạp')
                        ->numeric()
                        ->placeholder('VD: 2000000')
                        ->suffix('đ')
                        ->required(fn() => $this->serviceType === 'topup'),
                    TextInput::make('shipping_fee')
                        ->label('Phí dịch vụ')
                        ->numeric()
                        ->placeholder('Nhập thủ công')
                        ->suffix('đ'),
                    TextInput::make('pickup_phone')
                        ->label('SĐT khách')
                        ->tel()
                        ->placeholder('0xxx xxx xxx')
                        ->required(fn() => $this->serviceType === 'topup'),
                    TextInput::make('pickup_address')
                        ->label('Điểm nạp')
                        ->placeholder('Địa chỉ khách hoặc chọn trên bản đồ...')
                        ->required(fn() => $this->serviceType === 'topup')
                        ->extraInputAttributes(['id' => 'cc-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openMapPicker')),
                        ])
                        ->columnSpanFull(),
                    Textarea::make('order_note')
                        ->label('Nội dung nạp')
                        ->placeholder('VD: Nạp ngân hàng Vietcombank, nạp điện thoại Viettel...')
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),

            // ── CHUNG: Điểm giao đơn (delivery + shopping) ──────────────────
            Section::make('Điểm giao đơn')
                ->icon('heroicon-o-home')
                ->hidden(fn() => !in_array($this->serviceType, ['delivery', 'shopping']))
                ->schema([
                    TextInput::make('delivery_address')
                        ->label('Địa chỉ giao')
                        ->placeholder('Số nhà, đường, phường, quận... (có thể để trống)')
                        ->extraInputAttributes(['id' => 'cc-delivery-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Action::make('openDeliveryMapPicker')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn () => $this->dispatch('openDeliveryMapPicker')),
                        ]),
                    TextInput::make('delivery_phone')
                        ->label('SĐT khách')
                        ->tel()
                        ->placeholder('0xxx xxx xxx'),
                ])->columns(2),

            // ── CHUNG: Cước phí ──────────────────────────────────────────────
            Section::make('Cước phí')
                ->icon('heroicon-o-banknotes')
                ->hidden(fn() => $this->serviceType === 'topup' || $this->isRide())
                ->schema([
                    TextInput::make('shipping_fee')
                        ->label('Phí ship')
                        ->numeric()
                        ->placeholder('Bấm Tính phí hoặc nhập thủ công')
                        ->suffix('đ'),
                    TextInput::make('cod_amount')
                        ->label(fn() => $this->serviceType === 'shopping' ? 'Tiền hàng thu hộ' : 'Thu hộ COD')
                        ->numeric()
                        ->placeholder('0 nếu không có')
                        ->suffix('đ'),
                    Textarea::make('order_note')
                        ->label('Ghi chú thêm')
                        ->placeholder('Ghi chú cho tài xế...')
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),

        ])->statePath('data');
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

    // ─── Tính phí ────────────────────────────────────────────────────────────

    public function calculateFee(): void
    {
        $pickup   = trim($this->data['pickup_address']   ?? '');
        // Topup: chỉ 1 điểm, dùng pickup làm delivery
        $delivery = $this->serviceType === 'topup'
            ? $pickup
            : trim($this->data['delivery_address'] ?? '');
        $cityId   = $this->data['city_id'] ?? null;

        if (!$pickup || !$delivery) {
            $this->previewStatus = 'warning:Vui lòng điền đủ địa chỉ.';
            return;
        }

        $this->previewStatus   = 'loading:Đang tính...';
        $this->previewFee      = null;
        $this->previewDistance = null;

        $cityName  = $this->cityName();

        // Dùng stored coords từ map picker/autocomplete nếu có, không thì geocode
        $pickupLat  = $this->pickupLat;
        $pickupLng  = $this->pickupLng;
        if ($pickupLat === null) {
            $pickupGeo = GoogleMapService::geocode($this->withCity($pickup, $cityName));
            $pickupLat = $pickupGeo['lat'] ?? null;
            $pickupLng = $pickupGeo['lng'] ?? null;
        }

        $deliveryLat = $this->deliveryLat;
        $deliveryLng = $this->deliveryLng;
        if ($deliveryLat === null) {
            $deliveryGeo = GoogleMapService::geocode($this->withCity($delivery, $cityName));
            $deliveryLat = $deliveryGeo['lat'] ?? null;
            $deliveryLng = $deliveryGeo['lng'] ?? null;
        }

        if ($this->serviceType === 'topup') {
            $amount = (int) ($this->data['cod_amount'] ?? 0);
            $fee    = PricingService::topupFee($amount, $cityId);
            $this->previewFee            = $fee;
            $this->previewDistance       = null;
            $this->previewStatus         = 'success:Đã tính xong.';
            $this->data['shipping_fee']  = $fee;
        } elseif ($pickupLat && $deliveryLat) {
            $pricing = PricingService::estimateFromCoords(
                $this->serviceType,
                $pickupLat,  $pickupLng,
                $deliveryLat, $deliveryLng,
                $cityId
            );
            $this->previewFee      = $pricing['fee'];
            $this->previewDistance = number_format($pricing['distance_km'], 1) . ' km';
            $this->previewStatus   = 'success:Đã tính xong.';
            $this->data['shipping_fee'] = $pricing['fee'];
        } else {
            $failed = !$pickupLat ? 'địa chỉ lấy hàng' : 'địa chỉ giao hàng';
            $this->previewStatus = "error:Không geocode được {$failed}.";
        }
    }

    // ─── Đặt đơn ─────────────────────────────────────────────────────────────

    public function placeOrder(): void
    {
        $values = $this->form->getState();

        $this->resultOrderCode = null;
        $this->resultError     = null;
        $this->resultFee       = null;
        $this->resultDistance  = null;

        $cityId = $values['city_id'] ?? null;
        if (!$cityId) {
            $this->resultError = 'Vui lòng chọn khu vực.';
            return;
        }

        try {
            $cityName    = $this->cityName();

            // Pickup: dùng stored coords từ map/autocomplete, fallback geocode
            $pickupLat = $this->pickupLat;
            $pickupLng = $this->pickupLng;
            if ($pickupLat === null) {
                $pickupGeo = GoogleMapService::geocode($this->withCity($values['pickup_address'], $cityName));
                $pickupLat = $pickupGeo['lat'] ?? null;
                $pickupLng = $pickupGeo['lng'] ?? null;
            }

            // Topup: không có điểm giao riêng — dùng lại coords pickup
            if ($this->serviceType === 'topup') {
                $deliveryLat = $pickupLat;
                $deliveryLng = $pickupLng;
            } else {
                $deliveryLat = $this->deliveryLat;
                $deliveryLng = $this->deliveryLng;
                if ($deliveryLat === null) {
                    $deliveryGeo = GoogleMapService::geocode($this->withCity($values['delivery_address'], $cityName));
                    $deliveryLat = $deliveryGeo['lat'] ?? null;
                    $deliveryLng = $deliveryGeo['lng'] ?? null;
                }
            }

            if ($this->serviceType === 'topup') {
                $amount  = (int) ($values['cod_amount'] ?? 0);
                $pricing = [
                    'fee'         => PricingService::topupFee($amount, $cityId),
                    'distance_km' => 0,
                ];
            } elseif ($pickupLat && $deliveryLat) {
                $pricing = PricingService::estimateFromCoords(
                    $this->serviceType,
                    $pickupLat,  $pickupLng,
                    $deliveryLat, $deliveryLng,
                    $cityId
                );
            } else {
                $pricing = PricingService::estimate($this->serviceType, 3.0, $cityId);
            }

            $shippingFee = !empty($values['shipping_fee']) ? (int) $values['shipping_fee'] : ($pricing['fee'] ?? 0);

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
                // Mua hộ: ghép nội dung đơn vào đầu order_note
                'order_note'         => $this->serviceType === 'shopping'
                    ? trim(($values['shopping_note'] ?? '') . "\n" . ($values['order_note'] ?? ''))  ?: null
                    : ($values['order_note'] ?: null),
                'cod_amount'         => !empty($values['cod_amount']) ? (int) $values['cod_amount'] : null,
                'distance'           => $pricing['distance_km']   ?? null,
            ]);

            $order->refresh();

            app(OrderService::class)->dispatchNewOrder($order->id);

            $this->resultOrderCode = $order->code;
            $this->resultFee       = $shippingFee;
            $this->resultDistance  = isset($pricing['distance_km'])
                ? number_format($pricing['distance_km'], 1) . ' km' : null;

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
