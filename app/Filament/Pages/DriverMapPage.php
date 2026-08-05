<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DriverMapPage extends Page
{
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
            ->select('id', 'name', 'phone', 'city_id', 'is_online', 'driver_score', 'latitude', 'longitude', 'profile_photo_path');

        // city_manager chỉ thấy tài xế khu vực mình
        if ($this->fixedCityId) {
            $query->where('city_id', $this->fixedCityId);
        }

        // Đang đi đơn = có ít nhất 1 đơn ở trạng thái đang xử lý — cùng danh
        // sách trạng thái "active" dùng chung trong OrderService/DispatchService.
        $busyDriverIds = DB::table('orders')
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id')
            ->unique()
            ->flip();

        $meta = [];
        foreach ($query->get() as $d) {
            $meta[$d->id] = [
                'name'         => $d->name ?? '',
                'phone'        => $d->phone ?? '',
                'city_id'      => $d->city_id,
                'is_online'    => (bool) $d->is_online,
                'busy'         => $busyDriverIds->has($d->id),
                'driver_score' => (int) ($d->driver_score ?? 100),
                'lat'          => $d->latitude  ? (float) $d->latitude  : null,
                'lng'          => $d->longitude ? (float) $d->longitude : null,
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
