<?php

namespace App\Services;

use App\Models\AppVersionSetting;

/** So sánh phiên bản, phát hiện cấu hình lệch và đồng bộ trường cũ cho cài đặt phiên bản app. */
class AppVersionPolicy
{
    public const PLATFORMS = ['customer' => 'Khách hàng', 'driver' => 'Tài xế', 'shop' => 'Cửa hàng'];

    /** So sánh bỏ phần build (+15) và hậu tố (-beta). Trả <0, 0, >0. */
    public static function compare(string $a, string $b): int
    {
        $n = fn (string $v) => preg_replace('/[-+].*$/', '', trim($v));

        return version_compare($n($a), $n($b));
    }

    public static function max(?string $a, ?string $b): ?string
    {
        if (! $a) {
            return $b ?: null;
        }

        return ($b && self::compare($b, $a) > 0) ? $b : $a;
    }

    /**
     * Lỗi cấu hình: phiên bản tối thiểu cao hơn bản mới nhất trên cửa hàng khiến người dùng
     * đang chạy bản mới nhất bị bắt cập nhật lên bản không có sẵn.
     *
     * @return array<string,string> [tên trường => thông báo]
     */
    public static function conflicts(?string $min, ?string $androidLatest, ?string $iosLatest): array
    {
        $errors = [];
        if ($min && $androidLatest && self::compare($min, $androidLatest) > 0) {
            $errors['android_latest'] = "Bản mới nhất Android ({$androidLatest}) thấp hơn phiên bản tối thiểu ({$min}).";
        }
        if ($min && $iosLatest && self::compare($min, $iosLatest) > 0) {
            $errors['ios_latest'] = "Bản mới nhất iOS ({$iosLatest}) thấp hơn phiên bản tối thiểu ({$min}).";
        }

        return $errors;
    }

    /** Trường cũ latest_version (app cửa hàng vẫn đọc) = bản mới nhất cao nhất giữa hai nền tảng. */
    public static function legacyLatest(?string $androidLatest, ?string $iosLatest): ?string
    {
        return self::max($androidLatest, $iosLatest);
    }

    /** Cảnh báo cho cấu hình đang lưu của một nền tảng. */
    public static function issues(AppVersionSetting $s): array
    {
        $issues = array_values(self::conflicts(
            $s->min_version,
            $s->android_latest_version ?: $s->latest_version,
            $s->ios_latest_version ?: $s->latest_version,
        ));
        if ($s->force_update && ! $s->min_version) {
            $issues[] = 'Bật bắt buộc cập nhật nhưng chưa đặt phiên bản tối thiểu.';
        }

        return $issues;
    }
}
