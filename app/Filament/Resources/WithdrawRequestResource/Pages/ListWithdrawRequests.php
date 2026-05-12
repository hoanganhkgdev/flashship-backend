<?php

namespace App\Filament\Resources\WithdrawRequestResource\Pages;

use App\Filament\Resources\WithdrawRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWithdrawRequests extends ListRecords
{
    protected static string $resource = WithdrawRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tất cả'),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge(\Modules\Driver\Models\WithdrawRequest::where('status', 'pending')->count())
                ->badgeColor('warning'),
            'approved' => Tab::make('Đã duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make('Từ chối')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }
}
