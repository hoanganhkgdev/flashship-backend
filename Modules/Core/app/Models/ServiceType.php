<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $fillable = [
        'key', 'label', 'icon_name', 'color_hex', 'bg_color_hex', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
