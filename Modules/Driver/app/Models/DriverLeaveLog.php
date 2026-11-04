<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLeaveLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'leave_date', 'action', 'performed_by', 'note'];

    protected $casts = ['leave_date' => 'date', 'created_at' => 'datetime'];

    /** Ghi giờ theo ứng dụng, không dựa vào giờ mặc định của DB: phạt Offline dùng giờ này làm mốc khi hủy ngày nghỉ. */
    protected static function booted(): void
    {
        static::creating(fn (DriverLeaveLog $log) => $log->created_at ??= now());
    }
}
