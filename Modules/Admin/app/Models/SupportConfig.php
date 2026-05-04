<?php
namespace Modules\Admin\Models;
use Illuminate\Database\Eloquent\Model;

class SupportConfig extends Model
{
    protected $fillable = ['title', 'subtitle', 'icon', 'type', 'value', 'color', 'city_id', 'priority', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];
}
