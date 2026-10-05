<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

/** Tổng kết theo bộ lọc/tìm kiếm/tab đang áp dụng trên danh sách. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class OrderListSummaryWidget extends Widget
{
    use InteractsWithPageTable;

    protected static string $view = 'filament.resources.order-resource.widgets.order-list-summary';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getTablePage(): string
    {
        return ListOrders::class;
    }

    protected function getViewData(): array
    {
        $base = $this->getPageTableQuery()->toBase()->reorder();
        $row = (clone $base)->selectRaw("COUNT(*) total, SUM(status = 'cancelled') cancelled, SUM(status = 'completed') completed,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN shipping_fee END), 0) fee, COALESCE(SUM(CASE WHEN status = 'completed' THEN cod_amount END), 0) cod")->first();

        return [
            'total' => (int) $row->total,
            'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled,
            'fee' => (int) $row->fee,
            'cod' => (int) $row->cod,
            'cancelRate' => $row->total ? round($row->cancelled / $row->total * 100) : 0,
        ];
    }
}
