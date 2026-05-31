<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_version_settings', function (Blueprint $table) {
            $table->id();
            $table->string('min_version')->default('1.0.0');
            $table->string('latest_version')->default('1.0.0');
            $table->string('android_url')->nullable();
            $table->string('ios_url')->nullable();
            $table->boolean('force_update')->default(false);
            $table->string('force_message')->default('Vui lòng cập nhật ứng dụng để tiếp tục sử dụng.');
            $table->timestamps();
        });

        // Seed one default row
        DB::table('app_version_settings')->insert([
            'min_version'    => '1.0.0',
            'latest_version' => '1.0.1',
            'android_url'    => null,
            'ios_url'        => null,
            'force_update'   => false,
            'force_message'  => 'Vui lòng cập nhật ứng dụng để tiếp tục sử dụng.',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_version_settings');
    }
};
