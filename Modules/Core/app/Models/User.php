<?php

namespace Modules\Core\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants, HasDefaultTenant
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
        'cccd_image_path',
        'cccd_image_status',
        'vehicle_type',
        'license_plate',
        'user_type',
        'city_id',
        'latitude',
        'longitude',
        'status',
        'uid',
        'profile_photo_path',
        'avatar_updated_at',
        'last_login_at',
        'player_id',          // Deprecated — kept for backward compatibility
        'fcm_token',
        'last_notification_seen',
        'is_online',
        'online_since',
        'has_car_license',
        'driver_score',
        'consecutive_completed',
        'name_updated_at',
        'delete_requested_at',
        'last_location_at',
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
            'avatar_updated_at'   => 'datetime',
            'is_online'              => 'boolean',
            'online_since'           => 'datetime',
            'score_suspended_until'  => 'datetime',
            'last_location_at'       => 'datetime',
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

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

    public function shopOrders()
    {
        return $this->hasMany(\Modules\Order\Models\Order::class, 'sender_platform_id')
            ->where('platform', 'shop_app');
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

    /** Các ca làm việc tài xế đang đăng ký (ít nhất 1 ca, khoá cố định tới khi được duyệt đổi). */
    public function registeredShifts()
    {
        return $this->belongsToMany(Shift::class, 'shift_user', 'user_id', 'shift_id');
    }

    public function customerAddresses()
    {
        return $this->hasMany(\Modules\Customer\Models\CustomerAddress::class);
    }

    public function driverLicenses()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverLicense::class, 'user_id');
    }

    public function driverCccdImages()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverCccdImage::class, 'user_id');
    }

    public function scoreLogs()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverScoreLog::class, 'driver_id')->latest('created_at');
    }

    public function scoreSettlements()
    {
        return $this->hasMany(\Modules\Driver\Models\DriverScoreSettlement::class, 'driver_id')->latest('week_start');
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
        return in_array($this->user_type, ['admin', 'subadmin', 'city_manager', 'call_center']);
    }

    // ─── Filament Tenancy (khu vực quản lý) ────────────────────────────────
    // admin/subadmin quản lý xuyên suốt mọi thành phố; city_manager/call_center
    // chỉ quản lý đúng thành phố gán ở city_id.
    const TENANT_UNRESTRICTED_TYPES = ['admin', 'subadmin'];

    public function getTenants(Panel $panel): \Illuminate\Support\Collection
    {
        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return City::orderBy('name')->get();
        }

        return City::where('id', $this->city_id)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return true;
        }

        return $tenant->id === $this->city_id;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return City::orderBy('name')->first();
        }

        return City::where('id', $this->city_id)->first();
    }

    public function isCallCenter(): bool
    {
        return $this->user_type === 'call_center';
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
