<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DriverMapPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Bản đồ tài xế';
    protected static ?string $title           = ' ';

    public function getHeading(): string { return ''; }
    protected static ?int    $navigationSort  = 11;

    protected static string $view = 'filament.pages.driver-map';

    public array $cities      = [];
    public array $driversMeta = [];

    public function mount(): void
    {
        $this->cities = DB::table('cities')->orderBy('name')
            ->get(['id', 'name', 'lat', 'lng'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'lat' => (float) $c->lat, 'lng' => (float) $c->lng])
            ->toArray();

        $this->loadDriversMeta();
    }

    public function loadDriversMeta(): void
    {
        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'name', 'phone', 'city_id', 'is_online', 'driver_score', 'latitude', 'longitude', 'profile_photo_path')
            ->get();

        $meta = [];
        foreach ($drivers as $d) {
            $meta[$d->id] = [
                'name'         => $d->name ?? '',
                'phone'        => $d->phone ?? '',
                'city_id'      => $d->city_id,
                'is_online'    => (bool) $d->is_online,
                'driver_score' => (int) ($d->driver_score ?? 100),
                'lat'          => $d->latitude  ? (float) $d->latitude  : null,
                'lng'          => $d->longitude ? (float) $d->longitude : null,
                'avatar'       => $d->profile_photo_path
                    ? \Illuminate\Support\Facades\Storage::url($d->profile_photo_path)
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
