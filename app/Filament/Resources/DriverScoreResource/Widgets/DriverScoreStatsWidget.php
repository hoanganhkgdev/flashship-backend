<?php

namespace App\Filament\Resources\DriverScoreResource\Widgets;

use App\Filament\Resources\DriverScoreResource;
use App\Services\DriverScoreReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** KPI + hai bảng phân tích đầu trang Điểm tài xế. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class DriverScoreStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-score-resource.widgets.driver-score-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $p = DriverScoreReport::projection($cityId);

        $needsWork = DB::table('users')->where('user_type', 'driver')->where('status', 1)
            ->where('city_id', $cityId)
            ->whereRaw('COALESCE(driver_score, 100) <= ?', [$p['penaltyScore']])->count();

        $loss = DriverScoreReport::lossByReason($cityId, 30);

        return [
            'p' => $p,
            'needsWork' => $needsWork,
            'loss' => $loss->take(6)->map(fn ($r) => [
                'label' => DriverScoreResource::reasonLabel($r->reason, $cityId),
                'lost' => (int) $r->lost,
                'times' => (int) $r->times,
                'drivers' => (int) $r->drivers,
            ]),
            'lossTotal' => (int) $loss->sum('lost'),
            'history' => DriverScoreReport::settlementHistory($cityId, 6),
            'weekEnd' => Carbon::now()->endOfWeek(),
        ];
    }
}
