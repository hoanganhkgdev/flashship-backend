<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class WithdrawRequest extends Model
{
    protected $fillable = ['driver_id', 'amount', 'status', 'admin_note'];
    protected $casts    = ['amount' => 'float'];
}
