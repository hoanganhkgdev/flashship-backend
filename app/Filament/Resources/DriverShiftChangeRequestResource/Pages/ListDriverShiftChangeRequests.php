<?php

namespace App\Filament\Resources\DriverShiftChangeRequestResource\Pages;

use App\Filament\Resources\DriverShiftChangeRequestResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

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
        return [
            'all' => Tab::make('Tất cả'),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'approved' => Tab::make('Đã duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make('Từ chối')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }
}
