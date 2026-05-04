<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Modules\Order\Models\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Đơn hàng';

    protected static ?string $modelLabel = 'Đơn hàng';

    protected static ?string $pluralModelLabel = 'Đơn hàng';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Mã đơn')
                    ->disabled(),

                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending'    => 'Chờ xử lý',
                        'assigned'   => 'Đã phân công',
                        'delivering' => 'Đang giao',
                        'completed'  => 'Hoàn thành',
                        'cancelled'  => 'Đã huỷ',
                    ])
                    ->required(),

                Forms\Components\TextInput::make('pickup_address')
                    ->label('Địa chỉ lấy hàng'),

                Forms\Components\TextInput::make('delivery_address')
                    ->label('Địa chỉ giao hàng'),

                Forms\Components\TextInput::make('shipping_fee')
                    ->label('Phí giao hàng')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã đơn')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('service_type')
                    ->label('Loại dịch vụ')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'delivery' => 'Giao hàng',
                        'shopping' => 'Mua sắm',
                        'ride'     => 'Đặt xe',
                        'car'      => 'Thuê xe',
                        default    => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'    => 'Chờ xử lý',
                        'assigned'   => 'Đã phân công',
                        'delivering' => 'Đang giao',
                        'completed'  => 'Hoàn thành',
                        'cancelled'  => 'Đã huỷ',
                        default      => $state,
                    })
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'assigned',
                        'primary' => 'delivering',
                        'success' => 'completed',
                        'danger'  => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('pickup_address')
                    ->label('Địa chỉ lấy hàng')
                    ->limit(30),

                Tables\Columns\TextColumn::make('delivery_address')
                    ->label('Địa chỉ giao hàng')
                    ->limit(30),

                Tables\Columns\TextColumn::make('shipping_fee')
                    ->label('Phí giao hàng')
                    ->money('VND', locale: 'vi')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Thanh toán')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cod'      => 'COD',
                        'prepaid'  => 'Đã thanh toán',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending'    => 'Chờ xử lý',
                        'assigned'   => 'Đã phân công',
                        'delivering' => 'Đang giao',
                        'completed'  => 'Hoàn thành',
                        'cancelled'  => 'Đã huỷ',
                    ]),

                SelectFilter::make('service_type')
                    ->label('Loại dịch vụ')
                    ->options([
                        'delivery' => 'Giao hàng',
                        'shopping' => 'Mua sắm',
                        'ride'     => 'Đặt xe',
                        'car'      => 'Thuê xe',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
