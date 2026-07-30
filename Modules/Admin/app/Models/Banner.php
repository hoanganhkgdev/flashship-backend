<?php
namespace Modules\Admin\Models;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\City;

class Banner extends Model
{
    protected $fillable = ['city_id', 'title', 'image_path', 'link_url', 'is_active', 'sort_order'];
    protected $casts    = ['is_active' => 'boolean'];

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
