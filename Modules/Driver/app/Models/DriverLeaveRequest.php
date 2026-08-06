<?php
namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLeaveRequest extends Model
{
    protected $fillable = ['driver_id', 'leave_date', 'note', 'created_by'];

    protected $casts = [
        'leave_date' => 'date',
    ];

    public function driver()  { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
    public function creator() { return $this->belongsTo(\Modules\Core\Models\User::class, 'created_by'); }
}
