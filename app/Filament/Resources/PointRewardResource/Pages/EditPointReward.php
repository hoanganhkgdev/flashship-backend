<?php

namespace App\Filament\Resources\PointRewardResource\Pages;

use App\Filament\Resources\PointRewardResource;
use Filament\Resources\Pages\EditRecord;

class EditPointReward extends EditRecord
{
    protected static string $resource = PointRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
