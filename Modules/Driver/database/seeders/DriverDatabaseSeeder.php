<?php

namespace Modules\Driver\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\City;
use Modules\Core\Models\User;

class DriverDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $cantho = City::where('slug', 'can-tho')->first();
        if (!$cantho) {
            $this->command->warn('⚠️  City Cần Thơ chưa có, chạy CoreDatabaseSeeder trước.');
            return;
        }

        $rachgia = City::where('slug', 'rach-gia')->first();

        $drivers = [
            // Cần Thơ
            ['name' => 'Tài Xế Test CT1', 'phone' => '0900000001', 'city' => $cantho,  'lat' => 10.0314, 'lng' => 105.7695],
            ['name' => 'Tài Xế Test CT2', 'phone' => '0900000002', 'city' => $cantho,  'lat' => 10.0350, 'lng' => 105.7730],
            // Rạch Giá
            ['name' => 'Tài Xế Test RG1', 'phone' => '0900000003', 'city' => $rachgia, 'lat' => 10.0125, 'lng' => 105.0809],
            ['name' => 'Tài Xế Test RG2', 'phone' => '0900000004', 'city' => $rachgia, 'lat' => 10.0160, 'lng' => 105.0850],
        ];

        foreach ($drivers as $d) {
            if (!$d['city']) continue;
            User::firstOrCreate(
                ['phone' => $d['phone'], 'user_type' => 'driver'],
                [
                    'name'         => $d['name'],
                    'password'     => Hash::make('Driver@123'),
                    'user_type'    => 'driver',
                    'city_id'      => $d['city']->id,
                    'status'       => 1,
                    'is_online'    => true,
                    'latitude'     => $d['lat'],
                    'longitude'    => $d['lng'],
                    'driver_score' => 80,
                ]
            );
        }

        $this->command->info('✅ Driver seeded: 2 tài xế Cần Thơ + 2 tài xế Rạch Giá (online, sẵn sàng nhận đơn)');
    }
}
