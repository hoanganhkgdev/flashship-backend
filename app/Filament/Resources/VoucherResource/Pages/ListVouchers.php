<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tạo mã mới'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tất cả'),
            'active' => Tab::make('Đang hoạt động')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()))
                    ->where(fn (Builder $query) => $query
                        ->whereNull('usage_limit')
                        ->orWhereColumn('used_count', '<', 'usage_limit'))),
            'full' => Tab::make('Hết lượt')
                ->icon('heroicon-m-user-group')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('usage_limit')
                    ->whereColumn('used_count', '>=', 'usage_limit')),
            'expired' => Tab::make('Hết hạn')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())),
            'inactive' => Tab::make('Đã tắt')
                ->icon('heroicon-m-pause-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }
}
