<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            // Khu vực thử: chạy bình thường trong admin nhưng không lộ ra API công khai cho người dùng thật.
            $table->boolean('is_test')->default(false)->after('is_active');
        });
        DB::table('cities')->where('slug', 'like', '%-test')->update(['is_test' => true]);

        // Nhật ký thay đổi cấu hình danh mục (khu vực, dịch vụ).
        Schema::create('config_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20); // city | service_type
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 24); // created | updated | activated | deactivated | deleted
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_logs');
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
