<?php
namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\City;

class Banner extends Model
{
    protected $fillable = ['city_id', 'title', 'image_path', 'link_url', 'is_active', 'sort_order', 'starts_at', 'ends_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /** Trả thêm đường dẫn ảnh đầy đủ cho app (app cũ vẫn dùng image_path như trước). */
    protected $appends = ['image_url'];

    protected static function booted(): void
    {
        // Xóa banner hoặc thay ảnh thì xóa luôn file cũ, tránh tích tụ file mồ côi.
        static::deleted(fn (self $banner) => $banner->deleteImage($banner->image_path));
        static::updated(function (self $banner): void {
            if ($banner->wasChanged('image_path')) {
                $banner->deleteImage($banner->getOriginal('image_path'));
            }
        });
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return str_starts_with($this->image_path, 'http') ? $this->image_path : url('storage/'.ltrim($this->image_path, '/'));
    }

    /** Banner đang được phép hiển thị: bật, đã tới giờ bắt đầu và chưa hết hạn. */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** live | scheduled | expired | inactive */
    public function statusKey(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->ends_at && ! $this->ends_at->isFuture() => 'expired',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            default => 'live',
        };
    }

    public function imageMissing(): bool
    {
        return $this->image_path
            && ! str_starts_with($this->image_path, 'http')
            && ! Storage::disk('public')->exists($this->image_path);
    }

    private function deleteImage(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }
}
