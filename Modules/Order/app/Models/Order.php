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
        'shipping_fee', 'bonus_fee', 'night_surcharge', 'is_freeship', 'rain_bonus_eligible', 'distance', 'payment_method', 'cod_amount',
        'order_note', 'cargo_type', 'cargo_note', 'cargo_weight',
        'is_batch', 'stops', 'shop_service_type',
        'voucher_code', 'discount_amount',
        'driver_rating', 'driver_rating_note', 'scheduled_at', 'completed_at', 'delivered_at',
    ];

    protected $casts = [
        'delivery_man_id'          => 'integer',
        'dispatching_to_driver_id' => 'integer',
        'city_id'                  => 'integer',
        'is_freeship'              => 'boolean',
        'rain_bonus_eligible'      => 'boolean',
        'is_batch'                 => 'boolean',
        'stops'                    => 'array',
        'completed_at'             => 'datetime',
        'delivered_at'             => 'datetime',
        'scheduled_at'             => 'datetime',
        'pickup_lat'               => 'float',
        'pickup_lng'               => 'float',
        'delivery_lat'             => 'float',
        'delivery_lng'             => 'float',
    ];

    public function driver()   { return $this->belongsTo(\Modules\Core\Models\User::class, 'delivery_man_id'); }
    public function sender()   { return $this->belongsTo(\Modules\Core\Models\User::class, 'sender_platform_id'); }
    public function city()     { return $this->belongsTo(\Modules\Core\Models\City::class); }
    public function histories(){ return $this->hasMany(OrderHistory::class)->latest(); }

    protected $appends = ['city_name'];
    public function getCityNameAttribute() { return $this->city?->name ?? 'N/A'; }
}
