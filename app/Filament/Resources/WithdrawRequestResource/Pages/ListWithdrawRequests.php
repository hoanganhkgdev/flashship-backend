<?php

namespace App\Filament\Resources\WithdrawRequestResource\Pages;

use App\Filament\Resources\WithdrawRequestResource;
use App\Filament\Resources\WithdrawRequestResource\Widgets\WithdrawStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
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
        return 'Kiểm tra tài khoản nhận, chuyển tiền và báo kết quả cho tài xế.';
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [WithdrawStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        $base = fn () => WithdrawRequestResource::scopeEloquentQueryToTenant(WithdrawRequest::query(), Filament::getTenant());
        $tab = fn (string $label, ?string $status, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn ($query) => $status ? $query->where('status', $status) : $query)
            ->badge(($status ? $base()->where('status', $status) : $base())->count() ?: null)
            ->badgeColor($color);

        return [
            'pending' => $tab('Chờ duyệt', 'pending', 'warning'),
            'approved' => $tab('Đã duyệt', 'approved', 'success'),
            'rejected' => $tab('Từ chối', 'rejected', 'danger'),
            'all' => $tab('Tất cả', null),
        ];
    }
}
