<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

class DriverStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'action', 'reason', 'performed_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public const ACTION_LABELS = ['approved' => 'Duyệt tài khoản', 'locked' => 'Khóa tài khoản', 'unlocked' => 'Mở khóa tài khoản'];

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
