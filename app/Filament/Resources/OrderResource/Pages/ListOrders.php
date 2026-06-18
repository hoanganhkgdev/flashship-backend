<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getHeading(): string { return ''; }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'processing';
    }

    public function getTabs(): array
    {
        return [
            'processing' => Tab::make('Đang xử lý')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereNotIn('status', ['completed', 'cancelled'])),

            'completed' => Tab::make('Hoàn thành')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'completed')),

            'cancelled' => Tab::make('Đã hủy')
                ->icon('heroicon-m-x-circle')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'cancelled')),
        ];
    }
}
