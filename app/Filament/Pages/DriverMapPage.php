<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DriverMapPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Bản đồ tài xế';
    protected static ?string $title           = 'Bản đồ tài xế — Theo dõi real-time';
    protected static ?int    $navigationSort  = 11;

    protected static string $view = 'filament.pages.driver-map';

    public array $stats  = [];
    public array $cities = [];

    public function mount(): void
    {
        $this->cities = DB::table('cities')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name])
            ->toArray();

        $this->loadStats();
    }

    public function loadStats(): void
    {
        $rows = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('city_id', 'is_online', DB::raw('COUNT(*) as cnt'))
            ->groupBy('city_id', 'is_online')
            ->get();

        $cityNames = DB::table('cities')->pluck('name', 'id');
        $map = [];
        foreach ($rows as $row) {
            $n = $cityNames[$row->city_id] ?? '?';
            if (!isset($map[$n])) $map[$n] = ['online' => 0, 'offline' => 0];
            $map[$n][$row->is_online ? 'online' : 'offline'] += $row->cnt;
        }
        $this->stats = $map;
    }

    public function getGoogleMapsKey(): string
    {
        return config('services.google_maps.api_key', '');
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
