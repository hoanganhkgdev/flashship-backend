<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DriverMapPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Vận hành';
    protected static ?string $navigationLabel = 'Bản đồ tài xế';
    protected static ?string $title           = 'Bản đồ tài xế — Theo dõi vị trí';
    protected static ?int    $navigationSort  = 11;

    protected static string $view = 'filament.pages.driver-map';

    public ?int   $cityId  = null;
    public array  $drivers = [];
    public array  $stats   = [];
    public array  $cities  = [];

    public function mount(): void
    {
        $this->cities = DB::table('cities')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name])
            ->toArray();

        $this->loadDrivers();
    }

    public function updatedCityId(): void
    {
        $this->loadDrivers();
    }

    public function loadDrivers(): void
    {
        $query = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($this->cityId) {
            $query->where('city_id', $this->cityId);
        }

        $this->drivers = $query
            ->select('id', 'name', 'phone', 'city_id', 'is_online', 'latitude', 'longitude', 'driver_score')
            ->get()
            ->map(fn($d) => [
                'id'           => $d->id,
                'name'         => $d->name ?? '',
                'phone'        => $d->phone ?? '',
                'is_online'    => (bool) $d->is_online,
                'lat'          => (float) $d->latitude,
                'lng'          => (float) $d->longitude,
                'driver_score' => (int) ($d->driver_score ?? 100),
            ])
            ->values()
            ->toArray();

        // Thống kê theo khu vực
        $rows = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('city_id', 'is_online', DB::raw('COUNT(*) as cnt'))
            ->groupBy('city_id', 'is_online')
            ->get();

        $cityNames = DB::table('cities')->pluck('name', 'id');
        $statsMap  = [];
        foreach ($rows as $row) {
            $n = $cityNames[$row->city_id] ?? '?';
            if (!isset($statsMap[$n])) $statsMap[$n] = ['online' => 0, 'offline' => 0, 'no_location' => 0];
            $statsMap[$n][$row->is_online ? 'online' : 'offline'] += $row->cnt;
        }

        // Đếm tài xế không có tọa độ
        $noLocation = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where(fn($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->select('city_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('city_id')
            ->get();
        foreach ($noLocation as $row) {
            $n = $cityNames[$row->city_id] ?? '?';
            if (!isset($statsMap[$n])) $statsMap[$n] = ['online' => 0, 'offline' => 0, 'no_location' => 0];
            $statsMap[$n]['no_location'] += $row->cnt;
        }

        $this->stats = $statsMap;

        $this->dispatch('driversUpdated', drivers: $this->drivers);
    }
}
