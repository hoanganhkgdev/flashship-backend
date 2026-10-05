<?php

namespace App\Filament\Resources\DriverScoreResource\Pages;

use App\Filament\Resources\DriverScoreResource;
use App\Services\DriverScoreReport;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;

class ViewDriverScore extends ViewRecord
{
    protected static string $resource = DriverScoreResource::class;

    protected static string $view = 'filament.resources.driver-score-resource.pages.view-driver-score';

    public function getTitle(): string
    {
        return 'Điểm: '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->phone ?? '—').' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            DriverScoreResource::resetAction(Actions\Action::make('reset_score'))
                ->after(fn () => $this->record->refresh()),

            Actions\Action::make('unsuspend')
                ->label('Gỡ suspend')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn () => $this->getRecord()->score_suspended_until
                    && Carbon::parse($this->getRecord()->score_suspended_until)->isFuture())
                ->requiresConfirmation()
                ->action(function () {
                    $this->getRecord()->update(['score_suspended_until' => null]);
                    Notification::make()->success()->title('Đã gỡ suspend.')->send();
                }),
        ];
    }

    /** Tổng hợp cho giao diện: điểm, xếp loại, biểu đồ 30 ngày, nguyên nhân, kết quả tuần. */
    public function getOverview(): array
    {
        $d = $this->record;
        $cityId = $d->city_id;
        $score = (int) ($d->driver_score ?? DriverScoreService::DEFAULT_SCORE);
        $max = max(1, DriverScoreService::maxScore($cityId));
        $outcome = (int) $d->status === 1 ? DriverScoreReport::projectedOutcome($score, $cityId) : null;

        $lastReset = DB::table('driver_score_logs')->where('driver_id', $d->id)
            ->whereIn('reason', DriverScoreReport::RESET_REASONS)->latest('id')->first();

        return [
            'score' => $score,
            'max' => $max,
            'pct' => max(0, min(100, round($score / $max * 100))),
            'rank' => DriverScoreService::label($score, $cityId),
            'color' => DriverScoreResource::scoreColor($score, $cityId),
            'bonusScore' => DriverScoreService::weeklyBonusScore($cityId),
            'penaltyScore' => DriverScoreService::weeklyPenaltyScore($cityId),
            'outcome' => $outcome,
            'outcomeAmount' => $outcome === 'bonus' ? DriverScoreService::weeklyBonusAmount($cityId)
                : ($outcome === 'penalty' ? DriverScoreService::weeklyPenaltyAmount($cityId) : 0),
            'streak' => (int) ($d->consecutive_completed ?? 0),
            'bonusToday' => ($d->daily_bonus_date === now()->toDateString() ? (int) ($d->daily_bonus_points ?? 0) : 0),
            'bonusCap' => DriverScoreService::dailyBonusCap($cityId),
            'series' => DriverScoreReport::scoreSeries($d->id, $score, 30),
            'breakdown' => DriverScoreReport::driverBreakdown($d->id, 30)->map(fn ($r) => [
                'label' => DriverScoreResource::reasonLabel($r->reason, $cityId),
                'net' => (int) $r->net,
                'times' => (int) $r->times,
            ]),
            'lastReset' => $lastReset,
            'suspendedUntil' => $d->score_suspended_until && Carbon::parse($d->score_suspended_until)->isFuture()
                ? Carbon::parse($d->score_suspended_until) : null,
            'status' => (int) $d->status,
        ];
    }
}
