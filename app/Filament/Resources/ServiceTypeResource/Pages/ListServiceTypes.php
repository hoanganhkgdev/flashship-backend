<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;

class ListServiceTypes extends ListRecords
{
    protected static string $resource = ServiceTypeResource::class;

    public function getHeading(): string
    {
        return 'Loại dịch vụ';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý danh mục và thứ tự hiển thị dịch vụ trên ứng dụng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm dịch vụ')->icon('heroicon-o-plus'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tất cả')->badge(ServiceType::count() ?: null),
            'active' => Tab::make('Đang hiển thị')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(ServiceType::where('is_active', true)->count() ?: null)
                ->badgeColor('success'),
            'inactive' => Tab::make('Đã ẩn')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(ServiceType::where('is_active', false)->count() ?: null)
                ->badgeColor('gray'),
            'missing_pricing' => Tab::make('Thiếu bảng giá')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('pricingConfigs', fn (Builder $pricing) => $pricing->where('is_active', true)))
                ->badge(ServiceType::whereDoesntHave('pricingConfigs', fn (Builder $pricing) => $pricing->where('is_active', true))->count() ?: null)
                ->badgeColor('danger'),
        ];
    }
}
