<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
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
        return 'Theo dõi kỳ đối soát, số tiền còn lại và trạng thái thanh toán.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo công nợ')->icon('heroicon-o-plus')];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'unpaid';
    }

    public function getTabs(): array
    {
        $query = fn () => DriverDebtResource::scopeEloquentQueryToTenant(
            DriverDebt::query(),
            Filament::getTenant(),
        );

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'unpaid' => Tab::make('Chưa thanh toán')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($query()->where('status', 'pending')->count() ?: null)
                ->badgeColor('warning'),
            'overdue' => Tab::make('Quá hạn')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'overdue'))
                ->badge($query()->where('status', 'overdue')->count() ?: null)
                ->badgeColor('danger'),
            'paid' => Tab::make('Đã thanh toán')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid'))
                ->badge($query()->where('status', 'paid')->count() ?: null)
                ->badgeColor('success'),
        ];
    }
}
