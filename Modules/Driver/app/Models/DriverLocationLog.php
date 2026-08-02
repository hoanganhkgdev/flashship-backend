<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLocationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'latitude', 'longitude', 'bearing', 'source'];

    protected $casts = [
        'created_at' => 'datetime',
        'latitude'   => 'float',
        'longitude'  => 'float',
        'bearing'    => 'float',
    ];

    public function driver()
    {
        return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id');
    }
}
