<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Gemini\Laravel\Facades\Gemini;
use Modules\Core\Services\GoogleMapService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Shop\Services\ShopPricingService;

class CallCenterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-phone';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Tổng đài đặt đơn';
    protected static ?string $title           = 'Tổng đài — Đặt đơn hộ';
    protected static ?int    $navigationSort  = 10;

    protected static string $view = 'filament.pages.call-center';

    public static function canAccess(): bool
    {
        return auth()->user()?->user_type !== 'city_manager';
    }

    public string $rawText  = '';
    public string $aiStatus = '';
    public array  $data     = [];

    // Preview phí trước khi đặt
    public ?int    $previewFee      = null;
    public ?string $previewDistance = null;
    public ?string $previewStatus   = null;

    // Kết quả sau khi đặt
    public ?string $resultOrderCode  = null;
    public ?string $resultError      = null;
    public ?int    $resultFee        = null;
    public ?string $resultDistance   = null;

    public function mount(): void
    {
        $this->form->fill([
            'city_id'          => null,
            'store_name'       => '',
            'pickup_address'   => '',
            'pickup_phone'     => '',
            'pickup_name'      => '',
            'delivery_address' => '',
            'delivery_phone'   => '',
            'delivery_name'    => '',
            'order_note'       => '',
            'cod_amount'       => null,
            'cargo_type'       => 'parcel',
        ]);
    }

    public function form(Form $form): Form
    {
        $cities = DB::table('cities')->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn($c) => [$c->id => $c->name])->toArray();

        return $form->schema([

            Section::make('Khu vực & Shop')
                ->icon('heroicon-o-map-pin')
                ->schema([
                    Select::make('city_id')
                        ->label('Khu vực')
                        ->options($cities)
                        ->placeholder('Chọn khu vực...')
                        ->required()
                        ->searchable(),
                    TextInput::make('store_name')
                        ->label('Tên shop')
                        ->placeholder('Tên cửa hàng...'),
                ])->columns(2),

            Section::make('Địa chỉ lấy hàng')
                ->icon('heroicon-o-building-storefront')
                ->schema([
                    TextInput::make('pickup_address')
                        ->label('Địa chỉ lấy hàng')
                        ->placeholder('Số nhà, đường, phường, quận...')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('pickup_name')
                        ->label('Tên người gửi')
                        ->placeholder('Họ tên'),
                    TextInput::make('pickup_phone')
                        ->label('SĐT người gửi')
                        ->tel()
                        ->placeholder('0xxx xxx xxx'),
                ])->columns(2),

            Section::make('Địa chỉ giao hàng')
                ->icon('heroicon-o-home')
                ->schema([
                    TextInput::make('delivery_address')
                        ->label('Địa chỉ giao hàng')
                        ->placeholder('Số nhà, đường, phường, quận...')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('delivery_name')
                        ->label('Tên người nhận')
                        ->placeholder('Họ tên'),
                    TextInput::make('delivery_phone')
                        ->label('SĐT người nhận')
                        ->tel()
                        ->placeholder('0xxx xxx xxx')
                        ->required(),
                ])->columns(2),

            Section::make('Thông tin đơn hàng')
                ->icon('heroicon-o-cube')
                ->schema([
                    Select::make('cargo_type')
                        ->label('Loại hàng')
                        ->options(['food' => 'Đồ ăn', 'parcel' => 'Hàng hóa', 'flowers' => 'Hoa'])
                        ->default('parcel')
                        ->required(),
                    TextInput::make('cod_amount')
                        ->label('Thu hộ COD (đồng)')
                        ->numeric()
                        ->placeholder('0 nếu không thu hộ'),
                    Textarea::make('order_note')
                        ->label('Ghi chú đơn hàng')
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

    /** Append tên thành phố vào địa chỉ nếu chưa có */
    private function withCity(string $address, ?string $cityName): string
    {
        if (!$cityName || mb_stripos($address, $cityName) !== false) return $address;
        return $address . ', ' . $cityName;
    }

    // ─── AI Parsing ──────────────────────────────────────────────────────────

    public function parseWithAI(): void
    {
        $text = trim($this->rawText);
        if (!$text) {
            $this->aiStatus = 'Vui lòng nhập nội dung đơn hàng.';
            return;
        }

        $this->aiStatus = '⏳ Đang phân tích...';

        $cityName    = $this->cityName();
        $cityHint    = $cityName
            ? "- Khu vực đặt đơn là: {$cityName}. Nếu địa chỉ không có tên thành phố/tỉnh, hãy tự thêm \", {$cityName}\" vào cuối."
            : '- Giữ nguyên địa chỉ như trong text.';

        $prompt = <<<PROMPT
Bạn là trợ lý đặt đơn giao hàng. Phân tích đoạn text và trả về JSON thuần (không markdown, không ```).

TEXT:
{$text}

JSON cần trả về:
{
  "store_name": "",
  "pickup_name": "",
  "pickup_phone": "",
  "pickup_address": "",
  "delivery_name": "",
  "delivery_phone": "",
  "delivery_address": "",
  "cod_amount": null,
  "cargo_type": "parcel",
  "order_note": ""
}

Quy tắc:
- cargo_type chỉ được là: "food", "parcel", hoặc "flowers"
- cod_amount là số nguyên hoặc null
{$cityHint}
- Chỉ trả về JSON thuần
PROMPT;

        try {
            $response = Gemini::generativeModel(model: 'models/gemini-2.5-flash')
                ->generateContent($prompt);

            $json = trim($response->text());
            $json = preg_replace('/^```[a-z]*\n?/i', '', $json);
            $json = preg_replace('/\n?```$/i', '', $json);

            $parsed = json_decode($json, true);

            if (!is_array($parsed)) {
                $this->aiStatus = '❌ AI không trả về dữ liệu hợp lệ.';
                return;
            }

            $this->form->fill(array_merge($this->data, array_filter([
                'store_name'       => $parsed['store_name']       ?: null,
                'pickup_name'      => $parsed['pickup_name']      ?: null,
                'pickup_phone'     => $parsed['pickup_phone']     ?: null,
                'pickup_address'   => $parsed['pickup_address']   ?: null,
                'delivery_name'    => $parsed['delivery_name']    ?: null,
                'delivery_phone'   => $parsed['delivery_phone']   ?: null,
                'delivery_address' => $parsed['delivery_address'] ?: null,
                'order_note'       => $parsed['order_note']       ?: null,
                'cod_amount'       => isset($parsed['cod_amount']) && $parsed['cod_amount'] > 0
                                      ? (int) $parsed['cod_amount'] : null,
                'cargo_type'       => in_array($parsed['cargo_type'] ?? '', ['food', 'parcel', 'flowers'])
                                      ? $parsed['cargo_type'] : 'parcel',
            ], fn($v) => $v !== null)));

            $this->aiStatus = '✅ Đã điền thông tin từ AI. Kiểm tra lại trước khi đặt.';

        } catch (\Throwable $e) {
            $this->aiStatus = '❌ Lỗi AI: ' . $e->getMessage();
        }
    }

    // ─── Preview phí ─────────────────────────────────────────────────────────

    public function calculateFee(): void
    {
        $pickup   = trim($this->data['pickup_address']   ?? '');
        $delivery = trim($this->data['delivery_address'] ?? '');
        $cityId   = $this->data['city_id'] ?? null;

        if (!$pickup || !$delivery) {
            $this->previewStatus = '⚠️ Vui lòng điền đủ địa chỉ lấy hàng và giao hàng.';
            return;
        }

        $this->previewStatus   = '⏳ Đang tính...';
        $this->previewFee      = null;
        $this->previewDistance = null;

        $cityName    = $this->cityName();
        $pickupGeo   = GoogleMapService::geocode($this->withCity($pickup, $cityName));
        $deliveryGeo = GoogleMapService::geocode($this->withCity($delivery, $cityName));

        if ($pickupGeo && $deliveryGeo) {
            $cargoType = $this->data['cargo_type'] ?? 'parcel';
            $pricing = ShopPricingService::estimateFromCoords(
                $cargoType,
                $pickupGeo['lat'],   $pickupGeo['lng'],
                $deliveryGeo['lat'], $deliveryGeo['lng'],
                null, $cityId
            );
            $this->previewFee      = $pricing['fee'];
            $this->previewDistance = number_format($pricing['distance_km'], 1) . ' km';
            $this->previewStatus   = '✅ Đã tính xong.';
        } else {
            $failed = !$pickupGeo ? 'địa chỉ lấy hàng' : 'địa chỉ giao hàng';
            $this->previewStatus = "❌ Không geocode được {$failed} — kiểm tra lại địa chỉ.";
        }
    }

    // ─── Place Order ─────────────────────────────────────────────────────────

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
            // Geocode 2 địa chỉ — append tên thành phố nếu địa chỉ chưa có
            $cityName    = $this->cityName();
            $pickupGeo   = GoogleMapService::geocode($this->withCity($values['pickup_address'], $cityName));
            $deliveryGeo = GoogleMapService::geocode($this->withCity($values['delivery_address'], $cityName));

            $cargoType = $values['cargo_type'] ?? 'parcel';
            if ($pickupGeo && $deliveryGeo) {
                $pricing = ShopPricingService::estimateFromCoords(
                    $cargoType,
                    $pickupGeo['lat'],  $pickupGeo['lng'],
                    $deliveryGeo['lat'], $deliveryGeo['lng'],
                    null, $cityId
                );
            } else {
                $pricing = ShopPricingService::estimate($cargoType, 3.0, null, $cityId);
                $pricing['geocode_failed'] = true;
            }
            $shippingFee = $pricing['fee'] ?? 0;

            $order = Order::create([
                'code'               => '',
                'service_type'       => 'delivery',
                'platform'           => 'call_center',
                'city_id'            => $cityId,
                'shipping_fee'       => $shippingFee,
                'bonus_fee'          => 0,
                'is_freeship'        => false,
                'status'             => 'pending',
                'payment_method'     => 'cod',
                'sender_platform_id' => null,
                'pickup_address'     => $values['pickup_address'],
                'pickup_lat'         => $pickupGeo['lat']   ?? null,
                'pickup_lng'         => $pickupGeo['lng']   ?? null,
                'pickup_phone'       => $values['pickup_phone']  ?: null,
                'sender_name'        => $values['pickup_name']   ?: null,
                'delivery_address'   => $values['delivery_address'],
                'delivery_lat'       => $deliveryGeo['lat'] ?? null,
                'delivery_lng'       => $deliveryGeo['lng'] ?? null,
                'delivery_phone'     => $values['delivery_phone'],
                'receiver_name'      => $values['delivery_name'] ?: null,
                'order_note'         => $values['order_note']    ?: null,
                'cod_amount'         => $values['cod_amount'] ? (int) $values['cod_amount'] : null,
                'store_name'         => $values['store_name']    ?: null,
                'distance'           => $pricing['distance_km']  ?? null,
            ]);

            app(OrderService::class)->dispatchNewOrder($order->id);

            $this->resultOrderCode = $order->code;
            $this->resultFee       = $shippingFee;
            $this->resultDistance  = isset($pricing['distance_km']) ? number_format($pricing['distance_km'], 1) . ' km' : null;

            $this->rawText  = '';
            $this->aiStatus = '';
            $this->form->fill([
                'city_id'          => $cityId,
                'store_name'       => '',
                'pickup_address'   => '',
                'pickup_phone'     => '',
                'pickup_name'      => '',
                'delivery_address' => '',
                'delivery_phone'   => '',
                'delivery_name'    => '',
                'order_note'       => '',
                'cod_amount'       => null,
                'cargo_type'       => 'parcel',
            ]);

            Notification::make()
                ->title("Đặt đơn thành công — Mã: {$order->code}")
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
    }
}
