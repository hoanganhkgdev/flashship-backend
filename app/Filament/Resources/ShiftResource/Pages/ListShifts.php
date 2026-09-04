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
        return 'Thiết lập khung giờ hoạt động và danh sách ca tài xế có thể đăng ký.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm ca')->icon('heroicon-o-plus'),
        ];
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
