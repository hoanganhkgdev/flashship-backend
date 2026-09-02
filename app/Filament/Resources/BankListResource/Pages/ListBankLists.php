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
        return 'Quản lý ngân hàng khả dụng cho tài khoản nhận tiền và rút tiền.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm ngân hàng')->icon('heroicon-o-plus')];
    }
}
