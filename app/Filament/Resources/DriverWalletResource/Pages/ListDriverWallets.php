<?php

namespace App\Filament\Resources\DriverWalletResource\Pages;

use App\Filament\Resources\DriverWalletResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverWallets extends ListRecords
{
    protected static string $resource = DriverWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
