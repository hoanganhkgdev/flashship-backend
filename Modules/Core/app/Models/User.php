<?php

namespace Modules\Core\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'cccd',
        'vehicle_type',
        'license_plate',
        'user_type',
        'city_id',
        'latitude',
        'longitude',
        'status',
        'uid',
        'profile_photo_path',
        'last_login_at',
        'player_id',          // Deprecated — kept for backward compatibility
        'fcm_token',
        'last_notification_seen',
        'is_online',
        'has_car_license',
        'driver_score',
        'consecutive_completed',
        'name_updated_at',
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
            'name_updated_at'     => 'datetime',
            'delete_requested_at' => 'datetime',
            'is_online'           => 'boolean',
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

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->user_type, ['admin', 'subadmin']);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getHasCarLicenseAttribute(): bool
    {
        if ($this->relationLoaded('driverLicenses')) {
            return $this->driverLicenses
                ->where('status', \Modules\Driver\Models\DriverLicense::STATUS_APPROVED)
                ->isNotEmpty();
        }

        return $this->driverLicenses()
            ->where('status', \Modules\Driver\Models\DriverLicense::STATUS_APPROVED)
            ->exists();
    }
}
