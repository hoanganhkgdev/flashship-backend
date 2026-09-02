<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditVoucher extends EditRecord
{
    protected static string $resource = VoucherResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa mã '.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật ưu đãi, phạm vi và thời hạn của chiến dịch.';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['city_id'] = Filament::getTenant()?->getKey();

        if ($data['type'] === 'freeship') {
            $data['value'] = 0;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá mã giảm giá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
