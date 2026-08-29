<?php
namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverGpsEligibleSession extends Model
{
    protected $fillable = ['driver_id', 'started_at', 'last_gps_at', 'ended_at'];

    protected $casts = [
        'started_at'  => 'datetime',
        'last_gps_at' => 'datetime',
        'ended_at'    => 'datetime',
    ];
}
