<?php

namespace Modules\Core\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasRoles;
    use HasApiTokens, HasFactory, Notifiable;

    // ─── Role constants ────────────────────────────────────────────────────────
    const ROLE_ADMIN    = 'admin';
    const ROLE_SUBADMIN = 'subadmin';
    const ROLE_DRIVER   = 'driver';
    const ROLE_CUSTOMER = 'customer';
    const ROLE_SHOP     = 'shop';

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'address',
        'user_type',
        'city_id',
        'latitude',
        'longitude',
        'status',
        'uid',
        'profile_photo_path',
        'last_login_at',
        'player_id',          // Deprecated — kept for backward compatibility
        'fcm_token',          // Firebase Cloud Messaging token
        'last_notification_seen',
        'is_online',
        'shift_id',
        'has_car_license',
        'plan_id',
        'name_updated_at',
        'custom_commission_rate',
        'delete_requested_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'      => 'datetime',
            'password'               => 'hashed',
            'name_updated_at'        => 'datetime',
            'delete_requested_at'    => 'datetime',
            'custom_commission_rate' => 'float',
            'is_online'              => 'boolean',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function orders()
    {
        return $this->hasMany(\Modules\Order\Models\Order::class, 'delivery_man_id');
    }

    public function customerOrders()
    {
        return $this->hasMany(\Modules\Order\Models\Order::class, 'sender_platform_id')
            ->where('platform', 'customer_app');
    }

    public function shifts()
    {
        return $this->belongsToMany(Shift::class, 'shift_user', 'user_id', 'shift_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function bank()
    {
        return $this->hasOne(\Modules\Driver\Models\Bank::class);
    }

    public function wallet()
    {
        return $this->hasOne(\Modules\Driver\Models\DriverWallet::class, 'driver_id');
    }

    public function debts()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverDebt::class, 'driver_id');
    }

    public function customerAddresses()
    {
        return $this->hasMany(\Modules\Customer\Models\CustomerAddress::class);
    }

    public function driverLicenses()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverLicense::class, 'user_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeDrivers($query)
    {
        return $query->role(self::ROLE_DRIVER);
    }

    public function scopeAdmins($query)
    {
        return $query->role([self::ROLE_ADMIN, self::ROLE_SUBADMIN]);
    }

    // =========================================================================
    // SHIFT HELPERS
    // =========================================================================

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->user_type, ['admin', 'subadmin']);
    }

    public function isInShift(): bool
    {
        $cacheKey = "user_shift_{$this->id}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        [$result, $ttl] = $this->_computeIsInShift();
        Cache::put($cacheKey, $result, $ttl);
        return $result;
    }

    /** @return array{bool, int} [inShift, ttlSeconds] */
    private function _computeIsInShift(): array
    {
        // Commission, partner, free, or custom rate drivers → no shift restriction
        if (
            !$this->plan
            || $this->plan->type === Plan::TYPE_COMMISSION
            || $this->plan->type === Plan::TYPE_PARTNER
            || $this->plan->type === Plan::TYPE_FREE
            || $this->custom_commission_rate !== null
        ) {
            return [true, 120];
        }

        // Weekly plan with no shifts assigned → blocked
        if ($this->shifts->isEmpty()) {
            return [false, 30];
        }

        // Find the active shift and cache until it ends (max 120 s) for precision
        foreach ($this->shifts as $shift) {
            if ($shift->isNowInShift()) {
                $ttl = max(1, min(120, $shift->secondsUntilEnd()));
                return [true, $ttl];
            }
        }

        return [false, 30];
    }

    /**
     * Clear the driver's shift cache — call when admin changes their shifts.
     */
    public function clearShiftCache(): void
    {
        Cache::forget("user_shift_{$this->id}");
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getHasCarLicenseAttribute(): bool
    {
        return $this->driverLicenses()
            ->where('status', \Modules\Driver\Models\DriverLicense::STATUS_APPROVED)
            ->exists();
    }
}
