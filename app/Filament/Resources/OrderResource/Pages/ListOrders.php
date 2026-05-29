<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $services = [
            'all'      => ['label' => 'Tất cả',      'value' => null],
            'delivery' => ['label' => 'Giao hàng',   'value' => 'delivery'],
            'shopping' => ['label' => 'Mua sắm',     'value' => 'shopping'],
            'topup'    => ['label' => 'Nạp tiền',    'value' => 'topup'],
            'bike'     => ['label' => 'Xe máy',      'value' => 'bike'],
            'motor'    => ['label' => 'Xe máy lớn',  'value' => 'motor'],
            'car'      => ['label' => 'Ô tô',        'value' => 'car'],
        ];

        $tabs = [];
        foreach ($services as $key => $cfg) {
            $count = Order::when($cfg['value'], fn (Builder $q) => $q->where('service_type', $cfg['value']))->count();

            $tabs[$key] = Tab::make($cfg['label'])
                ->badge($count ?: null)
                ->modifyQueryUsing(fn (Builder $q) => $cfg['value']
                    ? $q->where('service_type', $cfg['value'])
                    : $q
                );
        }

        return $tabs;
    }
}
