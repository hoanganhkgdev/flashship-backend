<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Support\AdminAccess;
use App\Support\OrderListPresenter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Renderless;
use Modules\Core\Models\ServiceType;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\ManualOrderAssignment;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderTimeline;
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
        return AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE);
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
            $this->deliveryLat = ! in_array($service, ['delivery', 'shopping', 'bike', 'motor', 'car'], true) && $request->filled('delivery_lat') ? (float) $request->get('delivery_lat') : null;
            $this->deliveryLng = ! in_array($service, ['delivery', 'shopping', 'bike', 'motor', 'car'], true) && $request->filled('delivery_lng') ? (float) $request->get('delivery_lng') : null;

            // Đơn cũ có thể còn nhãn "[Chưa có điểm giao …]" tổng đài từng chèn sẵn — không mang sang đơn mới.
            $oldNote = trim((string) preg_replace('/^\s*\[Chưa có điểm giao[^\]]*\]\s*/u', '', (string) $request->get('order_note', '')));

            $this->form->fill([
                'city_id' => $this->userCityId(),
                'contact_phone' => in_array($service, ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'], true)
                    ? ''
                    : $this->contactFromParts($service, (string) $request->get('pickup_phone', ''), (string) $request->get('delivery_phone', '')),
                'pickup_address' => $request->get('pickup_address', ''),
                'pickup_place_name' => mb_substr(trim((string) $request->get('pickup_place_name', '')), 0, 100),
                'delivery_address' => in_array($service, ['delivery', 'shopping', 'bike', 'motor', 'car'], true) ? '' : $request->get('delivery_address', ''),
                'delivery_phone' => '',
                'shopping_note' => $service === 'shopping' ? $oldNote : '',
                'order_note' => $service !== 'shopping' ? $oldNote : '',
                'cod_amount' => null,
                'shipping_fee' => null,
                'bonus_fee' => null,
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
            'contact_phone' => '', // Dùng cho Mua hộ/Nạp tiền/Dịch vụ xe; Lấy hộ đọc SĐT trong ghi chú.
            'pickup_address' => '',
            'pickup_place_name' => '', // Tên cửa hàng từ Google khi chọn gợi ý điểm lấy
            'delivery_address' => '',
            'delivery_phone' => '', // Giữ trong state tương thích; Lấy hộ không còn nhập ô riêng.
            'shopping_note' => '', // Mua hộ: nội dung đơn / gọi khách
            'order_note' => '', // Ghi chú cho tài xế
            'cod_amount' => null,
            'shipping_fee' => null,
            'bonus_fee' => null, // Admin trả thêm để tăng sức hút cho đơn đang treo.
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
        $this->data['bonus_fee'] = null;
        $this->data['fee_note'] = '';
        if ($key !== 'delivery') {
            $this->data['delivery_phone'] = '';
        } else {
            // Lấy hộ chỉ cần điểm lấy và ghi chú; địa chỉ giao/SĐT đã nằm trong
            // ghi chú. Xóa dữ liệu cũ để không mang nhầm từ dịch vụ trước đó.
            $this->data['contact_phone'] = '';
            $this->data['delivery_phone'] = '';
            $this->data['delivery_address'] = '';
            $this->deliveryLat = null;
            $this->deliveryLng = null;
        }
        if (in_array($key, ['shopping', 'topup', 'bike', 'motor', 'car'], true)) {
            // Mua hộ/Nạp tiền/Xe ôm/Lái xe không có SĐT khách (Mua hộ/Xe ôm/Lái xe cũng không có điểm giao); bỏ dữ liệu mang từ dịch vụ trước.
            $this->data['contact_phone'] = '';
            $this->data['delivery_address'] = '';
            $this->deliveryLat = null;
            $this->deliveryLng = null;
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

    /** $placeName: tên cửa hàng/địa điểm Google trả về (null khi chọn một địa chỉ thường); ô nhập hiện tên, địa chỉ nằm dòng dưới. */
    public function setPickupLocation(string $address, float $lat, float $lng, ?string $placeName = null): void
    {
        $this->data['pickup_address'] = $address;
        $this->data['pickup_place_name'] = mb_substr(trim((string) $placeName), 0, 100);
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

        $cityId = $this->userCityId();

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
        // Khu vực lấy từ tenant phía server, không từ data.city_id (trình duyệt sửa được).
        $cityId = $this->userCityId();
        $this->onlineDrivers = [];
        $this->nearbyDrivers = [];
        $this->unavailableDrivers = [];
        if (! $cityId) {
            return;
        }

        // Tất cả tài xế đang hoạt động của khu vực kèm lý do chưa gán được (cùng bộ điều kiện với hộp thoại gán).
        $probe = new Order(['city_id' => $cityId, 'service_type' => $this->serviceType]);
        $candidates = collect(OrderResource::manualAssignmentCandidates($probe));
        $assignable = OrderResource::manualAssignmentOptionsFrom($candidates->all());

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
            $roadDistances = $this->cachedRoadDistances($origins);
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

    /**
     * Distance Matrix tính phí theo lượt gọi: đổi tab dịch vụ hay rời ô địa chỉ mà điểm lấy và tập tài xế
     * không đổi thì dùng lại kết quả trong 60 giây (vị trí tài xế cũng chỉ cập nhật cỡ đó).
     *
     * @param  array<int, array{lat: float, lng: float}>  $origins
     * @return array<int, float>
     */
    private function cachedRoadDistances(array $origins): array
    {
        if (! $origins) {
            return [];
        }
        ksort($origins);
        $key = sprintf('cc:road:%.4f,%.4f:%s', $this->pickupLat, $this->pickupLng, md5(implode(',', array_keys($origins))));

        return Cache::remember($key, 60, fn () => GoogleMapService::roadDistanceBatchKm($origins, $this->pickupLat, $this->pickupLng));
    }

    public function form(Form $form): Form
    {
        return $form->schema([])->statePath('data');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function cityName(): ?string
    {
        $cityId = $this->userCityId();
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

    // ─── SĐT ─────────────────────────────────────────────────────────────────

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    /** Livewire: sửa ô nào thì xóa lỗi của ô đó. */
    public function updatedData($value, $key): void
    {
        unset($this->fieldErrors[$key]);
    }

    // ─── Đặt đơn ─────────────────────────────────────────────────────────────

    /** Dịch vụ cho phép bỏ trống điểm giao/đến: shop nhiều khi không cho tổng đài, tài xế hỏi khi tới lấy. */
    private function deliveryOptional(): bool
    {
        return $this->serviceType !== 'shopping' && $this->serviceType !== 'topup';
    }

    /** Trần cho phí đơn và bonus tổng đài nhập tay (chưa gồm phụ phí đêm hệ thống tự cộng). */
    public const MAX_FEE = 300_000;

    /** Ô tiền: trống → 0; số nguyên không âm → giá trị; còn lại (âm, chữ, số lẻ) → null để báo lỗi. */
    private function moneyInput(mixed $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }

        return preg_match('/^\d{1,9}$/', $raw) ? (int) $raw : null;
    }

    /** Phụ phí đêm đang áp dụng ở khu vực này; tự cộng vào phí đơn khi tổng đài đã nhập phí. */
    public function currentNightSurcharge(): int
    {
        return OperationalSettings::nightSurcharge($this->userCityId() ?: null);
    }

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

        // Lấy hộ/Mua hộ/Nạp tiền không có ô SĐT (SĐT nằm trong ghi chú). Các dịch vụ khác có thể để trống;
        // đã nhập thì phải đủ số, tránh lưu số gõ dở.
        $contact = in_array($this->serviceType, ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'], true)
            ? ''
            : $this->normalizePhone($values['contact_phone'] ?? '');
        if ($contact !== '' && strlen($contact) < 9) {
            $errors['contact_phone'] = 'Số điện thoại chưa đủ (ít nhất 9 chữ số) — nhập đủ số hoặc để trống.';
        }

        // Mua hộ/Nạp tiền/Xe ôm/Lái xe không bắt buộc địa chỉ (có thể nằm trong ghi chú); chọn từ gợi ý thì vẫn lưu toạ độ.
        $pickupAddress = trim($values['pickup_address'] ?? '');
        if (in_array($this->serviceType, ['shopping', 'topup', 'bike', 'motor', 'car'], true)) {
            // bỏ qua kiểm tra điểm mua hàng/điểm nạp/điểm đón
        } elseif ($pickupAddress === '') {
            $errors['pickup_address'] = "Vui lòng nhập {$pickupLabel}.";
        } elseif (! $this->pickupLat || ! $this->pickupLng) {
            $errors['pickup_address'] = "Vui lòng chọn {$pickupLabel} từ gợi ý hoặc bản đồ để có toạ độ chính xác.";
        }

        // Mua hộ vẫn phải biết mua gì, nhưng tổng đài được tạo đơn trước khi
        // khách chốt điểm giao rồi bổ sung/liên hệ sau.
        if ($this->serviceType === 'shopping') {
            if (mb_strlen(trim($values['shopping_note'] ?? '')) < 3) {
                $errors['shopping_note'] = 'Vui lòng ghi hàng cần mua.';
            }
        }

        if (in_array($this->serviceType, ['topup', 'bike', 'motor', 'car'], true) && mb_strlen(trim($values['order_note'] ?? '')) < 3) {
            $errors['order_note'] = $this->isRide() ? 'Vui lòng ghi chú điểm đón/điểm đến.' : 'Vui lòng ghi chú nội dung cần nạp.';
        }

        // Phí được để trống/0: có đơn tài xế tới cửa hàng mới biết giao đi đâu, báo lại để tổng đài cập nhật.
        $shippingFee = $this->moneyInput($values['shipping_fee'] ?? null);
        $bonusFee = $this->moneyInput($values['bonus_fee'] ?? null);
        foreach (['shipping_fee' => [$shippingFee, 'Phí đơn'], 'bonus_fee' => [$bonusFee, 'Bonus tài xế']] as $field => [$amount, $label]) {
            if ($amount === null) {
                $errors[$field] = "{$label} chỉ nhập số, ví dụ 25000.";
            } elseif ($amount > 0 && $amount < 1000) {
                // Gõ "25.000" vào ô số trình duyệt hiểu thành 25 — chặn thay vì lưu 25đ.
                $errors[$field] = "{$label} {$amount}đ có vẻ thiếu số 0 — nhập đủ, ví dụ 25000.";
            } elseif ($amount > self::MAX_FEE) {
                $errors[$field] = "{$label} tối đa ".number_format(self::MAX_FEE, 0, ',', '.').'đ.';
            }
        }

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

        $codAmount = $this->serviceType !== 'topup' && ! empty($values['cod_amount']) ? max(0, (int) $values['cod_amount']) : null;

        try {
            $cityName = $this->cityName();

            $pickupLat = $this->pickupLat;
            $pickupLng = $this->pickupLng;

            // Lấy hộ và Mua hộ không có ô địa chỉ giao. Cưỡng chế rỗng để dữ liệu cũ từ
            // Livewire hoặc link đặt lại không thể lọt vào đơn.
            $deliveryAddress = in_array($this->serviceType, ['delivery', 'shopping', 'bike', 'motor', 'car'], true)
                ? ''
                : trim((string) ($values['delivery_address'] ?? ''));

            if ($this->serviceType === 'topup') {
                $deliveryLat = $pickupLat;
                $deliveryLng = $pickupLng;
            } elseif (in_array($this->serviceType, ['delivery', 'shopping', 'bike', 'motor', 'car'], true)) {
                $deliveryLat = null;
                $deliveryLng = null;
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
            // mua hộ → không lưu SĐT (có trong ghi chú); nạp tiền/xe → người gọi là khách.
            $callerPhone = trim((string) ($values['contact_phone'] ?? ''));
            [$pickupPhone, $deliveryPhone] = match (true) {
                in_array($this->serviceType, ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'], true) => [null, null],
                default => [$callerPhone, $callerPhone], // nạp tiền, xe ôm, lái xe
            };

            // Đơn tổng đài không cần điểm giao (chỉ app shop/khách mới bắt buộc) — không gắn nhãn "chưa có điểm giao".
            $note = $this->serviceType === 'shopping'
                ? trim($values['shopping_note'] ?? '')
                : trim($values['order_note'] ?? '');

            // Phụ phí đêm cộng vào phí đơn như đơn shop (shipping_fee đã gồm, night_surcharge ghi phần tách).
            // Chưa có phí (tài xế báo lại sau) thì chưa cộng, tránh đơn chỉ có mỗi phụ phí.
            $nightSurcharge = $shippingFee > 0 ? $this->currentNightSurcharge() : 0;
            $totalFee = $shippingFee + $nightSurcharge;

            $order = Order::create([
                'code' => '',
                'service_type' => $this->serviceType,
                'platform' => 'call_center',
                'city_id' => $cityId,
                'created_by' => auth()->id(),
                'shipping_fee' => $totalFee,
                'night_surcharge' => $nightSurcharge,
                'bonus_fee' => $bonusFee,
                'is_freeship' => $this->isFreeship,
                'status' => 'pending',
                'payment_method' => 'cod',
                'sender_platform_id' => null,
                'sender_name' => null,
                'pickup_place_name' => trim((string) ($values['pickup_place_name'] ?? '')) ?: null,
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
                .' — phí '.number_format($totalFee, 0, ',', '.').'₫'
                .($nightSurcharge > 0 ? ' (gồm phụ phí đêm '.number_format($nightSurcharge, 0, ',', '.').'₫)' : '')
                .($bonusFee > 0 ? ' — admin thưởng thêm '.number_format($bonusFee, 0, ',', '.').'₫' : ''), auth()->id());

            if ($this->assignedDriverId) {
                // Gán tay — gán CỨNG luôn cho đúng người tổng đài chọn, đơn vào thẳng mục "Đã nhận" của tài xế.
                // Điều kiện đã kiểm tra ở trên; nếu vẫn thất bại (vừa bận do đua nhau) thì HUỶ đơn, KHÔNG tự chuyển
                // sang phát cho cả thành phố vì tổng đài đã chủ động chọn đúng người này.
                // Đơn đã tạo: lỗi hạ tầng (Redis/Firebase) trong lúc gán không được rơi xuống "Đặt đơn thất bại".
                try {
                    $assignResult = app(ManualOrderAssignment::class)->assign($order, $this->assignedDriverId);
                } catch (\Throwable $e) {
                    report($e);
                    $assignResult = ['success' => false, 'message' => 'Lỗi hệ thống khi gán ('.$e->getMessage().').'];
                }

                if ($assignResult['success']) {
                    Notification::make()
                        ->title($assignResult['message'])
                        ->success()
                        ->send();
                } elseif ($this->cancelAfterFailedAssign($order, $assignResult['message'])) {
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
            $this->resultFee = $totalFee;
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

            $this->form->fill($this->defaultFormData($cityId));
            $this->refreshNearbyDrivers();
            $this->dispatch('cc-recent-refresh');

            if ($this->resultWarning) {
                Notification::make()->title("Đơn #{$order->code} đã tạo — cần kiểm tra")->body($this->resultWarning)->warning()->send();
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

    /**
     * Gán tay thất bại: hủy đơn vừa tạo qua đúng luồng hủy (khóa dòng, chỉ hủy khi còn pending, đóng chợ đơn, hoàn voucher).
     * Trả true khi đã hủy và báo lỗi; false khi đơn không hủy được và trang cần báo "đã tạo" kèm cảnh báo.
     */
    private function cancelAfterFailedAssign(Order $order, string $message): bool
    {
        try {
            $cancel = app(OrderService::class)->cancelPendingOrder($order, 'admin_no_driver', 'Gán tay thất bại: '.$message, auth()->id());
        } catch (\Throwable $e) {
            report($e);
            $this->resultWarning = "Đơn #{$order->code} đã được tạo nhưng không gán được ({$message}) và cũng chưa hủy được. Đừng đặt lại — kiểm tra đơn ở khối \"Theo dõi đơn tổng đài\".";

            return false;
        }

        if (! $cancel['success']) {
            // Không còn pending: lỗi xảy ra sau khi gán đã commit (vd. Firebase), đơn thực ra đã có tài xế.
            $status = $order->fresh()?->status;
            $this->resultWarning = $status === 'assigned'
                ? "Đơn #{$order->code} đã gán cho tài xế nhưng hệ thống báo lỗi ({$message}). Kiểm tra tài xế đã thấy đơn chưa."
                : "Đơn #{$order->code} đã được tạo nhưng không gán được ({$message}). Đừng đặt lại — kiểm tra đơn ở khối \"Theo dõi đơn tổng đài\".";

            return false;
        }

        $this->resultError = "Không gán được cho tài xế đã chọn — {$message} Đơn #{$order->code} đã bị huỷ, vui lòng thử lại với tài xế khác.";
        Notification::make()
            ->title('Gán tay thất bại — đơn đã huỷ')
            ->body($message)
            ->danger()
            ->send();
        $this->dispatch('cc-recent-refresh');

        return true;
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

    /** Số đơn gần nhất của hôm nay hiện ở tab "Tất cả hôm nay". */
    private const RECENT_LIMIT = 20;

    /**
     * Đơn tổng đài của khu vực này, mới nhất trước: mọi đơn còn chờ tài xế (kể cả của hôm trước, để tab
     * "Cần xử lý" không bỏ sót) cộng RECENT_LIMIT đơn gần nhất hôm nay. Renderless: JS gọi định kỳ
     * mà không làm Livewire vẽ lại form (sẽ đè lên địa chỉ đang gõ dở).
     *
     * @return array<int, array>
     */
    #[Renderless]
    public function recentOrders(): array
    {
        $labels = ServiceType::pluck('label', 'key');
        $startOfDay = now()->startOfDay();
        $base = fn () => Order::query()
            ->where('city_id', $this->userCityId())
            ->where('platform', 'call_center');

        $pendingIds = $base()->where('status', 'pending')->latest('id')->limit(100)->pluck('id');
        $todayIds = $base()->where('created_at', '>=', $startOfDay)->latest('id')->limit(self::RECENT_LIMIT)->pluck('id');

        return Order::query()
            ->whereIn('id', $pendingIds->merge($todayIds)->unique())
            ->with(['driver:id,name,phone', 'sender:id,name,phone'])
            ->latest('id')
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'service' => $labels[$o->service_type] ?? $o->service_type,
                'status' => $o->status,
                'status_label' => self::STATUS_LABELS[$o->status] ?? $o->status,
                'minutes' => (int) $o->created_at->diffInMinutes(now()),
                'today' => $o->created_at->gte($startOfDay),
                'pickup' => trim((string) $o->pickup_address) ?: '—',
                'delivery' => trim((string) $o->delivery_address),
                'phone' => OrderListPresenter::phone(OrderListPresenter::contactPhone($o)),
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
            ->modalDescription('Đơn sẽ rời danh sách đơn mới và chuyển thẳng vào danh sách đã nhận của tài xế.')
            ->form(fn (array $arguments) => ($o = $this->recentOrder($arguments)) ? OrderResource::manualAssignmentForm($o) : [])
            ->action(function (array $arguments, array $data): void {
                OrderResource::assignDriverManually($this->recentOrder($arguments) ?? new Order, $data);
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
            ->modalDescription(fn (array $arguments) => ($o = $this->recentOrder($arguments)) && $o->status === 'processing'
                ? 'Đơn đã được tài xế bấm lấy hàng. Hủy sẽ chặn thanh toán freeship/bonus mưa khi hoàn thành; thao tác được lưu để đối soát.'
                : 'Lý do hủy được lưu lại để thống kê và đối soát.')
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
