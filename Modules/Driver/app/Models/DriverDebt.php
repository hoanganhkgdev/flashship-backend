<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverDebt extends Model
{
    protected $fillable = [
        'driver_id', 'status', 'amount_due', 'amount_paid',
        'week_start', 'week_end', 'date', 'ref_id', 'note',
    ];

    public function driver() { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }

    public function scopeByStatus($q, $s) { return $s && $s !== 'all' ? $q->where('status', $s) : $q; }
}
