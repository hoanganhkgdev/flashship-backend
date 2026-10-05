<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lịch sử chiến dịch thông báo gửi cho khách hàng: ai gửi, gửi gì, tới ai, kết quả ra sao.
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('scope', 10)->default('city');           // city | all
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status', 12)->default('queued')->index(); // scheduled | queued | sending | sent | failed | cancelled
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('inbox_count')->default(0);       // khách nhận được trong hộp thư app
            $table->unsignedInteger('push_target')->default(0);       // thiết bị có token để gửi push
            $table->unsignedInteger('push_sent')->default(0);
            $table->unsignedInteger('push_failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Gắn từng thông báo trong hộp thư với chiến dịch để tính tỷ lệ mở.
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->index()->after('order_code');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
        Schema::dropIfExists('notification_campaigns');
    }
};
