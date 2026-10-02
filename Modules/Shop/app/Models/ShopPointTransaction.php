<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class ShopPointTransaction extends Model
{
    protected $fillable = ['shop_id', 'type', 'points', 'balance_after', 'description', 'reference'];
}
