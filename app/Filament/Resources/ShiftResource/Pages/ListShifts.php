<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Shift;

class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;

    public function getHeading(): string
    {
        return 'Ca làm việc';
    }

    public function getSubheading(): ?string
    {
        return 'Khung giờ ca, độ phủ tài xế và tải đơn của từng ca.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm ca')->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\ShiftResource\Widgets\ShiftCoverageWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        $query = fn () => Shift::query()->where('city_id', Filament::getTenant()?->id);

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'active' => Tab::make('Đang kích hoạt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge($query()->where('is_active', true)->count() ?: null)
                ->badgeColor('success'),
            'current' => Tab::make('Đang trong giờ ca')
                ->modifyQueryUsing(fn (Builder $query) => ShiftResource::scopeCurrentShifts($query))
                ->badge(ShiftResource::scopeCurrentShifts($query())->count() ?: null)
                ->badgeColor('info'),
            'inactive' => Tab::make('Đã tắt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge($query()->where('is_active', false)->count() ?: null)
                ->badgeColor('gray'),
        ];
    }
}
