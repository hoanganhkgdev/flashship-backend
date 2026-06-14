<?php
namespace Modules\Customer\Models;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = ['user_id', 'label', 'place_name', 'address', 'latitude', 'longitude', 'is_default'];
    protected $casts    = ['is_default' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function user() { return $this->belongsTo(\Modules\Core\Models\User::class); }
}
