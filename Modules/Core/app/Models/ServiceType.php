<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $fillable = [
        'key', 'label', 'icon_url', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function pricingConfigs()
    {
        return $this->hasMany(\Modules\Pricing\Models\PricingConfig::class, 'service_type', 'key');
    }

    public function orders()
    {
        return $this->hasMany(\Modules\Order\Models\Order::class, 'service_type', 'key');
    }
}
