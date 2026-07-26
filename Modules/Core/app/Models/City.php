<?php
namespace Modules\Core\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = ['name', 'slug', 'lat', 'lng', 'is_active', 'weekly_fee', 'is_rain_mode', 'rain_mode_started_at', 'rain_mode_by'];
    protected $casts = ['is_active' => 'boolean', 'weekly_fee' => 'integer', 'is_rain_mode' => 'boolean', 'rain_mode_started_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'driver');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'customer');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\Modules\Order\Models\Order::class);
    }
}
