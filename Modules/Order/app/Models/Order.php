<?php
namespace Modules\Order\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function ($order) {
            if (empty($order->status) || $order->status === 'draft') $order->status = 'pending';
            $order->bonus_fee    = $order->bonus_fee ?? 0;
            $order->shipping_fee = $order->shipping_fee ?? 0;
            $order->is_freeship  = $order->is_freeship ?? false;
        });
        static::created(function ($order) {
            $order->updateQuietly(['code' => (string) $order->id]);
        });
        static::saving(function ($order) {
            $order->bonus_fee    = $order->bonus_fee ?? 0;
            $order->shipping_fee = $order->shipping_fee ?? 0;
            $order->is_freeship  = $order->is_freeship ?? false;
        });
    }

    protected $fillable = [
        'code', 'service_type', 'city_id', 'delivery_man_id', 'dispatching_to_driver_id',
        'dispatch_attempts', 'sender_platform_id', 'platform', 'created_by', 'status', 'cancel_reason',
        'pickup_address', 'pickup_place_name', 'pickup_lat', 'pickup_lng', 'pickup_phone', 'sender_name', 'store_name',
        'delivery_address', 'delivery_place_name', 'delivery_lat', 'delivery_lng', 'delivery_phone', 'receiver_name',
        'shipping_fee', 'bonus_fee', 'night_surcharge', 'is_freeship', 'rain_bonus_eligible', 'rain_bonus_amount', 'distance', 'payment_method', 'cod_amount',
        'order_note', 'cargo_type', 'cargo_note', 'cargo_weight',
        'is_batch', 'stops', 'shop_service_type',
        'voucher_code', 'discount_amount',
        'cancel_note', 'cancelled_by', 'cancelled_at', 'fee_note', 'driver_rating', 'driver_rating_note', 'rated_at', 'rating_handled_at', 'rating_handled_by', 'rating_handle_note', 'rating_hidden', 'completed_at', 'delivered_at',
        'delivery_arrived_at', 'auto_completed_at',
    ];

    protected $casts = [
        'delivery_man_id'          => 'integer',
        'dispatching_to_driver_id' => 'integer',
        'city_id'                  => 'integer',
        'is_freeship'              => 'boolean',
        'rain_bonus_eligible'      => 'boolean',
        'rain_bonus_amount'        => 'integer',
        'is_batch'                 => 'boolean',
        'stops'                    => 'array',
        'scheduled_at'             => 'datetime',
        'dispatch_started_at'      => 'datetime',
        'offer_viewed_at'          => 'datetime',
        'completed_at'             => 'datetime',
        'cancelled_at'             => 'datetime',
        'rated_at'                 => 'datetime',
        'rating_handled_at'        => 'datetime',
        'rating_hidden'            => 'boolean',
        'delivery_arrived_at'      => 'datetime',
        'auto_completed_at'        => 'datetime',
        'delivered_at'             => 'datetime',
        'pickup_lat'               => 'float',
        'pickup_lng'               => 'float',
        'delivery_lat'             => 'float',
        'delivery_lng'             => 'float',
    ];

    public function driver()   { return $this->belongsTo(\Modules\Core\Models\User::class, 'delivery_man_id'); }
    public function sender()   { return $this->belongsTo(\Modules\Core\Models\User::class, 'sender_platform_id'); }
    public function creator()  { return $this->belongsTo(\Modules\Core\Models\User::class, 'created_by'); }
    public function city()     { return $this->belongsTo(\Modules\Core\Models\City::class); }
    public function histories(){ return $this->hasMany(OrderHistory::class)->latest(); }
    public function marketListing(){ return $this->hasOne(OrderMarketListing::class); }

    protected $appends = ['city_name'];
    public function getCityNameAttribute() { return $this->city?->name ?? 'N/A'; }
}
