<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DriverRatingResource;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Order\Models\Order;

/** Đánh giá 1–2 sao chưa xử lý, mới nhất trước, để xử lý khiếu nại sớm. */
class LowRatingsWidget extends Widget
{
    private const LIMIT = 5;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter() && DriverRatingResource::canViewAny();
    }

    protected static string $view = 'filament.widgets.low-ratings';

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected static ?int $sort = 8;

    public function getPending(): int
    {
        return \App\Services\DriverRatingService::pendingCount(Filament::getTenant()?->id);
    }

    public function getReviews(): Collection
    {
        return Order::query()
            ->when(Filament::getTenant()?->id, fn ($q, $cityId) => $q->where('city_id', $cityId))
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
