<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

class DriverStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'action', 'reason', 'performed_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /** Khóa ngắn (cột action tối đa 12 ký tự). */
    public const ACTION_LABELS = [
        'approved' => 'Duyệt tài khoản', 'locked' => 'Khóa tài khoản', 'unlocked' => 'Mở khóa tài khoản',
        'city_change' => 'Đổi khu vực', 'phone_change' => 'Đổi số điện thoại', 'debt_on' => 'Bật công nợ', 'debt_off' => 'Tắt công nợ',
        'name_change' => 'Đổi tên', 'delete_req' => 'Yêu cầu xoá tài khoản', 'delete_undo' => 'Huỷ yêu cầu xoá tài khoản',
    ];

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
