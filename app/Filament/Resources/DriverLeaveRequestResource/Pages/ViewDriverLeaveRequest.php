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
        return null; // Tài xế đã nằm trong thẻ đầu trang.
    }

    protected function getHeaderActions(): array
    {
        return [
            DriverLeaveRequestResource::approveAction(Actions\Action::make('approve_leave'))
                ->after(fn () => $this->record->refresh()),
            DriverLeaveRequestResource::rejectAction(Actions\Action::make('reject_leave'))
                ->after(fn () => $this->record->refresh()),
            DriverLeaveRequestResource::cancelAction(Actions\Action::make('cancel_leave'))
                ->after(fn () => $this->redirect(DriverLeaveRequestResource::getUrl('index'))),
            Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')->color('gray')
                ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
        ];
    }

    public function getOverview(): array
    {
        $l = $this->record->loadMissing('driver.registeredShifts', 'creator', 'reviewer');
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
                'action' => $g->action,
                'label' => match ($g->action) {
                    'requested' => 'Tài xế xin nghỉ',
                    'approved' => 'Duyệt nghỉ',
                    'rejected' => 'Từ chối',
                    'cancelled' => 'Hủy ngày nghỉ',
                    'withdrawn' => 'Rút yêu cầu nghỉ',
                    default => 'Ghi nhận nghỉ',
                },
                'who' => $g->performed_by ? ($names[$g->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
                'note' => $g->note, 'when' => $g->created_at,
            ]),
        ];
    }
}
