<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Ảnh CCCD và bằng lái: lưu ở ổ riêng (không có đường dẫn công khai), chỉ xem qua đường dẫn có chữ ký, hết hạn sau
 * vài phút. Ảnh tải lên trước đây nằm ở ổ public; `kyc:make-private` chuyển chúng sang, trong lúc chờ thì vẫn đọc được
 * từ ổ cũ để không có ảnh nào bị mất.
 */
final class KycFile
{
    public const DISK = 'local';

    private const LEGACY_DISK = 'public';

    public const DIRS = ['driver-cccd', 'driver-licenses'];

    /** Đường dẫn chỉ gồm một thư mục giấy tờ và một tên file, không có ".." hay thư mục khác. */
    public const PATH_PATTERN = '(driver-cccd|driver-licenses)/[A-Za-z0-9._-]+';

    private const TTL_MINUTES = 30;

    public static function store(UploadedFile $file, string $dir): string
    {
        return $file->store($dir, self::DISK);
    }

    public static function url(?string $path): ?string
    {
        if (! $path || ! self::isValidPath($path)) {
            return null;
        }

        return URL::temporarySignedRoute('kyc.file', now()->addMinutes(self::TTL_MINUTES), ['path' => $path]);
    }

    public static function isValidPath(string $path): bool
    {
        return (bool) preg_match('#^'.self::PATH_PATTERN.'$#', $path) && ! str_contains($path, '..');
    }

    /** Ổ đang chứa file: ổ riêng, hoặc ổ public nếu là ảnh cũ chưa chuyển. */
    public static function disk(string $path): ?\Illuminate\Contracts\Filesystem\Filesystem
    {
        foreach ([self::DISK, self::LEGACY_DISK] as $name) {
            if (Storage::disk($name)->exists($path)) {
                return Storage::disk($name);
            }
        }

        return null;
    }

    public static function delete(?string $path): void
    {
        if ($path && self::isValidPath($path)) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk(self::LEGACY_DISK)->delete($path);
        }
    }
}
