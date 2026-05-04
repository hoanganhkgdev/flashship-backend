<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class BankList extends Model
{
    protected $fillable = ['code', 'name', 'logo_url', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];
}
