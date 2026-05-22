<?php
namespace Modules\Admin\Models;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table    = 'legal_pages';
    protected $fillable = ['title', 'slug', 'content', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];
}
