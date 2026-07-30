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

    protected static array $statusTextColors = [
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
            // ── Cột trái: các trường có thể sửa ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Trạng thái')->schema([
                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options(self::$statusLabels)
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('cancel_reason')
                        ->label('Lý do huỷ')
                        ->visible(fn ($get) => $get('status') === 'cancelled'),
                ])->columns(2),

                Forms\Components\Section::make('Điểm lấy hàng')->schema([
                    Forms\Components\TextInput::make('pickup_address')->label('Địa chỉ lấy')
                        ->extraInputAttributes(['id' => 'edit-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('pickupMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditPickupMap')),
                        ]),
                    Forms\Components\TextInput::make('pickup_phone')->label('SĐT lấy hàng'),
                ])->columns(2),

                Forms\Components\Section::make('Điểm giao hàng')->schema([
                    Forms\Components\TextInput::make('delivery_address')->label('Địa chỉ giao')
                        ->extraInputAttributes(['id' => 'edit-delivery-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('deliveryMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditDeliveryMap')),
                        ]),
                    Forms\Components\TextInput::make('delivery_phone')->label('SĐT giao hàng'),
                ])->columns(2),

                Forms\Components\Section::make('Phí & thanh toán')->schema([
                    Forms\Components\TextInput::make('shipping_fee')->label('Phí ship')->numeric()->suffix('đ'),
                ])->columns(1),

                Forms\Components\Section::make('Ghi chú')->schema([
                    Forms\Components\Textarea::make('order_note')->label('Ghi chú đơn hàng')->rows(8)->columnSpanFull(),
                ]),

            ])->columnSpan(2),

            // ── Cột phải: thông tin chỉ xem ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Thông tin đơn')->schema([
                    Forms\Components\Placeholder::make('info_display')
                        ->label('')
                        ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                            '<div style="line-height:2">'
                            . '<b>Mã đơn:</b> #' . e($record->code) . '<br>'
                            . '<b>Dịch vụ:</b> ' . e(self::serviceLabels()[$record->service_type] ?? $record->service_type) . '<br>'
                            . '<b>Khu vực:</b> ' . e($record->city?->name ?? '—') . '<br>'
                            . '<b>Nguồn đơn:</b> ' . e(match ($record->platform) {
                                'customer_app' => 'App khách',
                                'call_center'  => 'Tổng đài',
                                'shop_app'     => 'App cửa hàng',
                                default        => $record->platform ?? '—',
                            }) . '<br>'
                            . '<b>Tạo lúc:</b> ' . e($record->created_at?->format('d/m/Y H:i')) . '<br>'
                            . '<b>Hoàn thành:</b> ' . e($record->completed_at?->format('d/m/Y H:i') ?? '—')
                            . '</div>'
                        )),
                ]),

                Forms\Components\Section::make('Tài xế')->schema([
                    Forms\Components\Placeholder::make('driver_info_display')
                        ->label('')
                        ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                            '<div style="line-height:2">'
                            . '<b>Tài xế:</b> ' . e($record->driver?->name ?? 'Chưa phân công') . '<br>'
                            . '<b>SĐT:</b> ' . ($record->driver?->phone
                                ? '<a href="https://zalo.me/' . e(ltrim($record->driver->phone, '0')) . '" target="_blank" style="text-decoration:underline">' . e($record->driver->phone) . '</a>'
                                : '—')
                            . '</div>'
                        )),
                ]),

                Forms\Components\Section::make('Chi tiết phí')->schema([
                    Forms\Components\Placeholder::make('fee_info_display')
                        ->label('')
                        ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                            '<div style="line-height:2">'
                            . '<b>Khoảng cách:</b> ' . e($record->distance ? number_format((float) $record->distance, 1) . ' km' : '—') . '<br>'
                            . '<b>Phụ phí đêm:</b> ' . e(number_format($record->night_surcharge ?? 0)) . 'đ<br>'
                            . '<b>Giảm giá:</b> ' . e($record->discount_amount ? number_format($record->discount_amount) . 'đ' : '—') . ($record->voucher_code ? ' (' . e($record->voucher_code) . ')' : '') . '<br>'
                            . '<b>Thanh toán:</b> ' . e(self::$paymentLabels[$record->payment_method] ?? $record->payment_method)
                            . '</div>'
                        )),
                ]),

            ])->columnSpan(1),

        ])->columns(3);
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
                        ->searchable(query: function (Builder $query, string $search): Builder {
                            return $query->where(function ($q) use ($search) {
                                $q->where('code', 'like', "%{$search}%")
                                  ->orWhere('pickup_phone', 'like', "%{$search}%")
                                  ->orWhere('delivery_phone', 'like', "%{$search}%")
                                  ->orWhere('pickup_address', 'like', "%{$search}%")
                                  ->orWhere('delivery_address', 'like', "%{$search}%")
                                  ->orWhere('order_note', 'like', "%{$search}%")
                                  ->orWhereHas('driver', fn ($q) => $q->where('phone', 'like', "%{$search}%"));
                            });
                        })
                        ->copyable()
                        ->formatStateUsing(fn ($state, $record) =>
                            '<span style="font-weight:700">#' . e($state) . '</span>'
                            . '<span style="color:#9ca3af;margin:0 6px">·</span>'
                            . '<span style="font-weight:700;color:rgb(var(--primary-500))">' . e(self::serviceLabels()[$record->service_type] ?? $record->service_type) . '</span>'
                            . '<span style="color:#9ca3af;margin:0 6px">·</span>'
                            . '<span style="font-weight:600;color:' . self::$statusTextColors[$record->status] . '">' . e(self::$statusLabels[$record->status] ?? $record->status) . '</span>'
                            . ($record->status === 'pending' && $record->cancel_reason === 'no_driver'
                                ? '<br><span style="color:#ef4444;font-size:11px;font-weight:600">⚠ Không tìm được tài xế — cần xử lý</span>'
                                : '')
                            . ($record->status === 'on_the_way' && $record->updated_at?->lt(now()->subMinutes(30))
                                ? '<br><span style="color:#ef4444;font-size:11px;font-weight:600">⚠ Đang giao ' . $record->updated_at->diffInMinutes(now()) . ' phút — chưa hoàn thành</span>'
                                : '')
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

                // Dòng 4: ghi chú
                Tables\Columns\TextColumn::make('order_note')
                    ->icon('heroicon-m-chat-bubble-left-ellipsis')
                    ->iconColor('warning')
                    ->formatStateUsing(fn ($state) => \Illuminate\Support\Str::limit($state, 50))
                    ->tooltip(fn ($record) => $record->order_note)
                    ->size('sm')
                    ->color('gray')
                    ->placeholder('—'),

                // Dòng 5: tài xế (trái) | phí + thanh toán (phải)
                Split::make([
                    Tables\Columns\TextColumn::make('driver.name')
                        ->icon('heroicon-m-user-circle')
                        ->iconColor('gray')
                        ->default('Chưa phân công')
                        ->formatStateUsing(fn ($state, $record) =>
                            $record->driver
                                ? e($state) . '<span style="color:#9ca3af;margin:0 6px">·</span>' . '<a href="https://zalo.me/' . e(ltrim($record->driver->phone ?? '', '0')) . '" target="_blank" style="text-decoration:underline">' . e($record->driver->phone ?? '') . '</a>'
                                : $state
                        )
                        ->html()
                        ->color(fn ($record) => $record->driver_id ? 'primary' : 'gray')
                        ->size('sm')
                        ->grow(true),
                    Tables\Columns\TextColumn::make('shipping_fee')
                        ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ')
                        ->weight('bold')
                        ->color('primary')
                        ->alignEnd()
                        ->grow(false),
                ]),
            ])
            ->contentGrid([
                'default' => 1,
                'sm'      => 2,
                'xl'      => 3,
            ])
            ->recordUrl(null)
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->label(''),

                Tables\Actions\DeleteAction::make()->label(''),

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

                Tables\Actions\Action::make('reorder')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->tooltip('Đặt lại')
                    ->visible(fn (Order $record) => in_array($record->status, ['cancelled', 'completed']))
                    ->url(fn (Order $record) => \App\Filament\Pages\CallCenterPage::getUrl() . '?' . http_build_query(array_filter([
                        'reorder'          => $record->id,
                        'service'          => $record->service_type,
                        'city_id'          => $record->city_id,
                        'pickup_address'   => $record->pickup_address,
                        'pickup_phone'     => $record->pickup_phone,
                        'pickup_lat'       => $record->pickup_lat,
                        'pickup_lng'       => $record->pickup_lng,
                        'delivery_address' => $record->delivery_address,
                        'delivery_phone'   => $record->delivery_phone,
                        'delivery_lat'     => $record->delivery_lat,
                        'delivery_lng'     => $record->delivery_lng,
                        'order_note'       => $record->order_note,
                    ]))),

            ])
            ->bulkActions([])
            ->actionsAlignment('end')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(12)
            ->paginationPageOptions([12, 24, 48])
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
