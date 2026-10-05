<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Order\Models\Order;

class DriverMapPage extends Page
{
    // Cho cả call_center xem — cần để hỗ trợ điều phối/CSKH theo dõi tài xế
    // trực tiếp. An toàn vì đã tự lọc đúng khu vực mình qua $fixedCityId
    // (tenant Filament theo city_id, xem User::getTenants()) + allIds phía
    // client chỉ lấy từ dbMeta đã lọc khu vực, không đọc thẳng toàn bộ RTDB.
    public static function canAccess(): bool
    {
        return true;
    }

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $navigationLabel = 'Bản đồ tài xế';

    protected static ?string $title = 'Bản đồ tài xế';

    protected static ?string $slug = 'ban-do-tai-xe';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.driver-map';

    public function getHeading(): string
    {
        return '';
    }

    public array $cities = [];

    public array $driversMeta = [];

    /** Đơn đang chờ tài xế có toạ độ điểm lấy, để hiện lên bản đồ. */
    public array $ordersMeta = [];

    public ?int $fixedCityId = null; // khu vực (tenant) đang đứng

    public function mount(): void
    {
        $this->fixedCityId = Filament::getTenant()?->id;

        $this->cities = DB::table('cities')
            ->when($this->fixedCityId, fn ($q) => $q->where('id', $this->fixedCityId))
            ->orderBy('name')
            ->get(['id', 'name', 'lat', 'lng'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'lat' => (float) $c->lat, 'lng' => (float) $c->lng])
            ->toArray();

        $this->loadDriversMeta();
    }

    public function loadDriversMeta(): void
    {
        $query = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'name', 'phone', 'city_id', 'driver_score', 'profile_photo_path', 'is_online');

        // city_manager chỉ thấy tài xế khu vực mình
        if ($this->fixedCityId) {
            $query->where('city_id', $this->fixedCityId);
        }

        // Đang đi đơn = có ít nhất 1 đơn ở trạng thái đang xử lý — cùng danh
        // sách trạng thái "active" dùng chung trong OrderService/DispatchService.
        $activeOrders = DB::table('orders')
            ->whereIn('status', ['assigned', 'processing'])
            ->whereIn('delivery_man_id', (clone $query)->pluck('id'))
            ->orderByDesc('id')
            ->get(['id', 'code', 'status', 'delivery_man_id'])
            ->unique('delivery_man_id')
            ->keyBy('delivery_man_id');

