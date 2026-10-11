<?php
namespace Modules\Order\Models;
use Illuminate\Database\Eloquent\Model;

class OrderDispatchLog extends Model
{
    public const CLOSE_ACCEPTED = 'accepted';
    public const CLOSE_DRIVER_DECLINED = 'driver_declined';
    public const CLOSE_DEADLINE_EXPIRED = 'deadline_expired';
    public const CLOSE_DEVICE_UNRECEIVED = 'device_unreceived';
    public const CLOSE_ANOTHER_DRIVER_ACCEPTED = 'another_driver_accepted';
    public const CLOSE_DELIVERY_FAILED = 'delivery_failed';
    public const CLOSE_DRIVER_INELIGIBLE = 'driver_ineligible';
    public const CLOSE_DRIVER_BUSY = 'driver_busy';
    public const CLOSE_ORDER_CANCELLED = 'order_cancelled';
    public const CLOSE_MANUAL_ASSIGNMENT = 'manual_assignment';
    public const CLOSE_DRIVER_OFFLINE = 'driver_offline';
    public const CLOSE_ACCOUNT_LOCKED = 'account_locked';
    public const CLOSE_STALE_RECOVERY = 'stale_recovery';

    protected $fillable = ['order_id', 'driver_id', 'offered_at', 'received_at', 'viewed_at', 'expires_at', 'responded_at', 'result', 'close_reason'];
    protected $casts    = ['offered_at' => 'datetime', 'received_at' => 'datetime', 'viewed_at' => 'datetime', 'expires_at' => 'datetime', 'responded_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
