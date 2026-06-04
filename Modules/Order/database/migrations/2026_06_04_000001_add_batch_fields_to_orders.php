<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_batch')->default(false)->after('cargo_weight');
            $table->json('stops')->nullable()->after('is_batch')
                ->comment('Multi-stop: [{seq,address,lat,lng,phone,name,cod_amount,note,delivered_at}]');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_batch', 'stops']);
        });
    }
};
