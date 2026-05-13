<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('icon_name');
            $table->string('color_hex', 7)->default('#FF6B35');
            $table->string('bg_color_hex', 7)->default('#FFF3E8');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('service_types')->insert([
            ['key' => 'delivery', 'label' => 'Lấy Hộ',    'icon_name' => 'storefront',              'color_hex' => '#FF6B35', 'bg_color_hex' => '#FFF3E8', 'sort_order' => 1],
            ['key' => 'shopping', 'label' => 'Mua Hộ',    'icon_name' => 'shopping_bag',            'color_hex' => '#7C4DFF', 'bg_color_hex' => '#F0EFFF', 'sort_order' => 2],
            ['key' => 'topup',    'label' => 'Nạp Tiền',  'icon_name' => 'account_balance_wallet',  'color_hex' => '#00C896', 'bg_color_hex' => '#E8FBF4', 'sort_order' => 3],
            ['key' => 'bike',     'label' => 'Xe Ôm',     'icon_name' => 'electric_moped',          'color_hex' => '#FFB300', 'bg_color_hex' => '#FFF8E1', 'sort_order' => 4],
            ['key' => 'motor',    'label' => 'Lái Xe Máy','icon_name' => 'motorcycle',              'color_hex' => '#1E88E5', 'bg_color_hex' => '#EBF4FF', 'sort_order' => 5],
            ['key' => 'car',      'label' => 'Lái Xe Hơi','icon_name' => 'directions_car',          'color_hex' => '#E53935', 'bg_color_hex' => '#FEECEC', 'sort_order' => 6],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
