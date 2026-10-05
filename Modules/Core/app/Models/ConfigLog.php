<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['entity', 'entity_id', 'action', 'performed_by', 'detail'];

    protected $casts = ['created_at' => 'datetime'];
}
