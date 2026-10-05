<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use App\Filament\Resources\ShopResource\Widgets\ShopStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    public function getHeading(): string
    {
        return 'Quản lý cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Tài khoản đã đăng ký trên app cửa hàng. Hoạt động tính cả đơn từ app và đơn tổng đài khớp số điện thoại.';
    }

    protected function getHeaderWidgets(): array
    {
        return [ShopStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    private function cutoff()
    {
        return now()->subDays(ShopResource::ACTIVE_DAYS)->toDateTimeString();
    }

    /** Điều kiện theo mức hoạt động; dùng chung cho đếm huy hiệu và lọc bảng. */
    private function scoped(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'active' => $query->whereRaw(ShopResource::LAST_ACTIVITY.' >= ?', [$this->cutoff()]),
            'dormant' => $query->whereRaw(ShopResource::LAST_ACTIVITY.' > ?', ['1970-01-01 00:00:01'])->whereRaw(ShopResource::LAST_ACTIVITY.' < ?', [$this->cutoff()]),
            'never' => $query->whereRaw(ShopResource::LAST_ACTIVITY.' < ?', ['1970-01-01 00:00:01']),
            default => $query,
        };
    }

    public function getTabs(): array
    {
        // Khu vực chọn ở bộ chuyển trên topbar, nên không cần tab theo thành phố.
        $base = fn () => ShopResource::joinCallCenter(User::query())->where('user_type', 'shop')->where('users.city_id', Filament::getTenant()?->id);

        return [
            'all' => Tab::make('Tất cả')->badge($base()->count() ?: null),

            'active' => Tab::make('Đang hoạt động')
                ->icon('heroicon-m-check-circle')
                ->badge($this->scoped($base(), 'active')->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $this->scoped($query, 'active')),

            'dormant' => Tab::make('Ngủ đông')
                ->icon('heroicon-m-moon')
                ->badge($this->scoped($base(), 'dormant')->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $this->scoped($query, 'dormant')),

            'never' => Tab::make('Chưa đặt đơn')
                ->icon('heroicon-m-clock')
                ->badge($this->scoped($base(), 'never')->count() ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $this->scoped($query, 'never')),

            'locked' => Tab::make('Bị khóa')
                ->icon('heroicon-m-lock-closed')
                ->badge($base()->where('status', 2)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 2)),
        ];
    }
}
