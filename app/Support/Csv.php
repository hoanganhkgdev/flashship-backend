<?php

namespace App\Support;

final class Csv
{
    /**
     * Chặn công thức Excel trong dữ liệu người dùng nhập (tên, địa chỉ...): ô bắt đầu bằng = + - @ hoặc tab/xuống dòng
     * được thêm dấu ' để Excel coi là chữ. Số giữ nguyên để vẫn cộng được.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return "'".$value;
    }

    /** @param  array<int, mixed>  $row */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
