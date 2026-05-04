<?php
namespace Modules\Admin\Models;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = ['title', 'image_path', 'link_url', 'is_active', 'sort_order'];
    protected $casts    = ['is_active' => 'boolean'];
}
