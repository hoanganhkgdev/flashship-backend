<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Modules\Order\Models\Order;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin shop')
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
                ->schema([
                    Infolists\Components\TextEntry::make('total_orders')
                        ->label('Tổng đơn')
                        ->state(fn ($record) => Order::where('sender_platform_id', $record->id)->where('platform', 'shop_app')->count())
                        ->badge()->color('primary'),

                    Infolists\Components\TextEntry::make('pending_orders')
                        ->label('Đang chạy')
                        ->state(fn ($record) => Order::where('sender_platform_id', $record->id)
                            ->where('platform', 'shop_app')
                            ->whereIn('status', ['pending','assigned','processing','on_the_way'])
                            ->count())
                        ->badge()->color('info'),

                    Infolists\Components\TextEntry::make('completed_orders')
                        ->label('Hoàn thành')
                        ->state(fn ($record) => Order::where('sender_platform_id', $record->id)
                            ->where('platform', 'shop_app')
                            ->where('status', 'completed')
                            ->count())
                        ->badge()->color('success'),

                    Infolists\Components\TextEntry::make('cancelled_orders')
                        ->label('Đã huỷ')
                        ->state(fn ($record) => Order::where('sender_platform_id', $record->id)
                            ->where('platform', 'shop_app')
                            ->where('status', 'cancelled')
                            ->count())
                        ->badge()->color('danger'),
                ])->columns(4),
        ]);
    }
}
