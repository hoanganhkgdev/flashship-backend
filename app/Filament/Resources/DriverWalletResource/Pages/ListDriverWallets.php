<?php

namespace App\Filament\Resources\DriverWalletResource\Pages;

use App\Filament\Resources\DriverWalletResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverWallets extends ListRecords
{
    protected static string $resource = DriverWalletResource::class;

    public function getHeading(): string
    {
        return 'Ví tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi số dư và lịch sử biến động ví của tài xế trong khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
