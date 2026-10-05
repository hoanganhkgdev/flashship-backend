<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use App\Filament\Resources\DriverResource;
use App\Services\DriverLeaveService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Order\Models\Order;

class ViewDriverLeaveRequest extends ViewRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    protected static string $view = 'filament.resources.driver-leave-request-resource.pages.view-leave';

    public function getTitle(): string
    {
        return 'Nghỉ phép '.$this->record->leave_date->format('d/m/Y');
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.($this->record->driver?->phone ?? '—');
    }

    protected function getHeaderActions(): array
    {
        return [
            DriverLeaveRequestResource::cancelAction(Actions\Action::make('cancel_leave'))
                ->after(fn () => $this->redirect(DriverLeaveRequestResource::getUrl('index'))),
            Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')->color('gray')
                ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
        ];
    }

    public function getOverview(): array
    {
        $l = $this->record->loadMissing('driver.registeredShifts', 'creator');
        $orders = Order::where('delivery_man_id', $l->driver_id)->where('status', 'completed')->whereDate('completed_at', $l->leave_date);
        $logs = DriverLeaveService::logs($l->driver_id, $l->leave_date);
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return [
            'flags' => DriverLeaveService::flags($l),
            'shifts' => ($l->driver?->registeredShifts ?? collect())->sortBy('start_time')->values(),
            'orders' => (clone $orders)->count(),
            'ordersBefore' => (clone $orders)->where('completed_at', '<=', $l->created_at)->count(),
            'history' => DriverLeaveRequest::where('driver_id', $l->driver_id)->where('leave_date', '>=', today()->subDays(90))->orderByDesc('leave_date')->get(),
            'logs' => $logs->map(fn ($g) => [
                'label' => $g->action === 'created' ? 'Ghi nhận nghỉ' : 'Hủy phiếu nghỉ',
                'who' => $g->performed_by ? ($names[$g->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
                'note' => $g->note, 'when' => $g->created_at,
            ]),
        ];
    }
}
