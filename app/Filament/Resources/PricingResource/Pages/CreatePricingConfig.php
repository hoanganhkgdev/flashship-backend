<?php

namespace App\Filament\Resources\PricingResource\Pages;

use App\Filament\Resources\PricingResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingConfig extends CreateRecord
{
    protected static string $resource = PricingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Nếu không có config_json, copy từ bảng giá global cùng service_type
        if (empty($data['config_json']) && !empty($data['service_type'])) {
            $global = \Modules\Pricing\Models\PricingConfig::where('service_type', $data['service_type'])
                ->whereNull('city_id')
                ->first();

            if ($global) {
                $data['config_json'] = $global->config_json;
                $data['base_km']     = $global->base_km;
                $data['base_fee']    = $global->base_fee;
                $data['per_km_fee']  = $global->per_km_fee;
                $data['min_fee']     = $global->min_fee;
            }
        }

        return $data;
    }
}
