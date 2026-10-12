<?php

namespace App\Filament\Resources\ShopDestinationPriceResource\Pages;

use App\Filament\Resources\ShopDestinationPriceResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageShopDestinationPrices extends ManageRecords
{
    protected static string $resource = ShopDestinationPriceResource::class;

    public function getHeading(): string
    {
        return 'Giá theo điểm đến';
    }

    public function getSubheading(): ?string
    {
        return 'Giá cố định cho đơn shop theo điểm giao. Điểm giao không thuộc điểm nào thì tính theo km như bình thường.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Thêm điểm đến')
                ->icon('heroicon-o-plus')
                ->mutateFormDataUsing(fn (array $data) => $data + ['city_id' => Filament::getTenant()?->id]),
        ];
    }
}
