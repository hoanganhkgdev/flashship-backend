<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Kết nối MySQL cho các test cần InnoDB thật (lockForUpdate, transaction).
     * Đọc từ .env.testing (DB_TEST_*) — không ghi mật khẩu cứng trong code.
     */
    protected static function mysqlTestConnection(): array
    {
        return [
            'driver' => 'mysql',
            // Mặc định lấy cùng thông tin đăng nhập DB như các test khác (.env). Trước đây mặc định mật khẩu
            // rỗng → 5 lớp test (23 test) luôn lỗi "Access denied", che mất lỗi thật nếu có.
            'host' => env('DB_TEST_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_TEST_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_TEST_DATABASE', 'flashship_backend_test'),
            'username' => env('DB_TEST_USERNAME', env('DB_USERNAME', 'flashship')),
            'password' => env('DB_TEST_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ];
    }
}
