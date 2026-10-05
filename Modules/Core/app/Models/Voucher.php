<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'description',
        'min_order_value', 'max_discount', 'max_distance_km', 'service_types',
        'city_id', 'audience', 'user_id', 'expires_at', 'usage_limit', 'per_user_limit', 'first_order_only', 'used_count', 'is_active',
    ];

    protected $casts = [
        'service_types' => 'array',
        'expires_at'    => 'datetime',
        'is_active'     => 'boolean',
        'first_order_only' => 'boolean',
        'max_distance_km' => 'float',
    ];

    public function scopeAvailable($query)
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'));
    }

    public function scopeForCity($query, ?int $cityId)
    {
        if ($cityId === null) return $query;
        return $query->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId));
    }

    public function scopeForAudience($query, string $audience)
    {
        return $query->where('audience', $audience);
    }

    public function scopeForUser($query, ?int $userId)
    {
        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    public function city(): BelongsTo { return $this->belongsTo(City::class); }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function usages() { return $this->hasMany(VoucherUsage::class); }

    public function usageCountByUser(int $userId): int
    {
        return $this->usages()->where('user_id', $userId)->count();
    }

    /** Câu mô tả ưu đãi bằng tiếng Việt, dùng cho bảng và phần xem trước khi tạo mã. */
    public static function describeOffer(?string $type, $value, $maxDiscount = null): string
    {
        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
        $max = $maxDiscount ? ', tối đa '.$money($maxDiscount) : '';

        return match ($type) {
            'percent' => $value ? "Giảm {$value}%{$max}" : 'Giảm theo phần trăm',
            'fixed' => $value ? 'Giảm '.$money($value) : 'Giảm số tiền cố định',
            'freeship' => 'Miễn phí vận chuyển'.($maxDiscount ? ', tối đa '.$money($maxDiscount) : ''),
            default => '—',
        };
    }

    /** Các điều kiện của mã dưới dạng danh sách ngắn (hiện thành chip/dòng phụ). */
    public static function describeConditions(array $v): array
    {
        $money = fn ($x) => number_format((float) $x, 0, ',', '.').'₫';
        $out = [];
        if (! empty($v['first_order_only'])) {
            $out[] = 'Đơn đầu tiên';
        }
        if (! empty($v['per_user_limit'])) {
            $out[] = $v['per_user_limit'] == 1 ? '1 lần/người' : $v['per_user_limit'].' lần/người';
        }
        if (! empty($v['min_order_value'])) {
            $out[] = 'Phí từ '.$money($v['min_order_value']);
        }
        if (! empty($v['max_distance_km'])) {
            $out[] = 'Cự ly ≤ '.rtrim(rtrim(number_format((float) $v['max_distance_km'], 1, ',', '.'), '0'), ',').' km';
        }

        return $out;
    }

    /** Trạng thái hiện tại: active | inactive | expired | full. */
    public function statusKey(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->expires_at && ! $this->expires_at->isFuture() => 'expired',
            $this->usage_limit && $this->used_count >= $this->usage_limit => 'full',
            default => 'active',
        };
    }

    /** Mã đã từng được dùng thì không được xóa: xóa sẽ kéo theo mất lịch sử lượt dùng và chi phí. */
    public function hasHistory(): bool
    {
        return $this->used_count > 0 || $this->usages()->exists();
    }

    public function getDiscountLabelAttribute(): string
    {
        return match ($this->type) {
            'percent'  => "-{$this->value}%",
            'fixed'    => '-' . number_format($this->value) . 'đ',
            'freeship' => 'Freeship',
            default    => '-',
        };
    }
}
