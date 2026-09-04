<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverWallet extends Model
{
    protected $fillable = ['driver_id', 'balance'];
    protected $casts    = ['balance' => 'float'];

    public function driver()       { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
    public function transactions() { return $this->hasMany(DriverWalletTransaction::class, 'wallet_id'); }
    public function latestTransaction() { return $this->hasOne(DriverWalletTransaction::class, 'wallet_id')->latestOfMany(); }
}
