<?php
namespace Modules\Order\Models;
use Illuminate\Database\Eloquent\Model;

class OrderDispatchLog extends Model
{
    protected $fillable = ['order_id', 'driver_id', 'offered_at', 'received_at', 'viewed_at', 'responded_at', 'result'];
    protected $casts    = ['offered_at' => 'datetime', 'received_at' => 'datetime', 'viewed_at' => 'datetime', 'responded_at' => 'datetime'];
}
