<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'description',
        'min_order_value', 'max_discount', 'service_types',
        'city_id', 'expires_at', 'usage_limit', 'per_user_limit', 'used_count', 'is_active',
    ];

    protected $casts = [
        'service_types' => 'array',
        'expires_at'    => 'datetime',
        'is_active'     => 'boolean',
    ];

    public function scopeAvailable($query)
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'));
    }

    public function scopeForCity($query, ?int $cityId)
    {
        if ($cityId === null) return $query;
        return $query->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId));
    }

    public function usages() { return $this->hasMany(VoucherUsage::class); }

    public function usageCountByUser(int $userId): int
    {
        return $this->usages()->where('user_id', $userId)->count();
    }

    public function getDiscountLabelAttribute(): string
    {
        return match ($this->type) {
            'percent'  => "-{$this->value}%",
            'fixed'    => '-' . number_format($this->value) . 'đ',
            'freeship' => 'Freeship',
            default    => '-',
        };
    }
}
