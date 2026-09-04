<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Hồ sơ cửa hàng · '.$this->record->phone.' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa cửa hàng')->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin cửa hàng')
                ->icon('heroicon-o-building-storefront')
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Tên shop')
                        ->weight('bold'),

                    Infolists\Components\TextEntry::make('phone')
                        ->label('Số điện thoại')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('address')
                        ->label('Địa chỉ lấy hàng')
                        ->default('—')
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('email')
                        ->label('Email')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('city.name')
                        ->label('Thành phố')
                        ->badge()
                        ->color('info'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) {
                            1 => 'Hoạt động', 2 => 'Bị khoá', default => 'Không rõ',
                        })
                        ->color(fn ($state) => match ((int) $state) {
                            1 => 'success', 2 => 'danger', default => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Ngày đăng ký')
                        ->dateTime('d/m/Y H:i'),
                ])->columns(2),

            Infolists\Components\Section::make('Thống kê đơn hàng')
                ->description('Tổng hợp toàn bộ đơn từ ứng dụng cửa hàng')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    Infolists\Components\TextEntry::make('total_orders')
                        ->label('Tổng đơn')
                        ->state(fn ($record) => $record->shop_orders_count)
                        ->badge()->color('primary'),

                    Infolists\Components\TextEntry::make('pending_orders')
                        ->label('Đang chạy')
                        ->state(fn ($record) => $record->active_orders_count)
                        ->badge()->color('info'),

                    Infolists\Components\TextEntry::make('completed_orders')
                        ->label('Hoàn thành')
                        ->state(fn ($record) => $record->completed_orders_count)
                        ->badge()->color('success'),

                    Infolists\Components\TextEntry::make('cancelled_orders')
                        ->label('Đã huỷ')
                        ->state(fn ($record) => $record->cancelled_orders_count)
                        ->badge()->color('danger'),
                ])->columns(4),
        ]);
    }
}
