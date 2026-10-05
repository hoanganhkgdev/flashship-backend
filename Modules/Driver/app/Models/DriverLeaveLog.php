<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLeaveLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'leave_date', 'action', 'performed_by', 'note'];

    protected $casts = ['leave_date' => 'date', 'created_at' => 'datetime'];
}
