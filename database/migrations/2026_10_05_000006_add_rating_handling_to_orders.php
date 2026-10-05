<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('rated_at')->nullable()->after('driver_rating_note');
            $table->timestamp('rating_handled_at')->nullable()->after('rated_at');
            $table->unsignedBigInteger('rating_handled_by')->nullable()->after('rating_handled_at');
            $table->text('rating_handle_note')->nullable()->after('rating_handled_by');
            // Ẩn khỏi thống kê (đánh giá sai/spam) — giữ nguyên nội dung, không xóa.
            $table->boolean('rating_hidden')->default(false)->after('rating_handle_note');
            $table->index(['driver_rating', 'rating_handled_at'], 'orders_rating_handling_index');
        });

        // Đánh giá cũ chưa có giờ riêng: lấy updated_at (lần ghi cuối, gần giờ đánh giá nhất).
        DB::table('orders')->whereNotNull('driver_rating')->whereNull('rated_at')->update(['rated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_rating_handling_index');
            $table->dropColumn(['rated_at', 'rating_handled_at', 'rating_handled_by', 'rating_handle_note', 'rating_hidden']);
        });
    }
};
