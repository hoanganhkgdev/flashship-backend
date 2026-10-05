<?php

namespace App\Filament\Resources\BankListResource\Pages;

use App\Filament\Resources\BankListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankLists extends ListRecords
{
    protected static string $resource = BankListResource::class;

    public function getHeading(): string
    {
        return 'Danh sách ngân hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Ngân hàng tài xế có thể chọn để nhận tiền. Mã ngân hàng là số BIN 6 chữ số.';
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\BankListResource\Widgets\BankHealthWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm ngân hàng')->icon('heroicon-o-plus')];
    }
}
