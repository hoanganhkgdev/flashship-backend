<?php

namespace App\Filament\Resources\DriverShiftChangeRequestResource\Pages;

use App\Filament\Resources\DriverShiftChangeRequestResource;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Driver\Models\DriverShiftChangeRequest;

class ListDriverShiftChangeRequests extends ListRecords
{
    protected static string $resource = DriverShiftChangeRequestResource::class;

    public function getHeading(): string
    {
        return 'Yêu cầu đổi ca';
    }

    public function getSubheading(): ?string
    {
        return 'Kiểm tra trùng giờ và phê duyệt ca làm việc mới cho tài xế.';
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $query = fn () => DriverShiftChangeRequestResource::scopeEloquentQueryToTenant(
            DriverShiftChangeRequest::query(),
            Filament::getTenant(),
        );

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($query()->where('status', 'pending')->count() ?: null)
                ->badgeColor('warning'),
            'approved' => Tab::make('Đã duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge($query()->where('status', 'approved')->count() ?: null)
                ->badgeColor('success'),
            'rejected' => Tab::make('Từ chối')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge($query()->where('status', 'rejected')->count() ?: null)
                ->badgeColor('danger'),
        ];
    }
}
