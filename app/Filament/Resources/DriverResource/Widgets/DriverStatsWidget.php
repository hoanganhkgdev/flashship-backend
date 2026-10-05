<?php

namespace App\Filament\Resources\DriverResource\Widgets;

use App\Filament\Resources\DriverResource;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** Dải chỉ số đầu trang Tài xế. Nằm ngoài app/Filament/Widgets để không bị tự thêm vào Tổng quan. */
class DriverStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-resource.widgets.driver-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Hồ sơ chờ duyệt quá bấy nhiêu ngày thì cảnh báo. */
    private const STALE_PENDING_DAYS = 14;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;

        // Trạng thái của giấy tờ MỚI NHẤT mỗi tài xế (tải lại giấy tờ thì bản cũ không còn tính).
        $latest = fn (string $table) => "(SELECT d.status FROM {$table} d WHERE d.user_id = users.id ORDER BY d.id DESC LIMIT 1)";

        $drivers = DB::table('users')->where('user_type', 'driver')->where('city_id', $cityId)
            ->selectRaw("users.id, users.status, users.driver_score, users.created_at, {$latest('driver_cccd_images')} as cccd, {$latest('driver_licenses')} as license")
            ->get();

        $working = DB::table('orders')->where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))
            ->whereIn('delivery_man_id', $drivers->pluck('id'))->distinct()->pluck('delivery_man_id');
        $statusById = $drivers->pluck('status', 'id');

        $pending = $drivers->where('status', 0);
        $oldest = $pending->isEmpty() ? 0 : (int) \Carbon\Carbon::parse($pending->min('created_at'))->diffInDays(now());

        return [
            'total' => $drivers->count(),
            'active' => $drivers->where('status', 1)->count(),
            'locked' => $drivers->where('status', 2)->count(),
            'working' => $working->count(),
            'workingLocked' => $working->filter(fn ($id) => ($statusById[$id] ?? null) == 2)->count(),
            'pending' => $pending->count(),
            'oldestPending' => $oldest,
            'stale' => $pending->filter(fn ($d) => \Carbon\Carbon::parse($d->created_at)->diffInDays(now()) > self::STALE_PENDING_DAYS)->count(),
            'staleDays' => self::STALE_PENDING_DAYS,
            'cccdPending' => $drivers->where('cccd', 'pending')->count(),
            'licensePending' => $drivers->where('license', 'pending')->count(),
            'lowScore' => $drivers->where('status', 1)->filter(fn ($d) => ($d->driver_score ?? 80) < DriverResource::LOW_SCORE)->count(),
            'lowThreshold' => DriverResource::LOW_SCORE,
        ];
    }
}
