<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Voucher;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $vouchers = [
            [
                'code'            => 'WELCOME20',
                'type'            => 'percent',
                'value'           => 20,
                'description'     => 'Giảm 20% cho đơn đầu tiên',
                'min_order_value' => 20000,
                'max_discount'    => 30000,
                'service_types'   => null,
                'expires_at'      => now()->addMonths(3),
                'usage_limit'     => null,
            ],
            [
                'code'            => 'SHIP0',
                'type'            => 'fixed',
                'value'           => 15000,
                'description'     => 'Miễn phí giao hàng',
                'min_order_value' => 30000,
                'max_discount'    => null,
                'service_types'   => ['delivery', 'shopping'],
                'expires_at'      => now()->addMonths(1),
                'usage_limit'     => 500,
            ],
            [
                'code'            => 'FLASH30',
                'type'            => 'percent',
                'value'           => 30,
                'description'     => 'Ưu đãi cuối tuần -30%',
                'min_order_value' => 50000,
                'max_discount'    => 50000,
                'service_types'   => null,
                'expires_at'      => now()->addDays(7),
                'usage_limit'     => 200,
            ],
            [
                'code'            => 'BIKER10',
                'type'            => 'fixed',
                'value'           => 10000,
                'description'     => 'Giảm 10.000đ dịch vụ xe ôm',
                'min_order_value' => null,
                'max_discount'    => null,
                'service_types'   => ['bike'],
                'expires_at'      => now()->addMonths(2),
                'usage_limit'     => null,
            ],
        ];

        foreach ($vouchers as $data) {
            Voucher::firstOrCreate(['code' => $data['code']], $data);
        }
    }
}
