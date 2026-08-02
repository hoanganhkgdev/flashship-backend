<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverShiftSession extends Model
{
    protected $fillable = ['driver_id', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function driver() { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
}
