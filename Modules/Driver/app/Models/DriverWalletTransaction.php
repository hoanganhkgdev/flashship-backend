<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverWalletTransaction extends Model
{
    protected $fillable = ['wallet_id', 'type', 'amount', 'description', 'reference'];
    protected $casts    = ['amount' => 'float'];

    public function wallet() { return $this->belongsTo(DriverWallet::class); }
}
