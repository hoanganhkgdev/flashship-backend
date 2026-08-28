<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Bản đồ tài xế';
    protected static ?string $title           = ' ';
    protected static ?int    $navigationSort  = 11;
    protected static string  $view            = 'filament.pages.driver-map';

    public function getHeading(): string { return ''; }

    public array $cities      = [];
    public array $driversMeta = [];
    public ?int  $fixedCityId = null; // khu vực (tenant) đang đứng

    public function mount(): void
    {
        $this->fixedCityId = \Filament\Facades\Filament::getTenant()?->id;

        $this->cities = DB::table('cities')
            ->when($this->fixedCityId, fn ($q) => $q->where('id', $this->fixedCityId))
            ->orderBy('name')
            ->get(['id', 'name', 'lat', 'lng'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'lat' => (float) $c->lat, 'lng' => (float) $c->lng])
            ->toArray();

        $this->loadDriversMeta();
    }

    public function loadDriversMeta(): void
    {
        $query = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'name', 'phone', 'city_id', 'driver_score', 'profile_photo_path');

        // city_manager chỉ thấy tài xế khu vực mình
        if ($this->fixedCityId) {
            $query->where('city_id', $this->fixedCityId);
        }

        // Đang đi đơn = có ít nhất 1 đơn ở trạng thái đang xử lý — cùng danh
        // sách trạng thái "active" dùng chung trong OrderService/DispatchService.
        $busyDriverIds = DB::table('orders')
            ->whereIn('status', ['assigned', 'processing'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id')
            ->unique()
            ->flip();

        // Chỉ giữ metadata quan hệ (tên/sđt/avatar/điểm) — is_online và toạ độ
        // đọc thẳng Firebase RTDB phía client (driver-map.blade.php), không
        // còn nguồn nào từ MySQL nữa.
        $meta = [];
        foreach ($query->get() as $d) {
            $meta[$d->id] = [
                'name'         => $d->name ?? '',
                'phone'        => $d->phone ?? '',
                'city_id'      => $d->city_id,
                'busy'         => $busyDriverIds->has($d->id),
                'driver_score' => (int) ($d->driver_score ?? 100),
                'avatar'       => $d->profile_photo_path
                    ? Storage::url($d->profile_photo_path)
                    : null,
            ];
        }
        $this->driversMeta = $meta;
        $this->dispatch('metaUpdated', meta: $meta);
    }

    public function getGoogleMapsKey(): string
    {
        return config('services.google_maps.api_key') ?? '';
    }

    public function getFirebaseConfig(): array
    {
        return [
            'apiKey'      => 'AIzaSyDSYWeYYO9oPK5I2HAkJ145eRp36WwnYaI',
            'projectId'   => 'flashship-app',
            'databaseURL' => config('services.firebase.database_url'),
        ];
    }
}
