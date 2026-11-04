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

        // Thưởng mưa nằm trong dữ liệu từng đơn trên danh sách đơn mới (Firebase). Bật/tắt mưa từ bất kỳ đâu
        // (nút điều khiển, lệnh tự tắt, trang khu vực) phải đẩy lại ngay, không chờ lượt đồng bộ 5 phút.
        static::updated(function (City $city) {
            if (! $city->wasChanged('is_rain_mode')) {
                return;
            }
            try {
                app(\Modules\Order\Services\OrderMarketService::class)->syncCity($city->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[RainMode] Không đồng bộ được danh sách đơn', ['city' => $city->id, 'error' => $e->getMessage()]);
            }
        });
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
