<?php

namespace App\Filament\Resources\BankListResource\Pages;

use App\Filament\Resources\BankListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankLists extends ListRecords
{
    protected static string $resource = BankListResource::class;

    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
