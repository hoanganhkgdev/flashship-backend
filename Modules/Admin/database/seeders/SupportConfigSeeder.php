<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\SupportConfig;

class SupportConfigSeeder extends Seeder
{
    public function run(): void
    {
        SupportConfig::truncate();

        $items = [
            [
                'title'    => 'Chat Zalo hỗ trợ',
                'subtitle' => 'Phản hồi trong giờ hành chính',
                'icon'     => 'chat-bubble-oval-left-ellipsis',
                'type'     => 'zalo',
                'value'    => 'https://zalo.me/flashship',
                'color'    => '#0068FF',
                'priority' => 1,
                'is_active'=> true,
            ],
            [
                'title'    => 'Gọi hotline',
                'subtitle' => '8:00 – 22:00 hàng ngày',
                'icon'     => 'phone',
                'type'     => 'phone',
                'value'    => 'tel:19001234',
                'color'    => '#34C759',
                'priority' => 2,
                'is_active'=> true,
            ],
            [
                'title'    => 'Facebook Messenger',
                'subtitle' => 'Fanpage FlashShip',
                'icon'     => 'chat-bubble-left-right',
                'type'     => 'url',
                'value'    => 'https://m.me/flashship',
                'color'    => '#0084FF',
                'priority' => 3,
                'is_active'=> true,
            ],
            [
                'title'    => 'Gửi email',
                'subtitle' => 'support@flashship.vn',
                'icon'     => 'envelope',
                'type'     => 'email',
                'value'    => 'mailto:support@flashship.vn',
                'color'    => '#EA4335',
                'priority' => 4,
                'is_active'=> true,
            ],
        ];

        foreach ($items as $item) {
            SupportConfig::create($item);
        }
    }
}
