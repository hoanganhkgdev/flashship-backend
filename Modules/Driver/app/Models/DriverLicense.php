<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class DriverLicense extends Model
{
    const STATUS_APPROVED = 'approved';
    const STATUS_PENDING  = 'pending';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = ['user_id', 'image_path', 'status'];

    public function user() { return $this->belongsTo(\Modules\Core\Models\User::class); }
}
