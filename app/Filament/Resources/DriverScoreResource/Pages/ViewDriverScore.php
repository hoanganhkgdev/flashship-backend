<?php

namespace App\Filament\Resources\DriverScoreResource\Pages;

use App\Filament\Resources\DriverScoreResource;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Driver\Services\DriverScoreService;

class ViewDriverScore extends ViewRecord
{
    protected static string $resource = DriverScoreResource::class;

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
            Actions\Action::make('reset_score')
                ->label('Reset điểm')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reset điểm về '.DriverScoreService::DEFAULT_SCORE)
                ->action(function () {
                    $record = $this->getRecord();
                    DriverScoreService::resetToDefault($record->id);
                    $this->refreshFormData(['driver_score', 'score_suspended_until']);
                    Notification::make()->success()
                        ->title('Đã reset điểm về '.DriverScoreService::DEFAULT_SCORE)
                        ->send();
                }),

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
}
