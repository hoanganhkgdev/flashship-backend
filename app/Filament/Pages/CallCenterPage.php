<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Livewire\Attributes\Renderless;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderTimeline;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderService;
use Modules\Pricing\Services\PricingService;
use Modules\Shop\Services\ShopPricingService;

class CallCenterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $navigationLabel = 'Tổng đài đặt đơn';

    protected static ?string $title = 'Tổng đài đặt đơn';

    protected static ?string $slug = 'tong-dai-dat-don';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.call-center';

    public static function canAccess(): bool
    {
        return ! in_array(auth()->user()?->user_type, ['city_manager']);
    }

    public function getHeading(): string
    {
        return '';
    }

    // ─── State ───────────────────────────────────────────────────────────────

    public string $serviceType = 'delivery';

    public array $data = [];

    // Coords from map picker / autocomplete (skip geocoding on order)
    public ?float $pickupLat = null;

    public ?float $pickupLng = null;

    public ?float $deliveryLat = null;

    public ?float $deliveryLng = null;

    public ?int $previewFee = null;

    public int $previewNightSurcharge = 0;

    public ?string $previewDistance = null;

    public ?string $previewStatus = null;

    // Gán tay tài xế — $onlineDrivers đổ vào dropdown gán tay (TẤT CẢ tài xế
    // đang online trong thành phố, không giới hạn khoảng cách — tổng đài chủ
    // động chọn ai cũng được). $nearbyDrivers riêng cho chấm xanh trên bản đồ
    // (lọc đúng khoảng cách phát đơn đang cấu hình). $assignedDriverId = tài xế tổng đài đang
    // chọn (null = để hệ thống tự chọn như trước).
    public array $onlineDrivers = [];

    public array $nearbyDrivers = [];

    /** Tài xế của khu vực chưa gán được kèm lý do (offline, nợ, đủ đơn...) — hiện mờ trong danh sách chọn. */
    public array $unavailableDrivers = [];

    public ?int $assignedDriverId = null;

    // Freeship — khách không trả phí ship, nền tảng trả thay cho tài xế lúc
    // hoàn thành đơn (xem OrderService::completeOrder(), đã có sẵn cơ chế
    // này, chỉ là trang tổng đài trước giờ luôn hardcode false).
    public bool $isFreeship = false;

    // Lịch sử khách theo SĐT và cảnh báo đơn trùng
    public array $history = [];

    /** Tóm tắt khách theo SĐT: tên, tổng đơn, số đơn đã hủy. */
    public array $customer = [];

    /** Lỗi theo từng ô (khóa = tên ô) để báo ngay dưới ô sai thay vì chỉ một dòng trên đầu trang. */
    public array $fieldErrors = [];

    public ?string $resultOrderCode = null;

    public ?string $resultError = null;

    /** Đơn đã tạo nhưng chưa phát được (lỗi hạ tầng): báo rõ để tổng đài không đặt lại thành đơn trùng. */
    public ?string $resultWarning = null;

    public ?int $resultFee = null;

    public ?string $resultDistance = null;

    // ─── Service definitions ─────────────────────────────────────────────────

    /** Cách form xử lý từng dịch vụ (icon, màu). Chỉ các khóa này form hỗ trợ; bật/tắt, nhãn và thứ tự lấy từ danh mục Dịch vụ. */
    private const SERVICE_STYLES = [
        'delivery' => ['icon' => 'heroicon-o-archive-box', 'color' => '#FF6B35'],
        'shopping' => ['icon' => 'heroicon-o-shopping-bag', 'color' => '#7C4DFF'],
        'topup' => ['icon' => 'heroicon-o-banknotes', 'color' => '#00C896'],
        'bike' => ['icon' => 'heroicon-o-bolt', 'color' => '#FFB300'],
        'motor' => ['icon' => 'heroicon-o-wrench-screwdriver', 'color' => '#1E88E5'],
        'car' => ['icon' => 'heroicon-o-truck', 'color' => '#E53935'],
    ];

    public static function services(): array
    {
        $out = [];
        foreach (ServiceType::active()->get() as $s) {
            if (isset(self::SERVICE_STYLES[$s->key])) {
                $out[$s->key] = ['label' => $s->label] + self::SERVICE_STYLES[$s->key];
            }
        }

        // Danh mục trống hoặc lỗi: không để tổng đài bị khóa, dùng bộ mặc định.
        return $out ?: collect(self::SERVICE_STYLES)->map(fn ($st, $k) => ['label' => ucfirst($k)] + $st)->all();
    }

    private function isRide(): bool
    {
        return in_array($this->serviceType, ['bike', 'motor', 'car']);
    }

    private function userCityId(): int
    {
        return (int) (Filament::getTenant()?->id ?? auth()->user()?->city_id ?? 0);
    }

    // ─── Mount ───────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $request = request();
        if (! array_key_exists($this->serviceType, self::services())) {
            $this->serviceType = (string) array_key_first(self::services());
        }

        if ($request->has('reorder')) {
            $service = $request->get('service', 'delivery');
            if (array_key_exists($service, self::services())) {
                $this->serviceType = $service;
            }

            $this->pickupLat = $request->filled('pickup_lat') ? (float) $request->get('pickup_lat') : null;
            $this->pickupLng = $request->filled('pickup_lng') ? (float) $request->get('pickup_lng') : null;
            $this->deliveryLat = $request->filled('delivery_lat') ? (float) $request->get('delivery_lat') : null;
            $this->deliveryLng = $request->filled('delivery_lng') ? (float) $request->get('delivery_lng') : null;

            $this->form->fill([
                'city_id' => $this->userCityId(),
                'contact_phone' => $this->contactFromParts($service, (string) $request->get('pickup_phone', ''), (string) $request->get('delivery_phone', '')),
                'contact_name' => '',
                'pickup_address' => $request->get('pickup_address', ''),
                'delivery_address' => $request->get('delivery_address', ''),
                'delivery_phone' => $service === 'delivery' ? $request->get('delivery_phone', '') : '',
                'shopping_note' => $service === 'shopping' ? $request->get('order_note', '') : '',
                'order_note' => $service !== 'shopping' ? $request->get('order_note', '') : '',
                'cod_amount' => null,
                'shipping_fee' => null,
                'fee_note' => '',
            ]);

            $this->refreshNearbyDrivers();
        } else {
            $this->form->fill($this->defaultFormData());
            // Danh sách tài xế để gán tay phải có ngay khi mở trang, không đợi chọn điểm lấy
            $this->refreshNearbyDrivers();
        }
    }

    private function defaultFormData(mixed $cityId = null): array
    {
        return [
            'city_id' => $cityId ?? $this->userCityId(),
            'contact_phone' => '', // SĐT liên hệ: shop/khách đang gọi — luôn hỏi đầu tiên
            'contact_name' => '',
            'pickup_address' => '',
            'delivery_address' => '',
            'delivery_phone' => '', // Lấy hộ: SĐT người nhận (tuỳ chọn — shop nhiều khi chưa cho)
            'shopping_note' => '', // Mua hộ: nội dung đơn / gọi khách
            'order_note' => '', // Ghi chú cho tài xế
            'cod_amount' => null,
            'shipping_fee' => null,
            'fee_note' => '',
        ];
    }

    /** Từ SĐT điểm lấy/giao (link đặt lại, đơn cũ) suy ra SĐT liên hệ theo đúng quy ước của từng dịch vụ. */
    private function contactFromParts(string $service, string $pickupPhone, string $deliveryPhone): string
    {
        return match ($service) {
            'shopping' => $deliveryPhone ?: $pickupPhone,
            default => $pickupPhone ?: $deliveryPhone,
        };
    }

    // ─── Select service ──────────────────────────────────────────────────────

    public function selectService(string $key): void
    {
        if (! array_key_exists($key, self::services())) {
            return;
        }
        $this->serviceType = $key;

        // Chọn nhầm dịch vụ rồi đổi lại không được mất công nhập: giữ khách và hành trình,
        // chỉ bỏ những ô riêng của dịch vụ (phí, nội dung mua, số tiền nạp).
        $this->data['shopping_note'] = '';
        $this->data['cod_amount'] = null;
        $this->data['shipping_fee'] = null;
        $this->data['fee_note'] = '';
        if ($key !== 'delivery') {
            $this->data['delivery_phone'] = '';
        }
        $this->previewFee = null;
        $this->previewNightSurcharge = 0;
        $this->previewDistance = null;
        $this->previewStatus = null;
        $this->resultOrderCode = null;
        $this->resultError = null;
        $this->resultFee = null;
        $this->resultDistance = null;
        $this->assignedDriverId = null;
        $this->isFreeship = false;
        $this->fieldErrors = [];

        $this->suggestShippingFee();
        $this->refreshNearbyDrivers();
    }

    /** Sau khi gõ tay đổi/xóa một địa chỉ (toạ độ cũ bị bỏ): tính lại phí và danh sách tài xế theo điểm còn lại. */
    public function refreshPreview(): void
    {
        $this->previewFee = null;
        $this->previewNightSurcharge = 0;
        $this->previewDistance = null;
        $this->suggestShippingFee();
        $this->refreshNearbyDrivers();
    }

    /** Dùng đúng phí hệ thống tính (một chạm) — không cần lý do vì trùng giá chuẩn. */
    public function useSystemFee(): void
    {
        if ($this->previewFee === null) {
            return;
        }
        $this->data['shipping_fee'] = (int) $this->previewFee;
        unset($this->fieldErrors['shipping_fee']);
    }

    public function setPickupLocation(string $address, float $lat, float $lng): void
    {
        $this->data['pickup_address'] = $address;
        $this->pickupLat = $lat;
        $this->pickupLng = $lng;
        $this->previewFee = null;
        $this->previewDistance = null;
        $this->previewStatus = null;
        $this->assignedDriverId = null;
        $this->suggestShippingFee();
        $this->refreshNearbyDrivers();
    }

    public function setDeliveryLocation(string $address, float $lat, float $lng): void
    {
        $this->data['delivery_address'] = $address;
        $this->deliveryLat = $lat;
        $this->deliveryLng = $lng;
        $this->previewFee = null;
        $this->previewDistance = null;
        $this->previewStatus = null;
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
        if ($this->serviceType === 'topup') {
            return;
        }
        if (! $this->pickupLat || ! $this->pickupLng || ! $this->deliveryLat || ! $this->deliveryLng) {
            return;
        }

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
     *  - $nearbyDrivers: lọc theo khoảng cách phát đơn đường thật tính từ điểm
     *    lấy hàng (khớp cấu hình khoảng cách phát đơn) — chỉ để hiện
     *    chấm xanh + đếm số trên bản đồ, không dùng cho dropdown gán tay nữa.
     *
     * Gọi trực tiếp trong setPickupLocation() (cùng 1 request với lúc set
     * toạ độ) — KHÔNG được gọi từ trong Livewire.hook('commit') phía JS, hook
     * đó chạy lại trên MỌI commit kể cả commit do chính việc gọi @this.call()
     * tạo ra, gây vòng lặp vô hạn (đã gặp bug này 1 lần — bản đồ chớp liên
     * tục do load lại Google Maps API hàng chục lần/giây).
     */
    /** Bán kính (km, đường chim bay) hiện tài xế quanh điểm lấy trên bản đồ. */
    public const NEARBY_KM = 4;

    private function refreshNearbyDrivers(): void
    {
        $cityId = $this->data['city_id'] ?? null;
        $this->onlineDrivers = [];
        $this->nearbyDrivers = [];
        $this->unavailableDrivers = [];
        if (! $cityId) {
            return;
        }

        // Tất cả tài xế đang hoạt động của khu vực kèm lý do chưa gán được (cùng bộ điều kiện với hộp thoại gán).
        $probe = new Order(['city_id' => $cityId, 'service_type' => $this->serviceType]);
        $assignable = OrderResource::manualAssignmentDriverOptions($probe);
        $candidates = collect(OrderResource::manualAssignmentCandidates($probe));

        $this->unavailableDrivers = $candidates
            ->filter(fn (array $c) => $c['reason'] !== null)
            ->map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'reason' => $c['reason']])
            ->values()->all();

        // Vị trí chỉ cần khi đã chọn điểm lấy — một lần đọc Firebase dùng cho cả danh sách chọn lẫn bản đồ.
        $locations = [];
        $pickupKnown = $this->pickupLat && $this->pickupLng;
        if ($pickupKnown) {
            $onlineIds = $candidates->filter(fn (array $c) => $c['online'])->pluck('id')->all();
            $locations = $onlineIds ? app(DriverLocationService::class)->freshLocationsFor($onlineIds) : [];
        }

        // Danh sách chọn gán tay: người gán được, gần nhất lên trước; chưa có vị trí thì xuống cuối nhưng vẫn chọn được.
        $roadDistances = [];
        if ($pickupKnown) {
            $origins = array_intersect_key($locations, $assignable);
            $roadDistances = GoogleMapService::roadDistanceBatchKm($origins, $this->pickupLat, $this->pickupLng);
        }
        $this->onlineDrivers = $candidates
            ->filter(fn (array $c) => $c['reason'] === null)
            ->map(fn (array $c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'label' => $assignable[$c['id']] ?? $c['name'],
                'km' => isset($roadDistances[$c['id']]) ? round($roadDistances[$c['id']], 1) : null,
            ])
            ->sortBy(fn (array $d) => $d['km'] ?? PHP_INT_MAX)
            ->values()
            ->all();

        // Chấm trên bản đồ: mọi tài xế đang online có GPS mới trong NEARBY_KM quanh điểm lấy (kể cả người đang bận,
        // để tổng đài thấy lực lượng thật quanh đó); người chưa gán được vẫn hiện nhưng xám kèm lý do.
        if ($pickupKnown) {
            $this->nearbyDrivers = $candidates
                ->filter(fn (array $c) => $c['online'] && isset($locations[$c['id']]))
                ->map(function (array $c) use ($locations) {
                    $loc = $locations[$c['id']];
                    $km = GoogleMapService::haversineKm($this->pickupLat, $this->pickupLng, $loc['lat'], $loc['lng']);

                    return [
                        'id' => $c['id'], 'name' => $c['name'], 'phone' => $c['phone'],
                        'lat' => $loc['lat'], 'lng' => $loc['lng'], 'km' => round($km, 1),
                        'state' => $c['reason'] !== null ? 'blocked' : ($c['active'] > 0 ? 'busy' : 'free'),
                        'assignable' => $c['reason'] === null,
                        'active' => $c['active'], 'max' => $c['max'], 'reason' => $c['reason'],
                    ];
                })
                ->filter(fn (array $d) => $d['km'] <= self::NEARBY_KM)
                ->sortBy('km')
                ->values()
                ->all();
        }
    }

    public function form(Form $form): Form
    {
        return $form->schema([])->statePath('data');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function cityName(): ?string
    {
        $cityId = $this->data['city_id'] ?? null;
        if (! $cityId) {
            return null;
        }

        return DB::table('cities')->where('id', $cityId)->value('name');
    }

    private function withCity(?string $address, ?string $cityName): string
    {
        if (! $address) {
            return '';
        }
        if (! $cityName || mb_stripos($address, $cityName) !== false) {
            return $address;
        }

        return $address.', '.$cityName;
    }

    // ─── Tra cứu khách theo SĐT ──────────────────────────────────────────────

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    /** Livewire: gõ SĐT liên hệ thì tra khách; sửa ô nào thì xóa lỗi của ô đó. */
    public function updatedData($value, $key): void
    {
        unset($this->fieldErrors[$key]);
        if ($key === 'contact_phone') {
            $this->loadHistory((string) $value);
        }
    }

    /** Tra khách theo SĐT: tóm tắt (tên, tổng đơn, đơn hủy) + 5 hành trình gần nhất để điền nhanh. */
    public function loadHistory(string $phone): void
    {
        $digits = $this->normalizePhone($phone);
        $this->history = [];
        $this->customer = [];
        if (strlen($digits) < 9) {
            return;
        }

        $like = '%'.substr($digits, -9);
        $base = Order::query()
            ->where('city_id', $this->userCityId())
            ->where(fn ($q) => $q->where('pickup_phone', 'like', $like)->orWhere('delivery_phone', 'like', $like));

        $agg = (clone $base)->selectRaw('COUNT(*) as total, SUM(status = "cancelled") as cancelled, MAX(created_at) as last_at')->first();
        $total = (int) ($agg->total ?? 0);
        if ($total === 0) {
            return;
        }

        $name = (string) ((clone $base)->whereNotNull('sender_name')->where('sender_name', '!=', '')->latest('id')->value('sender_name') ?? '');
        $cancelled = (int) $agg->cancelled;
        $this->customer = [
            'name' => $name,
            'total' => $total,
            'cancelled' => $cancelled,
            // Hủy nhiều so với số đơn: nhắc tổng đài xác nhận kỹ trước khi phát đơn
            'risky' => $total >= 3 && $cancelled / $total >= 0.4,
            'last_at' => $agg->last_at ? \Carbon\Carbon::parse($agg->last_at)->format('d/m H:i') : null,
        ];
        if ($name !== '' && trim((string) ($this->data['contact_name'] ?? '')) === '') {
            $this->data['contact_name'] = $name;
        }

        $labels = ServiceType::pluck('label', 'key');
        $this->history = (clone $base)
            ->orderByDesc('id')->limit(5)
            ->get(['id', 'code', 'service_type', 'status', 'pickup_address', 'delivery_address', 'created_at'])
            ->map(fn (Order $o) => [
                'id' => $o->id, 'code' => $o->code, 'service' => $labels[$o->service_type] ?? $o->service_type,
                'status' => $o->status, 'pickup' => $o->pickup_address, 'delivery' => $o->delivery_address, 'when' => $o->created_at?->format('d/m H:i'),
            ])->all();
    }

    /** Điền lại hành trình từ một đơn cũ của khách (kèm toạ độ để khỏi phải chọn lại). */
    public function useHistory(int $orderId): void
    {
        $o = Order::where('city_id', $this->userCityId())->find($orderId);
        if (! $o) {
            return;
        }
        $this->data['pickup_address'] = $o->pickup_address;
        $this->data['delivery_address'] = $o->delivery_address;
        $this->pickupLat = $o->pickup_lat ? (float) $o->pickup_lat : null;
        $this->pickupLng = $o->pickup_lng ? (float) $o->pickup_lng : null;
        $this->deliveryLat = $o->delivery_lat ? (float) $o->delivery_lat : null;
        $this->deliveryLng = $o->delivery_lng ? (float) $o->delivery_lng : null;
        $this->previewFee = null;
        unset($this->fieldErrors['pickup_address'], $this->fieldErrors['delivery_address']);
        $this->suggestShippingFee();
        $this->refreshNearbyDrivers();
    }

    // ─── Đặt đơn ─────────────────────────────────────────────────────────────

    /** Dịch vụ cho phép bỏ trống điểm giao/đến: shop nhiều khi không cho tổng đài, tài xế hỏi khi tới lấy. */
    private function deliveryOptional(): bool
    {
        return $this->serviceType !== 'shopping' && $this->serviceType !== 'topup';
    }

    public const NO_DELIVERY_NOTE = '[Chưa có điểm giao — hỏi shop khi lấy hàng]';

    public function placeOrder(): void
    {
        $values = $this->data;
        $this->fieldErrors = [];

        $this->resultOrderCode = null;
        $this->resultError = null;
        $this->resultWarning = null;
        $this->resultFee = null;
        $this->resultDistance = null;

        // Đơn luôn thuộc khu vực đang chọn trên thanh header.
        $values['city_id'] = $this->userCityId();

        $cityId = $values['city_id'] ?? null;
        if (! $cityId || ! DB::table('cities')->where('id', $cityId)->where('is_active', true)->exists()) {
            $this->resultError = 'Khu vực không hợp lệ.';

            return;
        }

        $pickupLabel = match ($this->serviceType) {
            'shopping' => 'địa chỉ cửa hàng',
            'topup' => 'điểm nạp',
            'bike', 'motor', 'car' => 'điểm đón',
            default => 'điểm lấy hàng',
        };

        $errors = [];

        // SĐT liên hệ (shop/khách) không bắt buộc ở mọi dịch vụ: nhiều khi khách không cho số.
        // Đã nhập thì phải đủ số, tránh lưu số gõ dở.
        $contact = $this->normalizePhone($values['contact_phone'] ?? '');
        if ($contact !== '' && strlen($contact) < 9) {
            $errors['contact_phone'] = 'Số điện thoại chưa đủ (ít nhất 9 chữ số) — nhập đủ số hoặc để trống.';
        }

        $pickupAddress = trim($values['pickup_address'] ?? '');
        if ($pickupAddress === '') {
            $errors['pickup_address'] = "Vui lòng nhập {$pickupLabel}.";
        } elseif (! $this->pickupLat || ! $this->pickupLng) {
            $errors['pickup_address'] = "Vui lòng chọn {$pickupLabel} từ gợi ý hoặc bản đồ để có toạ độ chính xác.";
        }

        // Mua hộ phải giao đến khách và biết mua gì; các dịch vụ khác được để trống điểm giao.
        if ($this->serviceType === 'shopping') {
            if (trim($values['delivery_address'] ?? '') === '') {
                $errors['delivery_address'] = 'Vui lòng nhập địa chỉ giao cho khách.';
            }
            if (mb_strlen(trim($values['shopping_note'] ?? '')) < 3) {
                $errors['shopping_note'] = 'Vui lòng ghi hàng cần mua.';
            }
        }
        if ($this->serviceType === 'topup' && (int) ($values['cod_amount'] ?? 0) <= 0) {
            $errors['cod_amount'] = 'Vui lòng nhập số tiền cần nạp.';
        }

        $shippingFee = max(0, (int) ($values['shipping_fee'] ?? 0));

        // Tài xế chọn tay phải còn đủ điều kiện TRƯỚC khi tạo đơn — tránh tạo rồi hủy ngay làm sai số đơn hủy.
        if ($this->assignedDriverId) {
            $eligible = OrderResource::manualAssignmentDriverOptions(new Order(['city_id' => $cityId, 'service_type' => $this->serviceType]));
            if (! array_key_exists($this->assignedDriverId, $eligible)) {
                $errors['assignedDriverId'] = 'Tài xế này không còn đủ điều kiện nhận đơn (đủ đơn, nợ quá hạn, nghỉ phép hoặc đã offline) — chọn người khác hoặc để hệ thống tự chọn.';
            }
        }

        if ($errors) {
            $this->fieldErrors = $errors;
            $this->resultError = count($errors) === 1
                ? array_values($errors)[0]
                : 'Còn '.count($errors).' mục cần bổ sung — các ô được đánh dấu đỏ bên dưới.';

            return;
        }

        $codAmount = ! empty($values['cod_amount']) ? max(0, (int) $values['cod_amount']) : null;

        try {
            $cityName = $this->cityName();

            $pickupLat = $this->pickupLat;
            $pickupLng = $this->pickupLng;

            $deliveryAddress = trim((string) ($values['delivery_address'] ?? ''));
            $noDelivery = $this->serviceType !== 'topup' && $deliveryAddress === '';

            if ($this->serviceType === 'topup') {
                $deliveryLat = $pickupLat;
                $deliveryLng = $pickupLng;
            } else {
                $deliveryLat = $this->deliveryLat;
                $deliveryLng = $this->deliveryLng;
                if ($deliveryLat === null && $deliveryAddress !== '') {
                    $deliveryGeo = GoogleMapService::geocode($this->withCity($deliveryAddress, $cityName));
                    $deliveryLat = $deliveryGeo['lat'] ?? null;
                    $deliveryLng = $deliveryGeo['lng'] ?? null;
                }
            }

            // SĐT theo quy ước từng dịch vụ: lấy hộ → SĐT điểm lấy là của shop (người gọi), SĐT nhận hàng tuỳ chọn;
            // mua hộ → người gọi là khách nhận hàng; nạp tiền/xe → người gọi là khách.
            $callerPhone = trim((string) ($values['contact_phone'] ?? ''));
            [$pickupPhone, $deliveryPhone] = match (true) {
                $this->serviceType === 'shopping' => [null, $callerPhone],
                $this->serviceType === 'delivery' => [$callerPhone, trim((string) ($values['delivery_phone'] ?? '')) ?: null],
                default => [$callerPhone, $callerPhone], // nạp tiền, xe ôm, lái xe
            };

            $note = $this->serviceType === 'shopping'
                ? trim($values['shopping_note'] ?? '')
                : trim($values['order_note'] ?? '');
            if ($noDelivery && $this->serviceType === 'delivery') {
                $note = trim(self::NO_DELIVERY_NOTE.' '.$note);
            }

            $contactName = mb_substr(trim((string) ($values['contact_name'] ?? '')), 0, 100);

            $order = Order::create([
                'code' => '',
                'service_type' => $this->serviceType,
                'platform' => 'call_center',
                'city_id' => $cityId,
                'created_by' => auth()->id(),
                'shipping_fee' => $shippingFee,
                'night_surcharge' => $this->previewNightSurcharge,
                'bonus_fee' => 0,
                'is_freeship' => $this->isFreeship,
                'status' => 'pending',
                'payment_method' => 'cod',
                'sender_platform_id' => null,
                'sender_name' => $contactName !== '' ? $contactName : null,
                'pickup_place_name' => null,
                'pickup_address' => $values['pickup_address'],
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'pickup_phone' => $pickupPhone ?: null,
                'delivery_address' => $this->serviceType === 'topup' ? $values['pickup_address'] : $deliveryAddress,
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'delivery_phone' => $deliveryPhone ?: null,
                'receiver_name' => null,
                'order_note' => $note !== '' ? $note : null,
                'cod_amount' => $codAmount,
                'distance' => null,
            ]);

            $order->refresh();
            OrderTimeline::record($order, 'created', 'Tổng đài tạo đơn'
                .($noDelivery ? ' — chưa có điểm giao' : '')
                .' — phí '.number_format($shippingFee, 0, ',', '.').'₫', auth()->id());

            if ($this->assignedDriverId) {
                // Gán tay — gán CỨNG luôn cho đúng người tổng đài chọn, đơn vào thẳng mục "Đã nhận" của tài xế.
                // Điều kiện đã kiểm tra ở trên; nếu vẫn thất bại (vừa bận do đua nhau) thì HUỶ đơn, KHÔNG tự chuyển
                // sang phát cho cả thành phố vì tổng đài đã chủ động chọn đúng người này.
                $assignResult = app(DispatchService::class)->assignDriverDirectly($order, $this->assignedDriverId);
                if ($assignResult['success']) {
                    Notification::make()
                        ->title($assignResult['message'])
                        ->success()
                        ->send();
                } else {
                    DB::table('orders')->where('id', $order->id)->update([
                        'status' => 'cancelled',
                        'cancel_reason' => 'admin_no_driver',
                        'cancel_note' => 'Gán tay thất bại: '.$assignResult['message'],
                        'cancelled_by' => auth()->id(),
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
                    OrderTimeline::record($order, 'cancelled', 'Hủy đơn — gán tay thất bại: '.$assignResult['message'], auth()->id());
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
                // Đơn đã tạo xong: lỗi lúc phát (Redis/Firebase...) không được biến thành "đặt đơn thất bại" —
                // nhân viên sẽ đặt lại và ra đơn trùng. Báo rõ đơn đã có, chưa phát được, để gán tay hoặc báo kỹ thuật.
                try {
                    app(OrderService::class)->dispatchNewOrder($order->id);
                } catch (\Throwable $e) {
                    report($e);
                    $this->resultWarning = "Đơn #{$order->code} đã được tạo nhưng hệ thống chưa phát được cho tài xế ({$e->getMessage()}). Đừng đặt lại — hãy gán tài xế tay ở khối \"Đơn vừa đặt\" hoặc báo kỹ thuật.";
                }
            }

            $this->resultOrderCode = $order->code;
            $this->resultFee = $shippingFee;
            $this->resultDistance = null;

            $this->pickupLat = null;
            $this->pickupLng = null;
            $this->deliveryLat = null;
            $this->deliveryLng = null;
            $this->previewFee = null;
            $this->previewNightSurcharge = 0;
            $this->onlineDrivers = [];
            $this->nearbyDrivers = [];
            $this->assignedDriverId = null;
            $this->isFreeship = false;
            $this->history = [];
            $this->customer = [];

            $this->form->fill($this->defaultFormData($cityId));
            $this->refreshNearbyDrivers();
            $this->dispatch('cc-recent-refresh');

            if ($this->resultWarning) {
                Notification::make()->title("Đơn #{$order->code} đã tạo — chưa phát được")->body('Chưa phát được cho tài xế, hãy gán tay.')->warning()->send();
            } else {
                Notification::make()
                    ->title("Đặt đơn thành công — #{$order->code}")
                    ->success()
                    ->send();
            }

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
        $this->resultError = null;
        $this->resultWarning = null;
        $this->resultFee = null;
        $this->resultDistance = null;
        $this->fieldErrors = [];
    }

    // ─── Đơn vừa đặt (theo dõi ngay trên trang) ──────────────────────────────

    private const STATUS_LABELS = [
        'pending' => 'Chờ tài xế', 'assigned' => 'Đã có tài xế', 'processing' => 'Đang giao',
        'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy',
    ];

    /**
     * Đơn tổng đài tạo hôm nay ở khu vực này, mới nhất trước. Renderless: JS gọi định kỳ
     * mà không làm Livewire vẽ lại form (sẽ đè lên địa chỉ đang gõ dở).
     *
     * @return array<int, array>
     */
    #[Renderless]
    public function recentOrders(): array
    {
        $labels = ServiceType::pluck('label', 'key');

        return Order::query()
            ->where('city_id', $this->userCityId())
            ->where('platform', 'call_center')
            ->where('created_at', '>=', now()->startOfDay())
            ->with(['driver:id,name,phone', 'sender:id,name,phone'])
            ->latest('id')->limit(10)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'service' => $labels[$o->service_type] ?? $o->service_type,
                'status' => $o->status,
                'status_label' => self::STATUS_LABELS[$o->status] ?? $o->status,
                'stopped' => $o->status === 'pending' && $o->cancel_reason === 'no_driver',
                'minutes' => (int) $o->created_at->diffInMinutes(now()),
                'pickup' => trim((string) $o->pickup_address) ?: '—',
                'delivery' => trim((string) $o->delivery_address) ?: 'Chưa có điểm giao',
                'no_delivery' => trim((string) $o->delivery_address) === '',
                'phone' => \App\Support\OrderListPresenter::phone(\App\Support\OrderListPresenter::contactPhone($o)),
                'name' => $o->sender_name ?: '',
                'driver' => $o->driver?->name,
                'fee' => (int) $o->shipping_fee,
                'url' => OrderResource::getUrl('view', ['record' => $o->id]),
            ])->all();
    }

    private function recentOrder(array $arguments): ?Order
    {
        return Order::where('city_id', $this->userCityId())->find($arguments['order'] ?? 0);
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Gán tài xế')
            ->icon('heroicon-o-user-plus')
            ->color('info')
            ->modalHeading(fn (array $arguments) => ($o = $this->recentOrder($arguments)) ? 'Gán tài xế cho đơn #'.$o->code : 'Gán tài xế')
            ->modalDescription('Đơn sẽ ngừng tìm tự động và chuyển thẳng vào danh sách đã nhận của tài xế.')
            ->form(fn (array $arguments) => ($o = $this->recentOrder($arguments)) ? OrderResource::manualAssignmentForm($o) : [])
            ->action(function (array $arguments, array $data): void {
                OrderResource::assignDriverManually($this->recentOrder($arguments) ?? new Order(), $data);
                $this->dispatch('cc-recent-refresh');
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Hủy đơn')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(fn (array $arguments) => ($o = $this->recentOrder($arguments)) ? 'Hủy đơn #'.$o->code : 'Hủy đơn')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do hủy')->options(OrderTimeline::CANCEL_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Ghi chú')->rows(2)->maxLength(250)->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(3),
            ])
            ->action(function (array $arguments, array $data): void {
                if ($order = $this->recentOrder($arguments)) {
                    OrderResource::cancelOrder($order, $data['reason'], $data['note'] ?? null);
                }
                $this->dispatch('cc-recent-refresh');
            });
    }
}
