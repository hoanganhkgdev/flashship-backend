<?php
namespace Modules\Pricing\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PricingConfig extends Model
{
    protected $fillable = ['service_type', 'label', 'base_fee', 'per_km_fee', 'min_fee', 'is_active'];
    protected $casts = ['base_fee' => 'integer', 'per_km_fee' => 'integer', 'min_fee' => 'integer', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn($m) => Cache::forget("pricing_{$m->service_type}"));
    }

    public static function forService(string $serviceType): ?self
    {
        return Cache::remember("pricing_{$serviceType}", 300, fn() =>
            static::where('service_type', $serviceType)->where('is_active', true)->first()
        );
    }
}
