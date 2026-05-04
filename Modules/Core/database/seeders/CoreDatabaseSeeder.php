<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\City;
use Modules\Core\Models\Plan;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Pricing\Models\PricingConfig;

class CoreDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Cities
        $cantho = City::firstOrCreate(['slug' => 'can-tho'], ['name' => 'Cần Thơ', 'lat' => 10.0452, 'lng' => 105.7469, 'is_active' => true]);
        $rachgia = City::firstOrCreate(['slug' => 'rach-gia'], ['name' => 'Rạch Giá', 'lat' => 10.0125, 'lng' => 105.0809, 'is_active' => true]);

        // Plans
        $commission = Plan::firstOrCreate(['code' => 'commission'], [
            'name' => 'Chiết khấu', 'type' => 'commission', 'commission_rate' => 15, 'is_active' => true,
        ]);
        Plan::firstOrCreate(['code' => 'weekly'], [
            'name' => 'Tuần', 'type' => 'weekly', 'base_weekly_fee' => 150000, 'is_active' => true,
        ]);

        // Shifts
        Shift::firstOrCreate(['code' => 'morning'], [
            'name' => 'Ca sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'city_id' => $cantho->id, 'is_active' => true,
        ]);
        Shift::firstOrCreate(['code' => 'afternoon'], [
            'name' => 'Ca chiều', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'city_id' => $cantho->id, 'is_active' => true,
        ]);
        Shift::firstOrCreate(['code' => 'night'], [
            'name' => 'Ca đêm', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'city_id' => $cantho->id, 'is_active' => true,
        ]);

        // Admin user
        User::firstOrCreate(['email' => 'admin@flashship.vn'], [
            'name'      => 'Admin FlashShip',
            'password'  => bcrypt('Admin@123'),
            'user_type' => 'admin',
            'city_id'   => $cantho->id,
            'status'    => 1,
        ]);

        // Pricing configs
        $pricings = [
            ['service_type' => 'delivery', 'label' => 'Đơn cửa hàng',  'base_fee' => 15000, 'per_km_fee' => 5000,  'min_fee' => 15000],
            ['service_type' => 'shopping', 'label' => 'Đơn mua hộ',    'base_fee' => 20000, 'per_km_fee' => 6000,  'min_fee' => 20000],
            ['service_type' => 'topup',    'label' => 'Nạp tiền',       'base_fee' => 10000, 'per_km_fee' => 0,     'min_fee' => 10000],
            ['service_type' => 'bike',     'label' => 'Xe ôm',          'base_fee' => 15000, 'per_km_fee' => 5000,  'min_fee' => 15000],
            ['service_type' => 'motor',    'label' => 'Lái xe máy',     'base_fee' => 15000, 'per_km_fee' => 6000,  'min_fee' => 15000],
            ['service_type' => 'car',      'label' => 'Lái xe ô tô',    'base_fee' => 30000, 'per_km_fee' => 15000, 'min_fee' => 30000],
        ];

        foreach ($pricings as $p) {
            PricingConfig::firstOrCreate(['service_type' => $p['service_type']], array_merge($p, ['is_active' => true]));
        }

        $this->command->info('✅ Core seeded: 2 cities, 3 shifts, 2 plans, 1 admin, 6 pricing configs');
    }
}
