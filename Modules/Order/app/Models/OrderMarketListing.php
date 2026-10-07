<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class OrderMarketListing extends Model
{
    protected $fillable = [
        'order_id', 'city_id', 'status', 'open_reason', 'offer_attempts',
        'opened_at', 'expires_at', 'claimed_at', 'claimed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'expires_at' => 'datetime', 'claimed_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
}
