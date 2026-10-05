<?php

namespace App\Filament\Support;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;

/**
 * Widget "cơ cấu đơn hôm nay": danh sách thanh ngang xếp theo số đơn giảm dần.
 * Lớp con chỉ cần khai báo tiêu đề và trả về các dòng.
 */
abstract class BreakdownWidget extends Widget
{
    protected static string $view = 'filament.widgets.breakdown';

    protected static ?string $pollingInterval = '30s';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    abstract protected function title(): string;

    abstract protected function description(): string;

    /** @return array<int, array{label: string, count: int, note?: string}> */
    abstract protected function rows(): array;

    /** Đơn tạo hôm nay, đã lọc theo khu vực đang chọn. */
    protected function todayOrders(): Builder
    {
        return Order::query()
            ->when(Filament::getTenant()?->id, fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    protected function getViewData(): array
    {
        $rows = collect($this->rows())->sortByDesc('count')->values();
        $total = (int) $rows->sum('count');

        return [
            'title' => $this->title(),
            'description' => $this->description(),
            'rows' => $rows->map(fn ($r) => $r + ['percent' => $total ? (int) round($r['count'] / $total * 100) : 0])->all(),
            'total' => $total,
        ];
    }
}
