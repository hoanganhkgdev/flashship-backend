<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\ShiftResource;
use App\Services\ShiftService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;

class ViewShift extends ViewRecord
{
    protected static string $resource = ShiftResource::class;

    protected static string $view = 'filament.resources.shift-resource.pages.view-shift';

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return substr($this->record->start_time, 0, 5).' → '.substr($this->record->end_time, 0, 5).' · '.ShiftResource::scheduleDescription($this->record);
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record->refresh();

        return [
            ShiftResource::editAction(Actions\Action::make('edit_time')),
            ShiftResource::toggleAction(Actions\Action::make('toggle'))->after($refresh),
        ];
    }

    public function getOverview(): array
    {
        $cov = ShiftService::coverage((int) $this->record->city_id);
        $row = $cov['rows']->first(fn ($r) => $r['shift']->id === $this->record->id);

        return [
            'row' => $row,
            'hours' => ShiftService::hourlyOrders($this->record),
            'roster' => ShiftService::roster($this->record)->map(fn ($u) => [
                'name' => $u->name, 'phone' => $u->phone, 'status' => (int) $u->status, 'online' => (bool) $u->is_online,
                'url' => DriverResource::getUrl('view', ['record' => $u->id]),
            ]),
            'logs' => $this->logs(),
            'overload' => ShiftService::OVERLOAD_PER_DRIVER,
        ];
    }

    private function logs(): Collection
    {
        $labels = ['created' => 'Tạo ca', 'time_changed' => 'Đổi giờ ca', 'activated' => 'Bật ca', 'deactivated' => 'Tắt ca', 'deleted' => 'Xóa ca'];
        $logs = $this->record->logs()->limit(15)->get();
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return $logs->map(fn ($l) => [
            'label' => $labels[$l->action] ?? $l->action,
            'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
            'detail' => $l->detail,
            'when' => $l->created_at,
        ]);
    }
}
