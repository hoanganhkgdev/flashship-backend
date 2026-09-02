<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    public function getTitle(): string
    {
        return 'Thêm khu vực';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập khu vực phục vụ mới cho hệ thống.';
    }
}
