<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['shift_id', 'action', 'performed_by', 'detail'];

    protected $casts = ['created_at' => 'datetime'];
}
