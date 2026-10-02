<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

class ShopReferral extends Model
{
    public const PENDING = 'pending';

    public const REWARDED = 'rewarded';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'referrer_shop_id', 'referred_shop_id', 'status', 'points', 'required_orders',
        'welcome_voucher_id', 'qualified_at', 'rewarded_at', 'reject_reason',
    ];

    protected $casts = [
        'qualified_at' => 'datetime',
        'rewarded_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_shop_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_shop_id');
    }
}
