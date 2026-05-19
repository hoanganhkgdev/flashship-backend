<?php
namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverScoreResetRequest extends Model
{
    protected $fillable = [
        'driver_id', 'current_score', 'points_to_restore',
        'amount', 'status', 'admin_note', 'processed_by', 'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function driver()    { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
    public function processor() { return $this->belongsTo(\Modules\Core\Models\User::class, 'processed_by'); }
}
