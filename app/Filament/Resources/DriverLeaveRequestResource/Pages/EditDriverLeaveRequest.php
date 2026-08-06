<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriverLeaveRequest extends EditRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
