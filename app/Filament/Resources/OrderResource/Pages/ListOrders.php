<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getHeading(): string
    {
        return 'Quản lý đơn hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi và xử lý toàn bộ vòng đời đơn hàng trong khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createFromCallCenter')
                ->label('Tạo đơn mới')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => CallCenterPage::canAccess())
                ->url(CallCenterPage::getUrl()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'new';
    }

    public function getTabs(): array
    {
        return [
            'new' => Tab::make('Đơn mới')
                ->icon('heroicon-m-bell-alert')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'processing' => Tab::make('Đang xử lý')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing'])),

            'completed' => Tab::make('Hoàn thành')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),

            'cancelled' => Tab::make('Đã hủy')
                ->icon('heroicon-m-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}
