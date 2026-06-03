<?php

namespace Modules\Shop\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Shop\Models\ShopPricingConfig;

class ShopPricingSeeder extends Seeder
{
    private static array $defaultSlabs = [
        ['max_km' => 3.4,  'fee' => 10000],
        ['max_km' => 4.4,  'fee' => 13000],
        ['max_km' => 5.4,  'fee' => 15000],
        ['max_km' => 6.4,  'fee' => 18000],
        ['max_km' => 7.4,  'fee' => 20000],
        ['max_km' => 8.4,  'fee' => 23000],
        ['max_km' => 10.0, 'fee' => 25000],
    ];

    private static array $flowersSlabs = [
        ['max_km' => 3.4,  'fee' => 15000],
        ['max_km' => 4.4,  'fee' => 18000],
        ['max_km' => 5.4,  'fee' => 20000],
        ['max_km' => 6.4,  'fee' => 23000],
        ['max_km' => 7.4,  'fee' => 25000],
        ['max_km' => 8.4,  'fee' => 28000],
        ['max_km' => 10.0, 'fee' => 30000],
    ];

    public function run(): void
    {
        $configs = [
            [
                'cargo_type'      => 'food',
                'slabs'           => self::$defaultSlabs,
                'over_max_per_km' => 3000,
                'weight_per_kg'   => 0,
            ],
            [
                'cargo_type'      => 'flowers',
                'slabs'           => self::$flowersSlabs,
                'over_max_per_km' => 3000,
                'weight_per_kg'   => 0,
            ],
            [
                'cargo_type'      => 'parcel',
                'slabs'           => self::$defaultSlabs,
                'over_max_per_km' => 3000,
                'weight_per_kg'   => 1000,
            ],
        ];

        foreach ($configs as $config) {
            ShopPricingConfig::updateOrCreate(
                ['cargo_type' => $config['cargo_type'], 'city_id' => null],
                array_merge($config, ['is_active' => true])
            );
        }
    }
}
