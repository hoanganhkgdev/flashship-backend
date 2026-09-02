<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDriverDebts extends ListRecords
{
    protected static string $resource = DriverDebtResource::class;

    public function getHeading(): string
    {
        return 'Công nợ tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi kỳ đối soát, số tiền còn lại và trạng thái thanh toán.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo công nợ')->icon('heroicon-o-plus')];
    }
}
