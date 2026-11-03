<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DriverRatingResource;
use App\Services\DriverRatingService;
use App\Filament\Support\TenantCity;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Order\Models\Order;

/** Đánh giá 1–2 sao chưa xử lý, mới nhất trước, để xử lý khiếu nại sớm. */
class LowRatingsWidget extends Widget
{
    use TenantCity;

    private const LIMIT = 4;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter() && DriverRatingResource::canViewAny();
    }

    protected static string $view = 'filament.widgets.low-ratings';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 12, 'md' => 12, 'xl' => 4];

    protected static ?int $sort = 9;

    public function getPending(): int
    {
        return DriverRatingService::pendingCount($this->cityId());
    }

    public function getReviews(): Collection
    {
        return Order::query()
            ->where('city_id', $this->cityId())
            ->where('driver_rating', '<=', 2)
            ->where('driver_rating', '>', 0)
            ->where('rating_hidden', false)
            ->whereNull('rating_handled_at')
            ->with('driver:id,name')
            ->orderByDesc('rated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'delivery_man_id', 'driver_rating', 'driver_rating_note', 'rated_at'])
            ->map(fn ($o) => [
                'code' => $o->code ?: '#'.$o->id,
                'driver' => $o->driver?->name ?? '—',
                'rating' => (int) $o->driver_rating,
                'note' => $o->driver_rating_note,
                'when' => $o->rated_at?->diffForHumans(['short' => true, 'parts' => 1]),
                'url' => DriverRatingResource::getUrl('view', ['record' => $o->id]),
            ]);
    }
}
