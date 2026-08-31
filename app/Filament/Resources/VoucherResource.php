<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoucherResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;

class VoucherResource extends Resource
{
    // Voucher là trách nhiệm tiền thật (giảm giá không giới hạn mức/số lần
    // dùng nếu admin không cẩn thận) — cùng nhóm rủi ro với ví/công nợ/giá
    // cước, chỉ admin đầy đủ mới được tạo/sửa.
    use RestrictToFullAdmin;

    // city_id = null nghĩa là áp dụng cho mọi khu vực — vẫn phải hiện ở mọi tenant.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->where(fn ($q) => $q->where('city_id', $tenant?->id)->orWhereNull('city_id'));
    }

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
                    ->icon('heroicon-o-ticket')
                    ->columns(4)
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
                                'percent' => 'Theo % (phần trăm)',
                                'fixed' => 'Cố định (VND)',
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
                            ->rows(1)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Phạm vi áp dụng')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('min_order_value')
                            ->label('Phí tối thiểu')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('₫')
                            ->placeholder('Không giới hạn'),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực áp dụng')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Tất cả khu vực'),

                        Forms\Components\Select::make('audience')
                            ->label('Áp dụng cho')
                            ->options([
                                'all' => 'Tất cả (Khách hàng & Shop)',
                                'customer' => 'Chỉ ứng dụng Khách hàng',
                                'shop' => 'Chỉ ứng dụng Shop',
                            ])
                            ->default('all')
                            ->required()
                            ->afterStateUpdated(fn (Set $set) => $set('user_id', null))
                            ->live(),

                        Forms\Components\Select::make('user_id')
                            ->label(fn (Get $get) => $get('audience') === 'shop'
                                ? 'Shop áp dụng riêng'
                                : 'Khách hàng áp dụng riêng')
                            ->relationship(
                                'user',
                                'name',
                                fn ($query, Get $get) => $query->where(
                                    'user_type',
                                    $get('audience') === 'shop' ? 'shop' : 'customer',
                                ),
                            )
                            ->getOptionLabelFromRecordUsing(fn (User $record) => "{$record->name} ({$record->phone})")
                            ->searchable(['name', 'phone'])
                            ->preload()
                            ->placeholder('Tất cả tài khoản phù hợp')
                            ->visible(fn (Get $get) => in_array($get('audience'), ['shop', 'customer']))
                            ->columnSpanFull(),

                        Forms\Components\CheckboxList::make('service_types')
                            ->label('Dịch vụ áp dụng')
                            ->options([
                                'delivery' => 'Giao hàng',
                                'shopping' => 'Mua sắm',
                                'topup' => 'Nạp tiền',
                                'bike' => 'Xe ôm (Bike)',
                                'motor' => 'Xe máy (Motor)',
                                'car' => 'Ô tô (Car)',
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Thiết lập nâng cao')
                    ->icon('heroicon-o-shield-check')
                    ->columns(4)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('usage_limit')
                            ->label('Tổng lượt dùng tối đa')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Không giới hạn'),

                        Forms\Components\TextInput::make('per_user_limit')
                            ->label('Lượt dùng tối đa / người')
                            ->minValue(1)
                            ->placeholder('Không giới hạn'),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Ngày hết hạn')
                            ->placeholder('Không hết hạn')
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
                        'percent' => 'success',
                        'freeship' => 'info',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('max_discount')
                    ->label('Tối đa')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', '.').' ₫' : '—')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('min_order_value')
                    ->label('Đơn tối thiểu')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', '.').' ₫' : '—')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('usage')
                    ->label('Đã dùng / Giới hạn')
                    ->state(fn (Voucher $record) => $record->used_count.' / '.($record->usage_limit ?? '∞')
                    ),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Còn lại')
                    ->state(fn (Voucher $record) => $record->usage_limit
                        ? max(0, $record->usage_limit - $record->used_count)
                        : '∞')
                    ->badge()
                    ->color(fn ($state) => is_numeric($state) && $state <= 5 ? 'danger' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('per_user_limit')
                    ->label('Giới hạn / người')
                    ->formatStateUsing(fn ($state) => $state ? $state.' lần' : '∞')
                    ->badge()
                    ->color(fn ($state) => $state === 1 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->placeholder('Tất cả')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('audience')
                    ->label('Áp dụng cho')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'customer' => 'Khách hàng',
                        'shop' => 'Shop',
                        default => 'Tất cả',
                    })
                    ->color(fn ($state) => match ($state) {
                        'customer' => 'info',
                        'shop' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Tài khoản riêng')
                    ->placeholder('Tất cả')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Không giới hạn')
                    ->color(fn ($state) => $state && Carbon::parse($state)->isPast() ? 'danger' : null)
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
                        'percent' => 'Phần trăm',
                        'fixed' => 'Cố định',
                        'freeship' => 'Freeship',
                    ]),

                SelectFilter::make('audience')
                    ->label('Áp dụng cho')
                    ->options([
                        'all' => 'Tất cả',
                        'customer' => 'Khách hàng',
                        'shop' => 'Shop',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
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
            'index' => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'edit' => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
