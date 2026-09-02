<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật trạng thái, phí duy trì và toạ độ khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Xoá khu vực')];
    }
}
