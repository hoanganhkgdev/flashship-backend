<?php
namespace Modules\Core\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    const TYPE_COMMISSION = 'commission';
    const TYPE_WEEKLY     = 'weekly';
    const TYPE_PARTNER    = 'partner';
    const TYPE_FREE       = 'free';

    protected $fillable = ['name', 'code', 'type', 'commission_rate', 'base_weekly_fee', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'commission_rate' => 'float', 'base_weekly_fee' => 'float'];

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }
    public function scopeForCity(Builder $q, int $cityId): Builder { return $q; }
}
