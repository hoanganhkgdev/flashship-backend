<?php

namespace App\Filament\Resources\BankListResource\Pages;

use App\Filament\Resources\BankListResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBankList extends EditRecord
{
    protected static string $resource = BankListResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('Xoá ngân hàng')];
    }
}
