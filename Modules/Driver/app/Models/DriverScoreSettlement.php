<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverScoreSettlement extends Model
{
    protected $fillable = [
        'driver_id', 'type', 'amount', 'score_at_settlement',
        'week_start', 'week_end', 'status',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end'   => 'date',
    ];

    public function driver()
    {
        return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id');
    }
}
