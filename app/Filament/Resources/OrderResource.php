<?php

namespace App\Filament\Resources;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\ServiceType;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $modelLabel = 'Đơn hàng';

    protected static ?string $pluralModelLabel = 'Đơn hàng';

    protected static ?int $navigationSort = 1;

    private static function serviceLabels(): array
    {
        static $cache = null;

        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    private static array $statusLabels = [
        'pending' => 'Chờ tài xế',
        'assigned' => 'Đã phân công',
        'processing' => 'Đã lấy hàng',
        'completed' => 'Hoàn thành',
        'cancelled' => 'Đã huỷ',
    ];

    private static array $statusColors = [
        'pending' => 'warning',
        'assigned' => 'info',
        'processing' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];

    private static array $paymentLabels = [
        'cod' => 'COD',
        'prepaid' => 'Thanh toán trước',
        'wallet' => 'Ví',
    ];

    private static function journeySummary(Order $record): string
    {
        $source = match ($record->platform) {
            'customer_app' => 'Khách hàng'.($record->sender?->name ? ' · '.$record->sender->name : ''),
            'shop_app' => 'Shop'.($record->sender?->name ? ' · '.$record->sender->name : ''),
            'call_center' => 'Tổng đài',
            default => $record->platform ?: 'Chưa xác định',
        };

        return '<div class="fs-order-journey">'
            .'<div><b class="fs-order-journey__pickup">Điểm lấy:</b><span>'.e($record->pickup_address ?: '—').'</span></div>'
            .'<div><b class="fs-order-journey__delivery">Điểm giao:</b><span>'.e($record->delivery_address ?: '—').'</span></div>'
            .'<div class="fs-order-journey__note-row"><b class="fs-order-journey__note" aria-label="Ghi chú"></b><span>'.e($record->order_note ?: '—').'</span></div>'
            .'<div class="fs-order-journey__meta"><span title="'.e($source).'"><b class="fs-order-journey__source">Nguồn đơn:</b><em>'.e($source).'</em></span><strong class="fs-order-journey__fee">'.number_format((int) $record->shipping_fee, 0, ',', '.').'đ</strong></div>'
            .'</div>';
    }

    private static function orderSummary(Order $record): string
    {
        $service = self::serviceLabels()[$record->service_type] ?? $record->service_type;

        return '<div class="fs-order-summary">'
            .'<div><b class="fs-order-summary__code">Mã đơn:</b><span>#'.e($record->code).'</span></div>'
            .'<div><b class="fs-order-summary__service">Dịch vụ:</b><span>'.e($service ?: '—').'</span></div>'
            .'<div><b class="fs-order-summary__time">Tạo lúc:</b><span>'.$record->created_at?->format('d/m H:i').'</span></div>'
            .'</div>';
    }

    private static function statusSummary(Order $record): string
    {
        $status = self::$statusLabels[$record->status] ?? $record->status;
        $driverName = $record->driver?->name ?: 'Đang tìm tài xế';
        $driverPhone = $record->driver?->phone ?: '—';
        $warning = match (true) {
            $record->status === 'pending' && $record->cancel_reason === 'no_driver' => 'Không tìm được tài xế',
            default => null,
        };

        return '<div class="fs-order-assignment">'
            .'<div><b class="fs-order-assignment__status" aria-label="Trạng thái"></b><span class="fs-order-status-text fs-order-status-text--'.e(self::$statusColors[$record->status] ?? 'gray').'">'.e($status).'</span></div>'
            .'<div><b class="fs-order-assignment__driver">Tài xế:</b><span title="'.e($driverName).'">'.e($driverName).'</span></div>'
            .'<div><b class="fs-order-assignment__phone">SĐT:</b><span>'.e($driverPhone).'</span></div>'
            .($warning ? '<strong class="fs-order-assignment__warning">'.e($warning).'</strong>' : '')
            .'</div>';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'city:id,name',
            'driver:id,name,phone',
            'sender:id,name,phone',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // ── Cột trái: các trường có thể sửa ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Trạng thái')
                    ->description('Cập nhật tiến trình xử lý của đơn hàng')
                    ->icon('heroicon-o-signal')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Trạng thái')
                            ->options(self::$statusLabels)
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('cancel_reason')
                            ->label('Lý do huỷ')
                            ->visible(fn ($get) => $get('status') === 'cancelled'),
                    ])->columns(2),

                Forms\Components\Section::make('Điểm lấy hàng')->icon('heroicon-o-arrow-up-circle')->schema([
                    Forms\Components\TextInput::make('pickup_address')->label('Địa chỉ lấy')
                        ->extraInputAttributes(['id' => 'edit-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('pickupMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditPickupMap')),
                        ]),
                    Forms\Components\TextInput::make('pickup_phone')->label('SĐT lấy hàng'),
                ])->columns(2),

                Forms\Components\Section::make('Điểm giao hàng')->icon('heroicon-o-arrow-down-circle')->schema([
                    Forms\Components\TextInput::make('delivery_address')->label('Địa chỉ giao')
                        ->extraInputAttributes(['id' => 'edit-delivery-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('deliveryMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditDeliveryMap')),
                        ]),
                    Forms\Components\TextInput::make('delivery_phone')->label('SĐT giao hàng'),
                ])->columns(2),

                Forms\Components\Section::make('Phí & thanh toán')->icon('heroicon-o-banknotes')->schema([
                    Forms\Components\TextInput::make('shipping_fee')->label('Phí ship')->numeric()->suffix('đ'),
                ])->columns(1),

                Forms\Components\Section::make('Ghi chú')->icon('heroicon-o-chat-bubble-left-ellipsis')->schema([
                    Forms\Components\Textarea::make('order_note')->label('Ghi chú đơn hàng')->rows(8)->columnSpanFull(),
                ]),

            ])->columnSpan(2),

            // ── Cột phải: thông tin chỉ xem ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Thông tin đơn')->icon('heroicon-o-information-circle')->schema([
                    Forms\Components\Placeholder::make('info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Mã đơn:</b> #'.e($record->code).'<br>'
                            .'<b>Dịch vụ:</b> '.e(self::serviceLabels()[$record->service_type] ?? $record->service_type).'<br>'
                            .'<b>Khu vực:</b> '.e($record->city?->name ?? '—').'<br>'
                            .'<b>Nguồn đơn:</b> '.e(match ($record->platform) {
                                'customer_app' => 'App khách',
                                'call_center' => 'Tổng đài',
                                'shop_app' => 'App cửa hàng',
                                default => $record->platform ?? '—',
                            }).'<br>'
                            .'<b>Tạo lúc:</b> '.e($record->created_at?->format('d/m/Y H:i')).'<br>'
                            .'<b>Hoàn thành:</b> '.e($record->completed_at?->format('d/m/Y H:i') ?? '—')
                            .'</div>'
                        )),
                ]),

                Forms\Components\Section::make('Tài xế')->icon('heroicon-o-truck')->schema([
                    Forms\Components\Placeholder::make('driver_info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Tài xế:</b> '.e($record->driver?->name ?? 'Chưa phân công').'<br>'
                            .'<b>SĐT:</b> '.($record->driver?->phone
                                ? '<a href="https://zalo.me/'.e(ltrim($record->driver->phone, '0')).'" target="_blank" style="text-decoration:underline">'.e($record->driver->phone).'</a>'
                                : '—')
                            .'</div>'
                        )),
                ]),

                Forms\Components\Section::make('Chi tiết phí')->icon('heroicon-o-receipt-percent')->schema([
                    Forms\Components\Placeholder::make('fee_info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Khoảng cách:</b> '.e($record->distance ? number_format((float) $record->distance, 1).' km' : '—').'<br>'
                            .'<b>Phụ phí đêm:</b> '.e(number_format($record->night_surcharge ?? 0)).'đ<br>'
                            .'<b>Giảm giá:</b> '.e($record->discount_amount ? number_format($record->discount_amount).'đ' : '—').($record->voucher_code ? ' ('.e($record->voucher_code).')' : '').'<br>'
                            .'<b>Thanh toán:</b> '.e(self::$paymentLabels[$record->payment_method] ?? $record->payment_method)
                            .'</div>'
                        )),
                ]),

            ])->columnSpan(1),

        ])->columns(3);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tổng quan đơn hàng')
                ->description('Thông tin nhận diện và tiến trình xử lý hiện tại')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
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

            Infolists\Components\Grid::make(['default' => 1, 'lg' => 2])->schema([
                Infolists\Components\Section::make('Điểm lấy hàng')
                    ->description('Thông tin người gửi và nơi nhận hàng')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->schema([
                        Infolists\Components\TextEntry::make('sender_name')->label('Người gửi')->default('—'),
                        Infolists\Components\TextEntry::make('pickup_phone')->label('SĐT')->default('—')->copyable(),
                        Infolists\Components\TextEntry::make('store_name')->label('Tên cửa hàng')->default('—'),
                        Infolists\Components\TextEntry::make('pickup_address')->label('Địa chỉ')->columnSpanFull(),
                    ])->columns(2),

                Infolists\Components\Section::make('Điểm giao hàng')
                    ->description('Thông tin người nhận và nơi giao hàng')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->schema([
                        Infolists\Components\TextEntry::make('receiver_name')->label('Người nhận')->default('—'),
                        Infolists\Components\TextEntry::make('delivery_phone')->label('SĐT')->default('—')->copyable(),
                        Infolists\Components\TextEntry::make('delivery_address')->label('Địa chỉ')->columnSpanFull(),
                    ])->columns(2),
            ]),

            Infolists\Components\Grid::make(['default' => 1, 'lg' => 2])->schema([
                Infolists\Components\Section::make('Tài xế phụ trách')
                    ->description('Thông tin phân công và đánh giá sau chuyến')
                    ->icon('heroicon-o-truck')
                    ->schema([
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
                    ])->columns(2),

                Infolists\Components\Section::make('Chi phí & thanh toán')
                    ->description('Các khoản phí, thu hộ và ưu đãi của đơn')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Infolists\Components\TextEntry::make('shipping_fee')
                            ->label('Phí ship')
                            ->formatStateUsing(fn ($state) => number_format((int) $state).'đ'),

                        Infolists\Components\TextEntry::make('bonus_fee')
                            ->label('Thưởng thêm')
                            ->formatStateUsing(fn ($state) => number_format((int) $state).'đ')
                            ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                        Infolists\Components\TextEntry::make('cod_amount')
                            ->label('Thu hộ COD')
                            ->formatStateUsing(fn ($state) => $state ? number_format((int) $state).'đ' : '—'),

                        Infolists\Components\TextEntry::make('discount_amount')
                            ->label('Giảm giá')
                            ->formatStateUsing(fn ($state) => $state ? number_format((int) $state).'đ' : '—'),

                        Infolists\Components\TextEntry::make('distance')
                            ->label('Khoảng cách')
                            ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 1).' km' : '—'),

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
                    ])->columns(2),
            ]),

            Infolists\Components\Section::make('Hàng hóa & ghi chú')
                ->description('Thông tin cần lưu ý trong quá trình giao nhận')
                ->icon('heroicon-o-archive-box')
                ->schema([
                    Infolists\Components\TextEntry::make('cargo_type')
                        ->label('Loại hàng')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('cargo_weight')
                        ->label('Khối lượng')
                        ->suffix(' kg')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('order_note')
                        ->label('Ghi chú đơn hàng')
                        ->default('Không có ghi chú')
                        ->columnSpanFull(),
                ])->columns(2)->collapsible(),

            Infolists\Components\Section::make('Lịch sử xử lý')
                ->description('Các sự kiện mới nhất của đơn hàng')
                ->icon('heroicon-o-clock')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('histories')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('description')
                                ->label('Sự kiện')
                                ->weight('bold'),
                            Infolists\Components\TextEntry::make('type')
                                ->label('Loại')
                                ->badge()
                                ->color('gray'),
                            Infolists\Components\TextEntry::make('created_at')
                                ->label('Thời gian')
                                ->dateTime('H:i · d/m/Y'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Đơn hàng')
                    ->formatStateUsing(fn ($state, Order $record): string => self::orderSummary($record))
                    ->html()
                    ->copyable()
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search) {
                            $query->where('code', 'like', "%{$search}%")
                                ->orWhere('pickup_phone', 'like', "%{$search}%")
                                ->orWhere('delivery_phone', 'like', "%{$search}%")
                                ->orWhere('pickup_address', 'like', "%{$search}%")
                                ->orWhere('delivery_address', 'like', "%{$search}%")
                                ->orWhere('order_note', 'like', "%{$search}%")
                                ->orWhereHas('driver', fn (Builder $query) => $query
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('journey_summary')
                    ->label('Hành trình & phụ trách')
                    ->state(fn (Order $record): string => self::journeySummary($record))
                    ->html(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state, Order $record): string => self::statusSummary($record))
                    ->html(),

            ])
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                Tables\Filters\Filter::make('created_at')
                    ->label('Ngày tạo đơn')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
                SelectFilter::make('service_type')
                    ->label('Dịch vụ')
                    ->options(fn (): array => self::serviceLabels()),
                SelectFilter::make('platform')
                    ->label('Nguồn đơn')
                    ->options([
                        'customer_app' => 'App khách hàng',
                        'shop_app' => 'App cửa hàng',
                        'call_center' => 'Tổng đài',
                    ]),
                SelectFilter::make('payment_method')
                    ->label('Thanh toán')
                    ->options(self::$paymentLabels),
                Tables\Filters\TernaryFilter::make('delivery_man_id')
                    ->label('Phân công tài xế')
                    ->placeholder('Tất cả đơn')
                    ->trueLabel('Đã có tài xế')
                    ->falseLabel('Chưa có tài xế')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('delivery_man_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('delivery_man_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip('Xem chi tiết'),
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
                    ->modalDescription(fn (Order $record) => 'Xác nhận huỷ đơn '.$record->code.'?')
                    ->action(function (Order $record) {
                        $fresh = $record->fresh();
                        if ($fresh->status === 'pending') {
                            $result = app(OrderService::class)->cancelPendingOrder($fresh);
                            if (! $result['success']) {
                                Notification::make()->title($result['message'])->danger()->send();

                                return;
                            }
                            Order::whereKey($fresh->id)->update(['cancel_reason' => 'admin']);
                        } elseif ($fresh->status === 'assigned') {
                            $updated = Order::whereKey($fresh->id)
                                ->where('status', 'assigned')
                                ->update(['status' => 'cancelled', 'cancel_reason' => 'admin', 'updated_at' => now()]);
                            if (! $updated) {
                                Notification::make()->title('Đơn vừa đổi trạng thái, vui lòng tải lại.')->danger()->send();

                                return;
                            }
                            RTDBService::clearOrder($fresh->code);
                            $driver = $fresh->driver;
                            if ($driver?->fcm_token) {
                                FCMService::getInstance()->sendDriverNotice(
                                    $driver->fcm_token,
                                    "Đơn #{$fresh->code} đã bị hủy",
                                    'Tổng đài đã hủy đơn hàng này.',
                                    ['type' => 'order_status', 'order_code' => $fresh->code, 'status' => 'cancelled'],
                                );
                            }
                        } else {
                            Notification::make()->title('Chỉ có thể hủy đơn đang chờ hoặc đã nhận.')->danger()->send();

                            return;
                        }
                        Notification::make()->title('Đã huỷ đơn '.$record->code)->warning()->send();
                    }),

                Tables\Actions\Action::make('reorder')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->tooltip('Đặt lại')
                    ->visible(fn (Order $record) => in_array($record->status, ['cancelled', 'completed']))
                    ->url(fn (Order $record) => CallCenterPage::getUrl().'?'.http_build_query(array_filter([
                        'reorder' => $record->id,
                        'service' => $record->service_type,
                        'city_id' => $record->city_id,
                        'pickup_address' => $record->pickup_address,
                        'pickup_phone' => $record->pickup_phone,
                        'pickup_lat' => $record->pickup_lat,
                        'pickup_lng' => $record->pickup_lng,
                        'delivery_address' => $record->delivery_address,
                        'delivery_phone' => $record->delivery_phone,
                        'delivery_lat' => $record->delivery_lat,
                        'delivery_lng' => $record->delivery_lng,
                        'order_note' => $record->order_note,
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
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
