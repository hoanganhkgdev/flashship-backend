<?php
namespace Modules\Core\Models;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = ['city_id', 'code', 'name', 'start_time', 'end_time', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function city() { return $this->belongsTo(City::class); }
    public function users()
    {
        return $this->belongsToMany(User::class, 'shift_user', 'shift_id', 'user_id')
            ->withTimestamps();
    }

    public function logs() { return $this->hasMany(ShiftLog::class)->latest('id'); }

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }
    public function scopeForCity(Builder $q, int $cityId): Builder { return $q->where('city_id', $cityId); }

    /** Ca chạy trong khoảng [bắt đầu, kết thúc): đúng giờ kết thúc là đã hết ca, giống phạt Offline và số liệu tải đơn. */
    public function isNowInShift(): bool
    {
        $t = Carbon::now()->secondsSinceMidnight();
        $s = Carbon::parse($this->start_time)->secondsSinceMidnight();
        $e = Carbon::parse($this->end_time)->secondsSinceMidnight();

        return $e > $s ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
    }

    public function secondsUntilEnd(): int
    {
        $now = Carbon::now(); $start = Carbon::parse($this->start_time); $end = Carbon::parse($this->end_time);
        if ($end->lessThan($start) && $now->greaterThanOrEqualTo($start)) $end = $end->addDay();
        return max(0, (int) $now->diffInSeconds($end, false));
    }
}
