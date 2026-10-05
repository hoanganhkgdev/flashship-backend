<?php

namespace App\Filament\Resources\AdminUserResource\Pages;

use App\Filament\Resources\AdminUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdminUsers extends ListRecords
{
    protected static string $resource = AdminUserResource::class;

    public function getHeading(): string
    {
        return 'Tài khoản quản trị';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý vai trò, khu vực phụ trách và trạng thái truy cập backend.';
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\AdminUserResource\Widgets\AdminOverviewWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Thêm quản trị viên')->icon('heroicon-o-user-plus')];
    }
}
