<?php

namespace Modules\Pricing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'service_type' => 'delivery',
                'label'        => 'Lấy Đồ Hộ',
                'base_km'      => 3,
                'base_fee'     => 15_000,
                'per_km_fee'   => 3_000,
                'min_fee'      => 15_000,
                'config_json'  => json_encode([
                    'type'           => 'slab',
                    'slabs'          => [
                        ['max_km' => 3.5,  'fee' => 15_000],
                        ['max_km' => 4.5,  'fee' => 18_000],
                        ['max_km' => 5.5,  'fee' => 20_000],
                        ['max_km' => 6.5,  'fee' => 23_000],
                        ['max_km' => 7.5,  'fee' => 25_000],
                        ['max_km' => 8.5,  'fee' => 28_000],
                        ['max_km' => 10.0, 'fee' => 30_000],
                    ],
                    'over_max_per_km' => 3_000,
                ]),
            ],
            [
                'service_type' => 'shopping',
                'label'        => 'Mua Hộ',
                'base_km'      => 3,
                'base_fee'     => 15_000,
                'per_km_fee'   => 3_000,
                'min_fee'      => 15_000,
                'config_json'  => json_encode([
                    'type'           => 'slab',
                    'slabs'          => [
                        ['max_km' => 3.5,  'fee' => 15_000],
                        ['max_km' => 4.5,  'fee' => 18_000],
                        ['max_km' => 5.5,  'fee' => 20_000],
                        ['max_km' => 6.5,  'fee' => 23_000],
                        ['max_km' => 7.5,  'fee' => 25_000],
                        ['max_km' => 8.5,  'fee' => 28_000],
                        ['max_km' => 10.0, 'fee' => 30_000],
                    ],
                    'over_max_per_km' => 3_000,
                ]),
            ],
            [
                'service_type' => 'bike',
                'label'        => 'Xe Ôm',
                'base_km'      => 2,
                'base_fee'     => 15_000,
                'per_km_fee'   => 5_000,
                'min_fee'      => 15_000,
                'config_json'  => json_encode([
                    'type'              => 'tiered_linear',
                    'base_km'           => 2,
                    'base_fee'          => 15_000,
                    'per_km_fee'        => 5_000,
                    'higher_from_km'    => 10,
                    'higher_per_km_fee' => 6_000,
                ]),
            ],
            [
                'service_type' => 'motor',
                'label'        => 'Lái Hộ Xe Máy',
                'base_km'      => 3,
                'base_fee'     => 60_000,
                'per_km_fee'   => 6_000,
                'min_fee'      => 60_000,
                'config_json'  => json_encode([
                    'type'        => 'linear',
                    'base_km'     => 3,
                    'base_fee'    => 60_000,
                    'per_km_fee'  => 6_000,
                ]),
            ],
            [
                'service_type' => 'car',
                'label'        => 'Lái Hộ Ô Tô',
                'base_km'      => 3,
                'base_fee'     => 80_000,
                'per_km_fee'   => 10_000,
                'min_fee'      => 80_000,
                'config_json'  => json_encode([
                    'type'        => 'linear',
                    'base_km'     => 3,
                    'base_fee'    => 80_000,
                    'per_km_fee'  => 10_000,
                ]),
            ],
            [
                'service_type' => 'topup',
                'label'        => 'Nạp Tiền',
                'base_km'      => 0,
                'base_fee'     => 20_000,
                'per_km_fee'   => 0,
                'min_fee'      => 20_000,
                'config_json'  => json_encode([
                    'type'              => 'topup',
                    'tiers'             => [
                        ['max_amount' => 5_000_000,  'fee' => 20_000],
                        ['max_amount' => 10_000_000, 'fee' => 30_000],
                        ['max_amount' => 15_000_000, 'fee' => 40_000],
                        ['max_amount' => 20_000_000, 'fee' => 50_000],
                        ['max_amount' => 25_000_000, 'fee' => 60_000],
                    ],
                    'over_max_per_unit' => 5_000_000,
                    'over_max_fee_step' => 10_000,
                ]),
            ],
        ];

        foreach ($services as $service) {
            DB::table('pricing_configs')->upsert(
                array_merge($service, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]),
                ['service_type'],
                ['label', 'base_km', 'base_fee', 'per_km_fee', 'min_fee', 'config_json', 'is_active', 'updated_at']
            );
        }
    }
}
