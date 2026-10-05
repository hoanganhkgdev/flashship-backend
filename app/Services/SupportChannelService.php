<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Admin\Models\SupportConfig;

/** Chuẩn hóa, tạo liên kết mở được và lọc kênh hỗ trợ theo đối tượng/khu vực. */
class SupportChannelService
{
    public const TYPES = ['phone' => 'Số điện thoại', 'zalo' => 'Zalo', 'facebook' => 'Facebook', 'website' => 'Website', 'email' => 'Email', 'other' => 'Khác'];

    public const AUDIENCES = ['all' => 'Tất cả', 'customer' => 'Khách hàng', 'driver' => 'Tài xế', 'shop' => 'Cửa hàng'];

    /** Chuẩn hóa số điện thoại VN về dạng 0xxxxxxxxx; null nếu không hợp lệ. */
    private static function phone(string $v): ?string
    {
        $d = preg_replace('/[^\d+]/', '', $v);
        $d = preg_replace('/^\+?84/', '0', $d);

        return preg_match('/^0\d{8,10}$/', $d) ? $d : null;
    }

    private static function isUrl(string $v): bool
    {
        return (bool) filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://[^/\s]+\.[^/\s]+#i', $v);
    }

    private static function withScheme(string $v): string
    {
        return preg_match('#^https?://#i', $v) ? $v : 'https://'.ltrim($v, '/');
    }

    /** @return array{0:?string,1:?string} [giá trị đã chuẩn hóa, lỗi] */
    public static function normalize(string $type, string $value): array
    {
        $v = trim($value);
        if ($v === '') {
            return [null, 'Giá trị không được để trống.'];
        }

        switch ($type) {
            case 'phone':
                $p = self::phone($v);

                return $p ? [$p, null] : [null, 'Số điện thoại không hợp lệ (9–11 chữ số, bắt đầu bằng 0 hoặc +84).'];
            case 'zalo':
                if ($p = self::phone($v)) {
                    return [$p, null];
                }
                $u = self::withScheme($v);

                return self::isUrl($u) && str_contains($u, 'zalo.me') ? [$u, null] : [null, 'Nhập số điện thoại Zalo hoặc đường dẫn dạng https://zalo.me/...'];
            case 'facebook':
                if (preg_match('/^[A-Za-z0-9.]{3,}$/', $v)) {
                    return ['https://facebook.com/'.$v, null];
                }
                $u = self::withScheme($v);

                return self::isUrl($u) && preg_match('#(facebook\.com|fb\.com|fb\.me|m\.me)#i', $u) ? [$u, null] : [null, 'Nhập tên trang hoặc đường dẫn Facebook (facebook.com/..., fb.me/...).'];
            case 'website':
                $u = self::withScheme($v);

                return self::isUrl($u) ? [$u, null] : [null, 'Đường dẫn website không hợp lệ.'];
            case 'email':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [strtolower($v), null] : [null, 'Email không hợp lệ.'];
            default:
                if (preg_match('#^[\w.-]+\.[a-z]{2,}(/|$)#i', $v) && ! str_contains($v, ' ')) {
                    $v = self::withScheme($v);
                }

                return [$v, null];
        }
    }

    /** Liên kết app có thể mở trực tiếp; null nếu không có dạng mở được. */
    public static function link(string $type, string $value): ?string
    {
        [$v, $err] = self::normalize($type, $value);
        if ($err || $v === null) {
            return in_array($type, ['other'], true) && self::isUrl($value) ? $value : null;
        }

        return match ($type) {
            'phone' => 'tel:'.$v,
            'zalo' => str_starts_with($v, 'http') ? $v : 'https://zalo.me/'.$v,
            'email' => 'mailto:'.$v,
            'facebook', 'website' => $v,
            default => self::isUrl($v) ? $v : null,
        };
    }

    /** Kênh đang bật dành cho một đối tượng ở một khu vực (hoặc toàn hệ thống). */
    public static function query(string $audience, ?int $cityId): Builder
    {
        return SupportConfig::where('is_active', true)
            ->whereIn('audience', ['all', $audience])
            ->where(fn ($q) => $q->whereNull('city_id')->when($cityId, fn ($w) => $w->orWhere('city_id', $cityId)))
            ->orderBy('priority')->orderBy('id');
    }

    /** Dữ liệu trả cho app: các trường cũ giữ nguyên, thêm `link`. */
    public static function forApp(string $audience, ?int $cityId): Collection
    {
        return self::query($audience, $cityId)->get()->map(fn (SupportConfig $c) => [
            'id' => $c->id, 'title' => $c->title, 'subtitle' => $c->subtitle, 'icon' => $c->icon,
            'type' => $c->type, 'value' => $c->value, 'color' => $c->color,
            'link' => self::link($c->type, $c->value),
        ]);
    }

    /** @return array<int, array{key:string,label:string,level:string}> */
    public static function flags(SupportConfig $c, Collection $all): array
    {
        $flags = [];
        [, $err] = self::normalize($c->type, (string) $c->value);
        if ($err) {
            $flags[] = ['key' => 'invalid', 'label' => 'Giá trị chưa hợp lệ', 'level' => 'danger'];
        } elseif (! self::link($c->type, (string) $c->value)) {
            $flags[] = ['key' => 'nolink', 'label' => 'Không mở được bằng 1 chạm', 'level' => 'warning'];
        }
        $dup = $all->filter(fn ($o) => $o->id !== $c->id && $o->is_active && $c->is_active
            && strtolower(trim($o->value)) === strtolower(trim($c->value)) && $o->type === $c->type
            && ($o->audience === $c->audience || $o->audience === 'all' || $c->audience === 'all'))->isNotEmpty();
        if ($dup) {
            $flags[] = ['key' => 'duplicate', 'label' => 'Trùng kênh khác', 'level' => 'warning'];
        }

        return $flags;
    }

    public static function summary(): array
    {
        $active = SupportConfig::where('is_active', true)->get();
        $count = fn (string $aud) => $active->whereIn('audience', ['all', $aud])->count();

        return ['customer' => $count('customer'), 'driver' => $count('driver'), 'shop' => $count('shop'), 'total' => SupportConfig::count(), 'active' => $active->count()];
    }
}
