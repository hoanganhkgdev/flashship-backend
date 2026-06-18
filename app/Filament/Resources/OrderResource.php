<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user?->user_type === 'city_manager' && $user->city_id) {
            $query->where('city_id', $user->city_id);
        }

        return $query;
    }

    protected static ?string $navigationIcon  = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Đơn hàng';
    protected static ?string $modelLabel      = 'Đơn hàng';
    protected static ?string $pluralModelLabel = 'Đơn hàng';
    protected static ?int    $navigationSort  = 1;

    private static function serviceLabels(): array
    {
        static $cache = null;
        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    private static array $statusLabels = [
        'pending'    => 'Chờ tài xế',
        'assigned'   => 'Đã phân công',
        'processing' => 'Đang lấy hàng',
        'on_the_way' => 'Đang giao',
        'completed'  => 'Hoàn thành',
        'cancelled'  => 'Đã huỷ',
    ];

    private static array $statusColors = [
        'pending'    => 'warning',
        'assigned'   => 'info',
        'processing' => 'primary',
        'on_the_way' => 'primary',
        'completed'  => 'success',
        'cancelled'  => 'danger',
    ];

    private static array $statusTextColors = [
        'pending'    => '#f59e0b',
        'assigned'   => '#3b82f6',
        'processing' => '#f97316',
        'on_the_way' => '#8b5cf6',
        'completed'  => '#22c55e',
        'cancelled'  => '#ef4444',
    ];

    private static array $paymentLabels = [
        'cod'     => 'COD',
        'prepaid' => 'Thanh toán trước',
        'wallet'  => 'Ví',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Trạng thái & dịch vụ')->schema([
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options(self::$statusLabels)
                    ->required(),

                Forms\Components\Select::make('service_type')
                    ->label('Loại dịch vụ')
                    ->options(self::serviceLabels())
                    ->disabled(),

                Forms\Components\TextInput::make('cancel_reason')
                    ->label('Lý do huỷ')
                    ->visible(fn ($get) => $get('status') === 'cancelled'),
            ])->columns(3),

            Forms\Components\Section::make('Lấy hàng')->schema([
                Forms\Components\TextInput::make('sender_name')->label('Người gửi'),
                Forms\Components\TextInput::make('pickup_phone')->label('SĐT lấy hàng'),
                Forms\Components\TextInput::make('pickup_address')->label('Địa chỉ lấy hàng')->columnSpan(2),
            ])->columns(2),

            Forms\Components\Section::make('Giao hàng')->schema([
                Forms\Components\TextInput::make('receiver_name')->label('Người nhận'),
                Forms\Components\TextInput::make('delivery_phone')->label('SĐT giao hàng'),
                Forms\Components\TextInput::make('delivery_address')->label('Địa chỉ giao hàng')->columnSpan(2),
            ])->columns(2),

            Forms\Components\Section::make('Tài chính')->schema([
                Forms\Components\TextInput::make('shipping_fee')->label('Phí ship')->numeric()->suffix('đ'),
                Forms\Components\TextInput::make('bonus_fee')->label('Thưởng thêm')->numeric()->suffix('đ'),
                Forms\Components\TextInput::make('cod_amount')->label('Thu hộ COD')->numeric()->suffix('đ'),
                Forms\Components\TextInput::make('discount_amount')->label('Giảm giá')->numeric()->suffix('đ'),
                Forms\Components\TextInput::make('distance')->label('Khoảng cách')->numeric()->suffix('km'),
                Forms\Components\Select::make('payment_method')->label('Thanh toán')->options(self::$paymentLabels),
            ])->columns(3),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin đơn')->schema([
                Infolists\Components\TextEntry::make('code')
                    ->label('Mã đơn')
                    ->weight('bold')
                    ->copyable(),

                Infolists\Components\TextEntry::make('service_type')
                    ->label('Dịch vụ')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::serviceLabels()[$state] ?? $state)
                    ->color('info'),

                Infolists\Components\TextEntry::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::$statusLabels[$state] ?? $state)
                    ->color(fn ($state) => self::$statusColors[$state] ?? 'gray'),

                Infolists\Components\TextEntry::make('city.name')
                    ->label('Thành phố')
                    ->default('—'),

                Infolists\Components\TextEntry::make('cancel_reason')
                    ->label('Lý do huỷ')
                    ->default('—')
                    ->visible(fn ($record) => $record->status === 'cancelled'),

                Infolists\Components\TextEntry::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i'),

                Infolists\Components\TextEntry::make('completed_at')
                    ->label('Hoàn thành lúc')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])->columns(3),

            Infolists\Components\Section::make('Lấy hàng')->schema([
                Infolists\Components\TextEntry::make('sender_name')->label('Người gửi')->default('—'),
                Infolists\Components\TextEntry::make('pickup_phone')->label('SĐT')->default('—'),
                Infolists\Components\TextEntry::make('store_name')->label('Tên cửa hàng')->default('—'),
                Infolists\Components\TextEntry::make('pickup_address')->label('Địa chỉ')->columnSpan(2),
            ])->columns(3),

            Infolists\Components\Section::make('Giao hàng')->schema([
                Infolists\Components\TextEntry::make('receiver_name')->label('Người nhận')->default('—'),
                Infolists\Components\TextEntry::make('delivery_phone')->label('SĐT')->default('—'),
                Infolists\Components\TextEntry::make('delivery_address')->label('Địa chỉ')->columnSpan(2),
            ])->columns(3),

            Infolists\Components\Section::make('Tài xế')->schema([
                Infolists\Components\TextEntry::make('driver.name')
                    ->label('Tên tài xế')
                    ->default('Chưa phân công'),

                Infolists\Components\TextEntry::make('driver.phone')
                    ->label('SĐT tài xế')
                    ->default('—'),

                Infolists\Components\TextEntry::make('dispatch_attempts')
                    ->label('Lần thử dispatch')
                    ->default(0),

                Infolists\Components\TextEntry::make('driver_rating')
                    ->label('Đánh giá')
                    ->default('Chưa đánh giá')
                    ->suffix(fn ($state) => $state ? ' ⭐' : ''),

                Infolists\Components\TextEntry::make('driver_rating_note')
                    ->label('Ghi chú đánh giá')
                    ->default('—'),
            ])->columns(3),

            Infolists\Components\Section::make('Tài chính')->schema([
                Infolists\Components\TextEntry::make('shipping_fee')
                    ->label('Phí ship')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ'),

                Infolists\Components\TextEntry::make('bonus_fee')
                    ->label('Thưởng thêm')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                Infolists\Components\TextEntry::make('cod_amount')
                    ->label('Thu hộ COD')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) . 'đ' : '—'),

                Infolists\Components\TextEntry::make('discount_amount')
                    ->label('Giảm giá')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) . 'đ' : '—'),

                Infolists\Components\TextEntry::make('distance')
                    ->label('Khoảng cách')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 1) . ' km' : '—'),

                Infolists\Components\TextEntry::make('payment_method')
                    ->label('Thanh toán')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::$paymentLabels[$state] ?? $state)
                    ->color('info'),

                Infolists\Components\TextEntry::make('voucher_code')
                    ->label('Voucher')
                    ->default('—'),

                Infolists\Components\IconEntry::make('is_freeship')
                    ->label('Freeship')
                    ->boolean(),
            ])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Dòng 1: mã đơn + badge dịch vụ (trái) | giờ (phải)
                Split::make([
                    Tables\Columns\TextColumn::make('code')
                        ->searchable()
                        ->copyable()
                        ->formatStateUsing(fn ($state, $record) =>
                            '<span style="font-weight:700">#' . e($state) . '</span>'
                            . '<span style="color:#9ca3af;margin:0 6px">·</span>'
                            . '<span style="font-weight:700;color:rgb(var(--primary-500))">' . e(self::serviceLabels()[$record->service_type] ?? $record->service_type) . '</span>'
                            . '<span style="color:#9ca3af;margin:0 6px">·</span>'
                            . '<span style="font-weight:600;color:' . self::$statusTextColors[$record->status] . '">' . e(self::$statusLabels[$record->status] ?? $record->status) . '</span>'
                        )
                        ->html()
                        ->grow(true),
                    Tables\Columns\TextColumn::make('created_at')
                        ->dateTime('d/m H:i')
                        ->color('gray')
                        ->size('xs')
                        ->alignEnd()
                        ->grow(false),
                ]),

                // Dòng 3: địa chỉ lấy → giao
                Stack::make([
                    Tables\Columns\TextColumn::make('pickup_address')
                        ->icon('heroicon-m-arrow-up-circle')
                        ->iconColor('warning')
                        ->formatStateUsing(fn ($state) => \Illuminate\Support\Str::limit($state, 35))
                        ->tooltip(fn ($record) => $record->pickup_address)
                        ->size('sm'),
                    Tables\Columns\TextColumn::make('delivery_address')
                        ->icon('heroicon-m-arrow-down-circle')
                        ->iconColor('success')
                        ->formatStateUsing(fn ($state) => \Illuminate\Support\Str::limit($state, 35))
                        ->tooltip(fn ($record) => $record->delivery_address)
                        ->size('sm'),
                ]),

                // Dòng 4: tài xế (trái) | phí + thanh toán (phải)
                Split::make([
                    Tables\Columns\TextColumn::make('driver.name')
                        ->icon('heroicon-m-user-circle')
                        ->iconColor('gray')
                        ->default('Chưa phân công')
                        ->color(fn ($record) => $record->driver_id ? 'primary' : 'gray')
                        ->size('sm')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('shipping_fee')
                        ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ')
                        ->weight('bold')
                        ->color('primary')
                        ->alignEnd(),
                ]),
            ])
            ->contentGrid([
                'default' => 1,
                'sm'      => 2,
                'xl'      => 3,
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->label(''),

                Tables\Actions\Action::make('cancel')
                    ->label('')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->tooltip('Huỷ đơn')
                    ->visible(fn (Order $record) => in_array($record->status, ['pending', 'assigned']))
                    ->requiresConfirmation()
                    ->modalHeading('Huỷ đơn hàng')
                    ->modalDescription(fn (Order $record) => 'Xác nhận huỷ đơn ' . $record->code . '?')
                    ->action(function (Order $record) {
                        $record->update(['status' => 'cancelled', 'cancel_reason' => 'admin']);
                        Notification::make()->title('Đã huỷ đơn ' . $record->code)->warning()->send();
                    }),

            ])
            ->bulkActions([])
            ->actionsAlignment('end')
            ->defaultSort('created_at', 'desc')
            ->poll('15s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'view'   => Pages\ViewOrder::route('/{record}'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
