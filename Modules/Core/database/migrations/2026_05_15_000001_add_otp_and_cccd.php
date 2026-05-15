<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'cccd')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('cccd')->nullable()->after('address');
            });
        }

        Schema::create('driver_otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('otp', 6);
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_otps');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cccd');
        });
    }
};
