<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Driver\Models\DriverLeaveRequest;

class ListDriverLeaveRequests extends ListRecords
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getHeading(): string
    {
        return 'Lịch nghỉ tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Yêu cầu nghỉ tài xế gửi từ app cần được duyệt mới có hiệu lực. Admin ghi nhận trực tiếp thì có hiệu lực ngay.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Ghi nhận nghỉ phép')->icon('heroicon-o-plus'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $query = fn () => DriverLeaveRequestResource::scopeEloquentQueryToTenant(
            DriverLeaveRequest::query(),
            Filament::getTenant(),
        )->whereDate('leave_date', '>=', today());

        if ($query()->where('status', DriverLeaveRequest::PENDING)->exists()) {
            return 'pending';
        }

        return $query()->exists() ? 'upcoming' : 'past';
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\DriverLeaveRequestResource\Widgets\LeaveImpactWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /** Tab Chờ duyệt tự làm mới: yêu cầu mới hiện ra không cần tải lại trang. */
    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)->poll($this->activeTab === 'pending' ? '30s' : null);
    }

    public function getTabs(): array
    {
        $query = fn () => DriverLeaveRequestResource::scopeEloquentQueryToTenant(
            DriverLeaveRequest::query(),
            Filament::getTenant(),
        );

        $pending = fn (Builder $query) => $query->where('status', DriverLeaveRequest::PENDING)->whereDate('leave_date', '>=', today());
        $todayApproved = fn (Builder $query) => $query->where('status', DriverLeaveRequest::APPROVED)->whereDate('leave_date', today());

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing($pending)
                ->badge($pending($query())->count() ?: null)
                ->badgeColor('warning'),
            'today' => Tab::make('Nghỉ hôm nay')
                ->modifyQueryUsing($todayApproved)
                ->badge($todayApproved($query())->count() ?: null)
                ->badgeColor('success'),
            'upcoming' => Tab::make('Hiện tại & sắp tới')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('leave_date', '>=', today()))
                ->badge($query()->whereDate('leave_date', '>=', today())->count() ?: null)
                ->badgeColor('info'),
            'past' => Tab::make('Đã kết thúc')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('leave_date', '<', today()))
                ->badge($query()->whereDate('leave_date', '<', today())->count() ?: null)
                ->badgeColor('gray'),
        ];
    }
}
