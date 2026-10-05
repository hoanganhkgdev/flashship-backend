<?php
namespace Modules\Core\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = ['name', 'slug', 'lat', 'lng', 'is_active', 'is_test', 'weekly_fee', 'is_rain_mode', 'rain_mode_started_at', 'rain_mode_by'];
    protected $casts = ['is_active' => 'boolean', 'is_test' => 'boolean', 'weekly_fee' => 'integer', 'is_rain_mode' => 'boolean', 'rain_mode_started_at' => 'datetime'];

    protected static function booted(): void
    {
        // Khu vực mới có ngay bộ cấu hình vận hành riêng, độc lập với khu vực khác.
        static::created(fn (City $city) => \Modules\Core\Services\OperationalSettings::initializeCity($city->id));
    }

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }

    /** Khu vực hiển thị cho người dùng thật trong app: đang bật và không phải khu vực thử. */
    public function scopePublic(Builder $q): Builder { return $q->where('is_active', true)->where('is_test', false); }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'driver');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'customer');
    }

    public function shops(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'shop');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\Modules\Order\Models\Order::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
