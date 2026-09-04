<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Shop\Services\ShopPricingService;

class EditShopPricing extends EditRecord
{
    protected static string $resource = ShopPricingResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa bảng giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Thay đổi này ảnh hưởng đến các đơn cửa hàng được báo giá sau khi lưu.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Xoá bảng giá')
                ->visible(fn () => $this->record->city_id !== null),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ShopPricingResource::normalizeSlabs($data);
    }

    protected function getFormActions(): array
    {
        return array_merge(parent::getFormActions(), [
            Actions\Action::make('preview_fee')
                ->label('Tính thử phí')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->form([
                    TextInput::make('distance_km')
                        ->label('Quãng đường')
                        ->numeric()
                        ->minValue(0.1)
                        ->default(3)
                        ->required()
                        ->suffix('km'),
                    TextInput::make('weight_kg')
                        ->label('Trọng lượng')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->visible(fn () => $this->record->cargo_type === 'parcel')
                        ->suffix('kg'),
                ])
                ->action(function (array $data) {
                    $result = ShopPricingService::estimate(
                        $this->record->cargo_type,
                        (float) $data['distance_km'],
                        (float) ($data['weight_kg'] ?? 0),
                        $this->record->city_id,
                    );

                    $details = [number_format($result['base_fee']).'₫ phí giao'];
                    if ($result['weight_surcharge'] > 0) {
                        $details[] = number_format($result['weight_surcharge']).'₫ phụ phí cân nặng';
                    }
                    if ($result['night_surcharge'] > 0) {
                        $details[] = number_format($result['night_surcharge']).'₫ phụ phí đêm';
                    }

                    Notification::make()->success()
                        ->title('Tổng phí: '.number_format($result['fee']).'₫')
                        ->body(implode(' + ', $details))
                        ->send();
                }),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
