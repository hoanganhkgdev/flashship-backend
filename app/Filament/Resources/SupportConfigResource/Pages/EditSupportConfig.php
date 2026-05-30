<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportConfig extends EditRecord
{
    protected static string $resource = SupportConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
