<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Hồ sơ khách hàng · '.$this->record->phone.' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa khách hàng')->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin khách hàng')
                ->icon('heroicon-o-identification')
                ->schema([
                    Infolists\Components\ImageEntry::make('profile_photo_path')
                        ->label('Ảnh đại diện')->disk('public')->circular()
                        ->defaultImageUrl('https://ui-avatars.com/api/?name=Customer&background=f97316&color=fff'),
                    Infolists\Components\TextEntry::make('name')->label('Họ tên')->weight('bold'),
                    Infolists\Components\TextEntry::make('phone')->label('Số điện thoại')->copyable(),
                    Infolists\Components\TextEntry::make('email')->label('Email')->placeholder('—'),
                    Infolists\Components\TextEntry::make('city.name')->label('Thành phố')->badge()->color('info')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) {
                            0 => 'Chờ duyệt', 1 => 'Hoạt động', 2 => 'Bị khoá', default => 'Không rõ'
                        })
                        ->color(fn ($state) => match ((int) $state) {
                            0 => 'warning', 1 => 'success', 2 => 'danger', default => 'gray'
                        }),
                    Infolists\Components\TextEntry::make('created_at')->label('Ngày đăng ký')->dateTime('H:i · d/m/Y'),
                ])->columns(3),

            Infolists\Components\Section::make('Hoạt động đơn hàng')
                ->description('Tổng hợp các đơn đặt từ ứng dụng khách hàng')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    Infolists\Components\TextEntry::make('total_orders')->label('Tổng đơn')
                        ->state(fn ($record) => $this->orderQuery($record->id)->count())->badge()->color('primary'),
                    Infolists\Components\TextEntry::make('active_orders')->label('Đang xử lý')
                        ->state(fn ($record) => $this->orderQuery($record->id)->whereIn('status', ['pending', 'assigned', 'processing'])->count())->badge()->color('info'),
                    Infolists\Components\TextEntry::make('completed_orders')->label('Hoàn thành')
                        ->state(fn ($record) => $this->orderQuery($record->id)->where('status', 'completed')->count())->badge()->color('success'),
                    Infolists\Components\TextEntry::make('addresses_count')->label('Địa chỉ đã lưu')
                        ->state(fn ($record) => $record->customerAddresses()->count())->badge()->color('gray'),
                ])->columns(4),
        ]);
    }

    private function orderQuery(int $customerId): Builder
    {
        return Order::where('sender_platform_id', $customerId)->where('platform', 'customer_app');
    }
}
