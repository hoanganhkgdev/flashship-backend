<?php

namespace App\Filament\Resources\BankListResource\Pages;

use App\Filament\Resources\BankListResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankList extends CreateRecord
{
    protected static string $resource = BankListResource::class;

    public function getTitle(): string
    {
        return 'Thêm ngân hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Bổ sung ngân hàng mới vào danh mục tài xế có thể lựa chọn.';
    }
}
