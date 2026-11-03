<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverCccdImage extends Model
{
    const STATUS_APPROVED = 'approved';
    const STATUS_PENDING  = 'pending';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = ['user_id', 'image_path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function reviewer() { return $this->belongsTo(\Modules\Core\Models\User::class, 'reviewed_by'); }

    public function user() { return $this->belongsTo(\Modules\Core\Models\User::class); }
}
