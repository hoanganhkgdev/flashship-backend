<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->string('platform')->default('customer')->after('id');
        });

        // Update existing row to customer
        DB::table('app_version_settings')->update(['platform' => 'customer']);

        // Add driver row
        DB::table('app_version_settings')->insert([
            'platform'       => 'driver',
            'min_version'    => '1.0.0',
            'latest_version' => '1.0.1',
            'android_url'    => null,
            'ios_url'        => null,
            'force_update'   => false,
            'force_message'  => 'Vui lòng cập nhật ứng dụng tài xế để tiếp tục.',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
