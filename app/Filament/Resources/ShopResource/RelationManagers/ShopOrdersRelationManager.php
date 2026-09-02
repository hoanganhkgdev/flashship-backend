<?php

namespace App\Filament\Resources\ShopResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ShopOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'shopOrders';

    protected static ?string $title = 'Đơn hàng';

    protected static ?string $label = 'đơn hàng của cửa hàng';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã đơn')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cargo_type')
                    ->label('Loại hàng')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'food' => '🍱 Đồ ăn',
                        'flowers' => '🌸 Hoa/Trái cây',
                        'parcel' => '📦 Bưu kiện',
                        default => $state,
                    })
                    ->color('gray'),

                Tables\Columns\TextColumn::make('pickup_address')
                    ->label('Lấy hàng')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->pickup_address),

                Tables\Columns\TextColumn::make('delivery_address')
                    ->label('Giao hàng')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->delivery_address),

                Tables\Columns\TextColumn::make('shipping_fee')
                    ->label('Phí ship')
                    ->money('VND')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Đang tìm tài xế',
                        'assigned' => 'Tài xế đã nhận',
                        'processing' => 'Đã lấy hàng',
                        'completed' => 'Hoàn thành',
                        'cancelled' => 'Đã huỷ',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'assigned', 'processing' => 'info',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending' => 'Đang tìm tài xế',
                        'assigned' => 'Tài xế đã nhận',
                        'processing' => 'Đã lấy hàng',
                        'completed' => 'Hoàn thành',
                        'cancelled' => 'Đã huỷ',
                    ]),

                Tables\Filters\SelectFilter::make('cargo_type')
                    ->label('Loại hàng')
                    ->options([
                        'food' => '🍱 Đồ ăn',
                        'flowers' => '🌸 Hoa/Trái cây',
                        'parcel' => '📦 Bưu kiện',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
