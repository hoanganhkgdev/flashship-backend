<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Driver\Models\DriverLeaveRequest;

class ListDriverLeaveRequests extends ListRecords
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getHeading(): string
    {
        return 'Lịch nghỉ tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Ngày nghỉ đã báo trước, ảnh hưởng của chúng lên từng ca và nhật ký ghi nhận.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Ghi nhận nghỉ phép')->icon('heroicon-o-plus'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $hasUpcoming = DriverLeaveRequestResource::scopeEloquentQueryToTenant(
            DriverLeaveRequest::query(),
            Filament::getTenant(),
        )->whereDate('leave_date', '>=', today())->exists();

        return $hasUpcoming ? 'upcoming' : 'past';
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\DriverLeaveRequestResource\Widgets\LeaveImpactWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        $query = fn () => DriverLeaveRequestResource::scopeEloquentQueryToTenant(
            DriverLeaveRequest::query(),
            Filament::getTenant(),
        );

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'today' => Tab::make('Nghỉ hôm nay')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('leave_date', today()))
                ->badge($query()->whereDate('leave_date', today())->count() ?: null)
                ->badgeColor('warning'),
            'upcoming' => Tab::make('Hiện tại & sắp tới')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('leave_date', '>=', today()))
                ->badge($query()->whereDate('leave_date', '>=', today())->count() ?: null)
                ->badgeColor('info'),
            'past' => Tab::make('Đã kết thúc')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('leave_date', '<', today()))
                ->badge($query()->whereDate('leave_date', '<', today())->count() ?: null)
                ->badgeColor('gray'),
        ];
    }
}
