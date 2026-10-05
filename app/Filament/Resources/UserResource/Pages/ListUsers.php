<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Widgets\CustomerStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /** Từ bấy nhiêu đơn hoàn thành trở lên là "khách quen". */
    public const LOYAL_MIN_ORDERS = 3;

    /** "Mới đăng ký" = trong bấy nhiêu ngày gần nhất. */
    public const NEW_DAYS = 7;

    public function getHeading(): string
    {
        return 'Quản lý khách hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Tài khoản đã đăng ký trên app khách hàng. Đơn đặt qua tổng đài không gắn với tài khoản nào ở đây.';
    }

    protected function getHeaderWidgets(): array
    {
        return [CustomerStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    private function loyal(Builder $query): Builder
    {
        return $query->whereHas('customerOrders', fn (Builder $q) => $q->where('status', 'completed'), '>=', self::LOYAL_MIN_ORDERS);
    }

    public function getTabs(): array
    {
        // Khu vực chọn ở bộ chuyển trên topbar, nên không cần tab theo thành phố.
        $base = fn () => User::query()->where('user_type', 'customer')->where('city_id', Filament::getTenant()?->id);
        $since = now()->subDays(self::NEW_DAYS);

        return [
            'all' => Tab::make('Tất cả')
                ->badge($base()->count() ?: null),

            'new' => Tab::make('Mới đăng ký')
                ->icon('heroicon-m-sparkles')
                ->badge($base()->where('created_at', '>=', $since)->count() ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('users.created_at', '>=', $since)),

            'never' => Tab::make('Chưa đặt đơn')
                ->icon('heroicon-m-clock')
                ->badge($base()->whereDoesntHave('customerOrders')->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('customerOrders')),

            'loyal' => Tab::make('Khách quen')
                ->icon('heroicon-m-heart')
                ->badge($this->loyal($base())->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $this->loyal($query)),

            'locked' => Tab::make('Bị khóa')
                ->icon('heroicon-m-lock-closed')
                ->badge($base()->where('status', 2)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 2)),
        ];
    }
}
