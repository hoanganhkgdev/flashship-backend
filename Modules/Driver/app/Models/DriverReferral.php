<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

class DriverReferral extends Model
{
    public const PENDING = 'pending';

    public const REWARDED = 'rewarded';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'driver_id', 'shop_id', 'status', 'reward_amount', 'required_orders',
        'qualified_at', 'rewarded_at', 'reject_reason',
    ];

    protected $casts = [
        'qualified_at' => 'datetime',
        'rewarded_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function shop()
    {
        return $this->belongsTo(User::class, 'shop_id');
    }
}
