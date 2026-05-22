<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_pages', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('legal_pages', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
