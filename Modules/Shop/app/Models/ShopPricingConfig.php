<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class ShopPricingConfig extends Model
{
    protected $table = 'shop_pricing_configs';

    protected $fillable = [
        'cargo_type',
        'city_id',
        'slabs',
        'over_max_per_km',
        'weight_per_kg',
        'is_active',
    ];

    protected $casts = [
        'slabs'          => 'array',
        'is_active'      => 'boolean',
        'over_max_per_km'=> 'integer',
        'weight_per_kg'  => 'integer',
    ];

    public function city()
    {
        return $this->belongsTo(\Modules\Core\Models\City::class);
    }

    public static function forCargo(string $cargoType, ?int $cityId = null): ?self
    {
        // Ưu tiên city cụ thể → fallback default (city_id = null)
        if ($cityId) {
            $cfg = static::where('cargo_type', $cargoType)
                ->where('city_id', $cityId)
                ->where('is_active', true)
                ->first();
            if ($cfg) return $cfg;
        }

        return static::where('cargo_type', $cargoType)
            ->whereNull('city_id')
            ->where('is_active', true)
            ->first();
    }
}
