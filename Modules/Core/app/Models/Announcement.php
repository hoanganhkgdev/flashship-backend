<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Announcement extends Model
{
    protected $fillable = ['title', 'content', 'target', 'city_id', 'type', 'is_active', 'expires_at'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function city()
    {
        return $this->belongsTo(\Modules\Core\Models\City::class);
    }

    public function scopeActiveFor(Builder $query, string $target, ?int $cityId = null): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('target', [$target, 'all']))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId))
            ->orderByDesc('created_at');
    }
}
