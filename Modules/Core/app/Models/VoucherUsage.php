<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherUsage extends Model
{
    public $timestamps = false;

    protected $fillable = ['voucher_id', 'user_id', 'order_id', 'used_at'];

    protected $casts = ['used_at' => 'datetime'];

    public function voucher() { return $this->belongsTo(Voucher::class); }
    public function user()    { return $this->belongsTo(User::class); }
}
