<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupportConfigs extends ListRecords
{
    protected static string $resource = SupportConfigResource::class;

    public function getHeading(): string
    {
        return 'Kênh hỗ trợ';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý hotline, Zalo, mạng xã hội và các kênh liên hệ hiển thị trên app.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm kênh hỗ trợ')->icon('heroicon-o-plus')];
    }
}
