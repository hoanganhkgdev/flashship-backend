<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_operational_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('key');
            $table->text('value');
            $table->timestamps();
            $table->unique(['city_id', 'key']);
        });

        // Mỗi khu vực nhận đúng bộ giá trị đang áp dụng chung (kể cả phần
        // admin đã chỉnh trước khi tách khu vực) — hành vi không đổi sau deploy.
        OperationalSettings::flush();
        foreach (DB::table('cities')->pluck('id') as $cityId) {
            OperationalSettings::initializeCity((int) $cityId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('city_operational_settings');
        OperationalSettings::flush();
    }
};
