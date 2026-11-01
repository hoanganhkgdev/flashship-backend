<?php
namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DriverLeaveRequest extends Model
{
    /** Tài xế tự xin trên app, chờ admin duyệt — chưa có hiệu lực. */
    public const PENDING = 'pending';

    /** Có hiệu lực: không bật online, không nhận đơn, miễn chấm ca. */
    public const APPROVED = 'approved';

    /** Admin từ chối — tài xế vẫn phải đi làm theo ca như thường. */
    public const REJECTED = 'rejected';

    protected $fillable = ['driver_id', 'leave_date', 'note', 'status', 'created_by', 'reviewed_by', 'reviewed_at', 'reject_reason'];

    protected $attributes = [
        'status' => self::APPROVED,
    ];

    protected $casts = [
        'leave_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function driver()   { return $this->belongsTo(\Modules\Core\Models\User::class, 'driver_id'); }
    public function creator()  { return $this->belongsTo(\Modules\Core\Models\User::class, 'created_by'); }
    public function reviewer() { return $this->belongsTo(\Modules\Core\Models\User::class, 'reviewed_by'); }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function isPending(): bool  { return $this->status === self::PENDING; }
    public function isApproved(): bool { return $this->status === self::APPROVED; }
    public function isRejected(): bool { return $this->status === self::REJECTED; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => $this->leave_date->lt(today()) ? 'Quá hạn chưa duyệt' : 'Chờ duyệt',
            self::REJECTED => 'Từ chối',
            default => 'Đã duyệt',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::PENDING => $this->leave_date->lt(today()) ? 'gray' : 'warning',
            self::REJECTED => 'danger',
            default => 'success',
        };
    }
}
