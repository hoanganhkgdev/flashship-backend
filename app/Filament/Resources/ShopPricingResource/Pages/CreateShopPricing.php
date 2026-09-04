<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Shop\Models\ShopPricingConfig;

class CreateShopPricing extends CreateRecord
{
    protected static string $resource = ShopPricingResource::class;

    public function getTitle(): string
    {
        return 'Thêm bảng giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập các ngưỡng giá và phụ phí cho nhóm hàng.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament (City::shopPricingConfigs()
     * không tồn tại — city_id=null nghĩa là bảng giá mặc định, form tự chọn,
     * không nên bị ép theo tenant đang đứng).
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();

        return $record;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $duplicate = ShopPricingConfig::where('cargo_type', $data['cargo_type'])
            ->when(
                $data['city_id'] ?? null,
                fn ($query, $cityId) => $query->where('city_id', $cityId),
                fn ($query) => $query->whereNull('city_id'),
            )
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'data.cargo_type' => 'Loại hàng này đã có bảng giá trong phạm vi đã chọn.',
            ]);
        }

        return ShopPricingResource::normalizeSlabs($data);
    }
}
