<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\City;
use Modules\Core\Models\User;

class NotificationCampaign extends Model
{
    protected $fillable = [
        'title', 'body', 'scope', 'city_id', 'created_by', 'status', 'scheduled_at', 'started_at', 'finished_at',
        'inbox_count', 'push_target', 'push_sent', 'push_failed', 'error',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'scheduled' => 'Hẹn giờ', 'queued' => 'Đang chờ gửi', 'sending' => 'Đang gửi',
        'sent' => 'Đã gửi', 'failed' => 'Lỗi', 'cancelled' => 'Đã hủy',
    ];

    public const STATUS_COLORS = [
        'scheduled' => 'info', 'queued' => 'warning', 'sending' => 'warning',
        'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'gray',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
