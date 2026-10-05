<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverDebtLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['debt_id', 'action', 'amount', 'performed_by', 'note'];

    protected $casts = ['created_at' => 'datetime', 'amount' => 'float'];

    public function debt()
    {
        return $this->belongsTo(DriverDebt::class, 'debt_id');
    }
}
