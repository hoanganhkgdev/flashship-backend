<?php

namespace App\Filament\Resources\WithdrawRequestResource\Pages;

use App\Filament\Resources\WithdrawRequestResource;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Driver\Models\WithdrawRequest;

class ListWithdrawRequests extends ListRecords
{
    protected static string $resource = WithdrawRequestResource::class;

    public function getHeading(): string
    {
        return 'Yêu cầu rút tiền';
    }

    public function getSubheading(): ?string
    {
        return 'Kiểm tra thông tin ngân hàng và xử lý thanh toán cho tài xế.';
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
        $query = fn () => WithdrawRequestResource::scopeEloquentQueryToTenant(
            WithdrawRequest::query(),
            Filament::getTenant(),
        );

        return [
            'all' => Tab::make('Tất cả')
                ->badge($query()->count() ?: null),
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