        $shiftNames = DB::table('shift_user as su')
            ->join('shifts as s', 's.id', '=', 'su.shift_id')
            ->whereIn('su.user_id', (clone $query)->pluck('id'))
            ->get(['su.user_id', 's.name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->implode(', '));

        // Chỉ giữ metadata quan hệ (tên/sđt/avatar/điểm) — is_online và toạ độ
        // đọc thẳng Firebase RTDB phía client (driver-map.blade.php), không
        // còn nguồn nào từ MySQL nữa.
        $meta = [];
        foreach ($query->get() as $d) {
            $activeOrder = $activeOrders->get($d->id);
            $meta[$d->id] = [
                'name' => $d->name ?? '',
                'phone' => $d->phone ?? '',
                'city_id' => $d->city_id,
                'is_online' => (bool) $d->is_online, // theo hệ thống: để phát hiện "bật online nhưng mất GPS"
                'url' => DriverResource::getUrl('view', ['record' => $d->id]),
                'order_url' => $activeOrder ? OrderResource::getUrl('view', ['record' => $activeOrder->id]) : null,
                'busy' => (bool) $activeOrder,
                'active_order_id' => $activeOrder?->id,
                'active_order_code' => $activeOrder?->code,
                'active_order_status' => $activeOrder?->status,
                'shift_names' => $shiftNames->get($d->id, ''),
                'driver_score' => (int) ($d->driver_score ?? 100),
                'avatar' => $d->profile_photo_path
                    ? Storage::url($d->profile_photo_path)
                    : null,
            ];
        }
        $this->driversMeta = $meta;
        $this->dispatch('metaUpdated', meta: $meta);

        $this->ordersMeta = $this->pendingOrdersMeta();
        $this->dispatch('ordersUpdated', orders: $this->ordersMeta);
    }

    /**
     * Đơn đang chờ tài xế có toạ độ điểm lấy — chờ lâu nhất trước. Gồm cả đơn hệ thống đã dừng tìm,
     * vì đó là những đơn cần gán tay gấp nhất.
     *
     * @return array<int, array>
     */
    public function pendingOrdersMeta(): array
    {
        return Order::query()
            ->where('status', 'pending')
            ->whereNotNull('pickup_lat')->whereNotNull('pickup_lng')
            ->when($this->fixedCityId, fn ($q) => $q->where('city_id', $this->fixedCityId))
            ->orderBy('created_at')->limit(50)
            ->get(['id', 'code', 'pickup_address', 'delivery_address', 'order_note', 'shipping_fee', 'pickup_lat', 'pickup_lng', 'created_at', 'dispatching_to_driver_id', 'cancel_reason'])
            ->map(fn (Order $o) => [
                'id' => $o->id, 'code' => $o->code, 'address' => $o->pickup_address, 'delivery' => $o->delivery_address,
                'note' => trim((string) $o->order_note), 'fee' => (int) $o->shipping_fee,
                'lat' => (float) $o->pickup_lat, 'lng' => (float) $o->pickup_lng,
                'minutes' => (int) $o->created_at->diffInMinutes(now()),
                'offered' => (bool) $o->dispatching_to_driver_id,
                'stopped' => $o->cancel_reason === 'no_driver',
                'url' => OrderResource::getUrl('view', ['record' => $o->id]),
            ])->all();
    }

    /** Tài xế thật sự gán được cho đơn này — để gợi ý trên bản đồ không đưa ra người bị loại khỏi hộp thoại gán. */
    public function eligibleDriverIds(int $orderId): array
    {
        $order = Order::where('city_id', $this->fixedCityId)->find($orderId);

        return $order ? array_map('intval', array_keys(OrderResource::manualAssignmentDriverOptions($order))) : [];
    }

    public function getGoogleMapsKey(): string
    {
        return config('services.google_maps.api_key') ?? '';
    }

    public function getFirebaseConfig(): array
    {
        return [
            'apiKey' => 'AIzaSyDSYWeYYO9oPK5I2HAkJ145eRp36WwnYaI',
            'projectId' => 'flashship-app',
            'databaseURL' => config('services.firebase.database_url'),
        ];
    }

    /** Gán tay tài xế cho đơn đang chờ ngay từ bản đồ. */
    public function assignAction(): Action
    {
        $order = fn (array $a) => Order::where('city_id', $this->fixedCityId)->find($a['order'] ?? 0);

        return Action::make('assign')
            ->label('Gán tài xế')
            ->icon('heroicon-o-user-plus')
            ->color('info')
            ->modalHeading(fn (array $arguments) => ($o = $order($arguments)) ? 'Gán tài xế cho đơn #'.$o->code : 'Gán tài xế')
            ->modalDescription('Đơn sẽ ngừng tìm tự động và chuyển thẳng vào danh sách đã nhận của tài xế.')
            ->form(fn (array $arguments) => ($o = $order($arguments)) ? OrderResource::manualAssignmentForm($o) : [])
            ->fillForm(function (array $arguments) use ($order): array {
                $driver = (int) ($arguments['driver'] ?? 0);

                return $driver && in_array($driver, $this->eligibleDriverIds((int) ($arguments['order'] ?? 0)), true) ? ['driver_id' => $driver] : [];
            })
            ->action(function (array $arguments, array $data) use ($order): void {
                OrderResource::assignDriverManually($order($arguments) ?? new Order(), $data);
                $this->loadDriversMeta();
            });
    }
}
