<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoucherResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;
use App\Filament\Traits\HideFromCityManager;

class VoucherResource extends Resource
{
    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter();
    }

    use HideFromCityManager;

    protected static ?string $model = Voucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $modelLabel = 'Mã giảm giá';

    protected static ?string $pluralModelLabel = 'Mã giảm giá';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin mã')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Mã giảm giá')
                            ->required()
                            ->maxLength(32)
                            ->placeholder('VD: SUMMER20')
                            ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state)))
                            ->unique(ignoreRecord: true),

                        Forms\Components\Select::make('type')
                            ->label('Loại giảm giá')
                            ->options([
                                'percent'  => 'Theo % (phần trăm)',
                                'fixed'    => 'Cố định (VND)',
                                'freeship' => 'Freeship (miễn phí vận chuyển)',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('value')
                            ->label(fn (Get $get) => $get('type') === 'percent' ? 'Mức giảm (%)' : 'Số tiền giảm (₫)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(fn (Get $get) => $get('type') === 'percent' ? 100 : null)
                            ->suffix(fn (Get $get) => $get('type') === 'percent' ? '%' : '₫')
                            ->visible(fn (Get $get) => in_array($get('type'), ['percent', 'fixed']))
                            ->required(fn (Get $get) => in_array($get('type'), ['percent', 'fixed'])),

                        Forms\Components\TextInput::make('max_discount')
                            ->label(fn (Get $get) => $get('type') === 'freeship' ? 'Freeship tối đa (₫)' : 'Giảm tối đa (₫)')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('₫')
                            ->visible(fn (Get $get) => in_array($get('type'), ['percent', 'freeship']))
                            ->helperText('Để trống = không giới hạn'),

                        Forms\Components\Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Điều kiện áp dụng')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('min_order_value')
                            ->label('Giá trị đơn tối thiểu (₫)')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('₫')
                            ->placeholder('0 = không giới hạn'),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực áp dụng')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Tất cả khu vực'),

                        Forms\Components\Select::make('audience')
                            ->label('Áp dụng cho')
                            ->options([
                                'all'      => 'Tất cả (Khách hàng & Shop)',
                                'customer' => 'Chỉ ứng dụng Khách hàng',
                                'shop'     => 'Chỉ ứng dụng Shop',
                            ])
                            ->default('all')
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('user_id')
                            ->label('Shop áp dụng riêng')
                            ->relationship('user', 'name', fn ($query) => $query->where('user_type', 'shop'))
                            ->getOptionLabelFromRecordUsing(fn (User $record) => "{$record->name} ({$record->phone})")
                            ->searchable(['name', 'phone'])
                            ->preload()
                            ->placeholder('Tất cả shop')
                            ->visible(fn (Get $get) => $get('audience') === 'shop')
                            ->helperText('Để trống = áp dụng cho mọi shop')
                            ->columnSpanFull(),

                        Forms\Components\CheckboxList::make('service_types')
                            ->label('Dịch vụ áp dụng')
                            ->options([
                                'delivery' => 'Giao hàng',
                                'shopping' => 'Mua sắm',
                                'topup'    => 'Nạp tiền',
                                'bike'     => 'Xe ôm (Bike)',
                                'motor'    => 'Xe máy (Motor)',
                                'car'      => 'Ô tô (Car)',
                            ])
                            ->columns(3)
                            ->helperText('Để trống = áp dụng tất cả dịch vụ')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Giới hạn sử dụng')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('usage_limit')
                            ->label('Tổng lượt dùng tối đa')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Để trống = không giới hạn')
                            ->helperText('Tổng số lần mã này được dùng bởi tất cả người dùng'),

                        Forms\Components\TextInput::make('per_user_limit')
                            ->label('Lượt dùng tối đa / người')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Để trống = không giới hạn')
                            ->helperText('Nhập 1 = mỗi người chỉ dùng được 1 lần'),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Ngày hết hạn')
                            ->placeholder('Để trống = không hết hạn')
                            ->native(false)
                            ->displayFormat('d/m/Y H:i'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('discount_label')
                    ->label('Giảm giá')
                    ->weight('bold')
                    ->color(fn (Voucher $record) => match ($record->type) {
                        'percent'  => 'success',
                        'freeship' => 'info',
                        default    => 'warning',
                    }),

                Tables\Columns\TextColumn::make('max_discount')
                    ->label('Tối đa')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', '.') . ' ₫' : '—')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('min_order_value')
                    ->label('Đơn tối thiểu')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', '.') . ' ₫' : '—')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('usage')
                    ->label('Đã dùng / Giới hạn')
                    ->state(fn (Voucher $record) =>
                        $record->used_count . ' / ' . ($record->usage_limit ?? '∞')
                    ),

                Tables\Columns\TextColumn::make('per_user_limit')
                    ->label('Giới hạn / người')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' lần' : '∞')
                    ->badge()
                    ->color(fn ($state) => $state === 1 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->placeholder('Tất cả'),

                Tables\Columns\TextColumn::make('audience')
                    ->label('Áp dụng cho')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'customer' => 'Khách hàng',
                        'shop'     => 'Shop',
                        default    => 'Tất cả',
                    })
                    ->color(fn ($state) => match ($state) {
                        'customer' => 'info',
                        'shop'     => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Shop riêng')
                    ->placeholder('Tất cả shop'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Không giới hạn')
                    ->color(fn ($state) => $state && \Carbon\Carbon::parse($state)->isPast() ? 'danger' : null)
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Kích hoạt'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Đã tắt'),

                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        'percent'  => 'Phần trăm',
                        'fixed'    => 'Cố định',
                        'freeship' => 'Freeship',
                    ]),

                SelectFilter::make('audience')
                    ->label('Áp dụng cho')
                    ->options([
                        'all'      => 'Tất cả',
                        'customer' => 'Khách hàng',
                        'shop'     => 'Shop',
                    ]),
            ])
            ->actions([
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
            'index'  => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'edit'   => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
