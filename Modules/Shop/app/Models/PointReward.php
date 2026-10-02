<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class PointReward extends Model
{
    protected $fillable = [
        'name', 'points_cost', 'type', 'value', 'min_order_value', 'max_discount',
        'valid_days', 'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('points_cost');
    }
}
