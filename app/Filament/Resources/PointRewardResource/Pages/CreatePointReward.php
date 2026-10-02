<?php

namespace App\Filament\Resources\PointRewardResource\Pages;

use App\Filament\Resources\PointRewardResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePointReward extends CreateRecord
{
    protected static string $resource = PointRewardResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
