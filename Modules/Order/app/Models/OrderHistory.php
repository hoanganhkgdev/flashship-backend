<?php
namespace Modules\Order\Models;
use Illuminate\Database\Eloquent\Model;

class OrderHistory extends Model
{
    protected $fillable = ['order_id', 'user_id', 'type', 'description', 'metadata'];
    protected $casts    = ['metadata' => 'array'];

    public function order() { return $this->belongsTo(Order::class); }
}
