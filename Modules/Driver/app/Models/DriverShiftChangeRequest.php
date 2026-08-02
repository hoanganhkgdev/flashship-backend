<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverShiftChangeRequest extends Model
{
    protected $fillable = ['driver_id', 'shift_ids', 'status', 'admin_note', 'processed_by', 'processed_at'];

    protected $casts = [
        'shift_ids'    => 'array',
        'processed_at' => 'datetime',
    ];

    public function driver()    { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
    public function processor() { return $this->belongsTo(\Modules\Core\Models\User::class, 'processed_by'); }
}
