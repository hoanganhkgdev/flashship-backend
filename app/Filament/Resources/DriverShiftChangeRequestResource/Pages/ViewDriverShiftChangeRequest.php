<?php

namespace App\Filament\Resources\DriverShiftChangeRequestResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverShiftChangeRequestResource;
use App\Services\ShiftChangeService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Modules\Driver\Models\DriverShiftChangeRequest;

class ViewDriverShiftChangeRequest extends ViewRecord
{
    protected static string $resource = DriverShiftChangeRequestResource::class;

    protected static string $view = 'filament.resources.driver-shift-change-request-resource.pages.view-shift-change';

    public function getTitle(): string
    {
        return 'Yêu cầu đổi ca #'.$this->record->id;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.($this->record->driver?->phone ?? '—');
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record->refresh()->load('driver.registeredShifts');

        return [
            DriverShiftChangeRequestResource::approveAction(Actions\Action::make('approve'))->after($refresh),
            DriverShiftChangeRequestResource::rejectAction(Actions\Action::make('reject'))->after($refresh),
            Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')->color('gray')
                ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
        ];
    }

    public function getOverview(): array
    {
        $r = $this->record->loadMissing('driver.registeredShifts', 'processor');

        return [
            'current' => ($r->driver?->registeredShifts ?? collect())->sortBy('start_time')->values(),
            'requested' => ShiftChangeService::requestedShifts($r),
            'flags' => ShiftChangeService::flags($r),
            'can' => $r->status === 'pending' ? ShiftChangeService::canApproveNow($r) : null,
            'impact' => $r->status === 'pending' ? ShiftChangeService::impact($r) : collect(),
            'history' => DriverShiftChangeRequest::where('driver_id', $r->driver_id)->whereKeyNot($r->id)->latest()->limit(8)->get(),
        ];
    }
}
