<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ai duyệt / từ chối CCCD, bằng lái và lúc nào. Nullable: hồ sơ cũ chưa có thông tin này. */
return new class extends Migration
{
    private const TABLES = ['driver_cccd_images', 'driver_licenses'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (! Schema::hasColumn($t, 'reviewed_by')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('reviewed_by')->nullable()->after('rejection_reason');
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasColumn($t, 'reviewed_by')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['reviewed_by', 'reviewed_at']));
            }
        }
    }
};
