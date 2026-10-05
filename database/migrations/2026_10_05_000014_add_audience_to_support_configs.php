<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_configs', function (Blueprint $table) {
            // all | customer | driver | shop — kênh hiện có giữ nguyên hành vi cũ (hiện cho mọi đối tượng).
            $table->string('audience', 10)->default('all')->after('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_configs', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
