<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverScoreLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'delta', 'score_before', 'score_after', 'reason'];

    protected $casts = ['created_at' => 'datetime'];

    public function driver()
    {
        return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id');
    }
}
