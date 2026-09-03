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
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Modules\Customer\Models\CustomerAddress;
use Modules\Driver\Models\Bank;
use Modules\Driver\Models\DriverCccdImage;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Models\DriverLicense;
use Modules\Driver\Models\DriverScoreLog;
use Modules\Driver\Models\DriverScoreSettlement;
use Modules\Driver\Models\DriverWallet;
use Modules\Order\Models\Order;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;

    // ─── Role constants ────────────────────────────────────────────────────────
    const ROLE_ADMIN = 'admin';

    const ROLE_SUBADMIN = 'subadmin';

    const ROLE_DRIVER = 'driver';

    const ROLE_CUSTOMER = 'customer';

    const ROLE_SHOP = 'shop';

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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'name_updated_at' => 'datetime',
            'delete_requested_at' => 'datetime',
            'avatar_updated_at' => 'datetime',
            'is_online' => 'boolean',
            'online_since' => 'datetime',
            'score_suspended_until' => 'datetime',
            'last_location_at' => 'datetime',
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
        return $this->hasMany(Order::class, 'delivery_man_id');
    }

    public function customerOrders()
    {
        return $this->hasMany(Order::class, 'sender_platform_id')
            ->where('platform', 'customer_app');
    }

    public function shopOrders()
    {
        return $this->hasMany(Order::class, 'sender_platform_id')
            ->where('platform', 'shop_app');
    }

    public function bank()
    {
        return $this->hasOne(Bank::class);
    }

    public function wallet()
    {
        return $this->hasOne(DriverWallet::class, 'driver_id');
    }

    public function debts()
    {
        return $this->hasMany(DriverDebt::class, 'driver_id');
    }

    /** Các ca làm việc tài xế đang đăng ký (ít nhất 1 ca, khoá cố định tới khi được duyệt đổi). */
    public function registeredShifts()
    {
        return $this->belongsToMany(Shift::class, 'shift_user', 'user_id', 'shift_id')
            ->withTimestamps();
    }

    public function customerAddresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function driverLicenses()
    {
        return $this->hasMany(DriverLicense::class, 'user_id');
    }

    public function driverCccdImages()
    {
        return $this->hasMany(DriverCccdImage::class, 'user_id');
    }

    public function scoreLogs()
    {
        return $this->hasMany(DriverScoreLog::class, 'driver_id')->latest('created_at');
    }

    public function scoreSettlements()
    {
        return $this->hasMany(DriverScoreSettlement::class, 'driver_id')->latest('week_start');
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

    public function getTenants(Panel $panel): Collection
    {
        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return City::active()->orderBy('name')->get();
        }

        return City::active()->whereKey($this->city_id)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof City || ! $tenant->is_active) {
            return false;
        }

        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return true;
        }

        return (int) $tenant->getKey() === (int) $this->city_id;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        if (in_array($this->user_type, self::TENANT_UNRESTRICTED_TYPES)) {
            return City::active()->orderBy('name')->first();
        }

        return City::active()->whereKey($this->city_id)->first();
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
                ->where('status', DriverLicense::STATUS_APPROVED)
                ->isNotEmpty();
        }

        return $this->driverLicenses()
            ->where('status', DriverLicense::STATUS_APPROVED)
            ->exists();
    }
}
