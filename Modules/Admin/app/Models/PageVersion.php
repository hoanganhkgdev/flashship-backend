<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class PageVersion extends Model
{
    protected $table = 'legal_page_versions';

    public $timestamps = false;

    protected $fillable = ['page_id', 'title', 'content', 'performed_by'];

    protected $casts = ['created_at' => 'datetime'];
}
