<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['commission', 'weekly', 'partner', 'free'])->default('commission');
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('base_weekly_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('username')->nullable()->unique()->after('phone');
            $table->string('address')->nullable()->after('username');
            $table->unsignedBigInteger('city_id')->nullable()->after('address');
            $table->decimal('latitude', 10, 7)->nullable()->after('city_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->enum('user_type', ['admin', 'subadmin', 'driver', 'customer', 'shop'])->default('customer')->after('longitude');
            $table->tinyInteger('status')->default(1)->after('user_type');
            $table->string('fcm_token')->nullable()->after('status');
            $table->boolean('is_online')->default(false)->after('fcm_token');
            $table->boolean('has_car_license')->default(false)->after('is_online');
            $table->unsignedBigInteger('plan_id')->nullable()->after('has_car_license');
            $table->string('profile_photo_path', 2048)->nullable()->after('plan_id');
            $table->timestamp('last_login_at')->nullable()->after('profile_photo_path');
            $table->timestamp('last_notification_seen')->nullable()->after('last_login_at');
            $table->float('custom_commission_rate')->nullable()->after('last_notification_seen');
            $table->timestamp('name_updated_at')->nullable()->after('custom_commission_rate');
            $table->timestamp('delete_requested_at')->nullable()->after('name_updated_at');

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('set null');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
        });

        Schema::create('shift_user', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['shift_id', 'user_id']);

            $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_user');
        Schema::dropIfExists('shifts');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'username', 'address', 'city_id', 'latitude', 'longitude',
                'user_type', 'status', 'fcm_token', 'is_online', 'has_car_license',
                'plan_id', 'profile_photo_path', 'last_login_at', 'last_notification_seen',
                'custom_commission_rate', 'name_updated_at', 'delete_requested_at',
            ]);
        });
        Schema::dropIfExists('plans');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('settings');
    }
};
