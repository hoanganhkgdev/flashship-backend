<?php
namespace Modules\Core\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class City extends Model
{
    protected $fillable = ['name', 'slug', 'lat', 'lng', 'is_active', 'weekly_fee'];
    protected $casts = ['is_active' => 'boolean', 'weekly_fee' => 'integer'];

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }

    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'driver');
    }
}
