<?php

namespace Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PricingConfig extends Model
{
    protected $fillable = [
        'service_type', 'city_id', 'label',
        'base_km', 'base_fee', 'per_km_fee', 'min_fee',
        'is_active', 'config_json',
    ];

    protected $casts = [
        'city_id'     => 'integer',
        'base_km'     => 'integer',
        'base_fee'    => 'integer',
        'per_km_fee'  => 'integer',
        'min_fee'     => 'integer',
        'is_active'   => 'boolean',
        'config_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $m) {
            Cache::forget("pricing_{$m->service_type}_{$m->city_id}");
            Cache::forget("pricing_{$m->service_type}_global");
        });
        static::deleted(function (self $m) {
            Cache::forget("pricing_{$m->service_type}_{$m->city_id}");
            Cache::forget("pricing_{$m->service_type}_global");
        });
    }

    public function city()
    {
        return $this->belongsTo(\Modules\Core\Models\City::class);
    }

    // Ưu tiên giá khu vực → fallback global (city_id = null)
    public static function forService(string $serviceType, ?int $cityId = null): ?self
    {
        if ($cityId) {
            $cityKey = "pricing_{$serviceType}_{$cityId}";
            $city    = Cache::remember($cityKey, 300, fn() =>
                static::where('service_type', $serviceType)
                    ->where('city_id', $cityId)
                    ->where('is_active', true)
                    ->first()
            );
            if ($city) return $city;
        }

        return Cache::remember("pricing_{$serviceType}_global", 300, fn() =>
            static::where('service_type', $serviceType)
                ->whereNull('city_id')
                ->where('is_active', true)
                ->first()
        );
    }
}
