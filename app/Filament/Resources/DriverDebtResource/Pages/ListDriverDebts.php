<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use App\Filament\Resources\DriverDebtResource\Widgets\DriverDebtStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverDebt;

class ListDriverDebts extends ListRecords
{
    protected static string $resource = DriverDebtResource::class;

    public function getHeading(): string
    {
        return 'Công nợ tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Phí tuần, phạt Offline và các khoản phải thu. Mọi thay đổi đều được ghi nhật ký.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo công nợ')->icon('heroicon-o-plus')->visible(DriverDebtResource::canManage())];
    }

    protected function getHeaderWidgets(): array
    {
        return [DriverDebtStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    public function getTabs(): array
    {
        $base = fn () => DriverDebtResource::scopeEloquentQueryToTenant(DriverDebt::query(), Filament::getTenant());

        $open = fn ($q) => $q->whereIn('status', ['pending', 'overdue']);
        $overdue = fn ($q) => $q->where('status', 'overdue');
        // Cùng điều kiện chặn nhận đơn như KPI (DriverDebtService::blocking).
        $debtOn = OperationalSettings::debtEnabled(Filament::getTenant()?->id);
        $blocked = fn ($q) => $q->where('status', 'overdue')->whereColumn('amount_due', '>', 'amount_paid')
            ->whereRaw($debtOn ? '1 = 1' : '1 = 0')
            ->whereHas('driver', fn ($d) => $d->where('status', 1)->where(fn ($e) => $e->whereNull('debt_enabled')->orWhere('debt_enabled', true)));
        $paid = fn ($q) => $q->where('status', 'paid');

        $tab = fn (string $label, ?callable $scope, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn ($query) => $scope ? $scope($query) : $query)
            ->badge(($scope ? $scope($base()) : $base())->count() ?: null)
            ->badgeColor($color);

        return [
            'open' => $tab('Cần thu', $open, 'warning'),
            'overdue' => $tab('Quá hạn', $overdue, 'danger'),
            'blocked' => $tab('Tài xế bị chặn', $blocked, 'danger'),
            'paid' => $tab('Đã thu', $paid, 'success'),
            'all' => $tab('Tất cả', null),
        ];
    }
}
