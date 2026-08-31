<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoucherResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
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
                            ->extraInputAttributes([
                                'autocapitalize' => 'characters',
                                'spellcheck' => 'false',
                                'style' => 'text-transform: uppercase',
                            ])
                            ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state)))
                            ->unique(ignoreRecord: true),

                        Forms\Components\Select::make('type')
                            ->label('Loại giảm giá')
                            ->options([
                                'fixed' => 'Giảm số tiền cố định',
                                'percent' => 'Giảm theo phần trăm',
                                'freeship' => 'Miễn phí vận chuyển',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('value')
                            ->label(fn (Get $get) => $get('type') === 'percent' ? 'Mức giảm (%)' : 'Số tiền giảm (₫)')
                            ->numeric()
                            ->type('text')
                            ->mask(fn (Get $get) => $get('type') === 'fixed'
                                ? RawJs::make('$money($input, ".", ",", 0)')
                                : null)
                            ->stripCharacters(fn (Get $get) => $get('type') === 'fixed' ? ',' : null)
                            ->minValue(1)
                            ->maxValue(fn (Get $get) => $get('type') === 'percent' ? 100 : null)
                            ->suffix(fn (Get $get) => $get('type') === 'percent' ? '%' : '₫')
                            ->visible(fn (Get $get) => in_array($get('type'), ['percent', 'fixed']))
                            ->required(fn (Get $get) => in_array($get('type'), ['percent', 'fixed']))
                            ->columnSpan(fn (Get $get) => $get('type') === 'fixed' ? 2 : 1),

                        Forms\Components\TextInput::make('max_discount')
                            ->label(fn (Get $get) => $get('type') === 'freeship' ? 'Freeship tối đa (₫)' : 'Giảm tối đa (₫)')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('₫')
                            ->visible(fn (Get $get) => in_array($get('type'), ['percent', 'freeship']))
                            ->columnSpan(fn (Get $get) => $get('type') === 'freeship' ? 2 : 1)
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Mức giảm tối đa cho mỗi đơn. Để trống nếu không giới hạn.',
                            ),

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
                            ->label('Phí dịch vụ tối thiểu')
                            ->numeric()
                            ->type('text')
                            ->mask(RawJs::make('$money($input, ".", ",", 0)'))
                            ->stripCharacters(',')
                            ->minValue(0)
                            ->suffix('₫')
                            ->placeholder('Không yêu cầu')
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Khách chỉ dùng được mã khi phí dịch vụ đạt mức này. Để trống nếu không yêu cầu.',
                            ),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực áp dụng')
                            ->options(function (): array {
                                $tenant = Filament::getTenant();

                                return $tenant ? [$tenant->getKey() => $tenant->name] : [];
                            })
                            ->default(fn () => Filament::getTenant()?->getKey())
                            ->afterStateHydrated(fn (Forms\Components\Select $component) => $component->state(Filament::getTenant()?->getKey()))
                            ->disabled()
                            ->dehydrated()
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Khu vực được tự động lấy theo khu vực admin đang chọn.',
                            ),

                        Forms\Components\Select::make('audience')
                            ->label('Đối tượng áp dụng')
                            ->options([
                                'customer' => 'Khách hàng',
                                'shop' => 'Cửa hàng',
                                'all' => 'Tất cả người dùng',
                            ])
                            ->default('customer')
                            ->required()
                            ->afterStateUpdated(function (string $state, Set $set): void {
                                $set('user_id', null);
                                $set('service_types', $state === 'shop'
                                    ? ['delivery']
                                    : ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car']);
                            })
                            ->live()
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Chọn nhóm người dùng được phép sử dụng mã. Có thể giới hạn thêm cho một tài khoản cụ thể ở ô bên dưới.',
                            ),

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
                            ->options(fn (Get $get): array => match ($get('audience')) {
                                'shop' => [
                                    'delivery' => 'Giao hàng cửa hàng',
                                ],
                                'all' => [
                                    'delivery' => 'Lấy Hộ / Giao hàng cửa hàng',
                                    'shopping' => 'Mua Hộ',
                                    'topup' => 'Nạp Tiền',
                                    'bike' => 'Xe Ôm',
                                    'motor' => 'Lái Xe Máy',
                                    'car' => 'Lái Xe Hơi',
                                ],
                                default => [
                                    'delivery' => 'Lấy Hộ',
                                    'shopping' => 'Mua Hộ',
                                    'topup' => 'Nạp Tiền',
                                    'bike' => 'Xe Ôm',
                                    'motor' => 'Lái Xe Máy',
                                    'car' => 'Lái Xe Hơi',
                                ],
                            })
                            ->default(['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'])
                            ->required()
                            ->minItems(1)
                            ->bulkToggleable()
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Chọn những dịch vụ được phép sử dụng mã giảm giá.',
                            )
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
                            ->label('Tổng lượt sử dụng tối đa')
                            ->numeric()
                            ->type('text')
                            ->mask(RawJs::make('$money($input, ".", ",", 0)'))
                            ->stripCharacters(',')
                            ->minValue(1)
                            ->placeholder('Không giới hạn')
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Tổng số lần mã có thể được sử dụng bởi tất cả người dùng. Để trống nếu không giới hạn.',
                            ),

                        Forms\Components\TextInput::make('per_user_limit')
                            ->label('Lượt sử dụng tối đa mỗi người')
                            ->numeric()
                            ->type('text')
                            ->mask(RawJs::make('$money($input, ".", ",", 0)'))
                            ->stripCharacters(',')
                            ->minValue(1)
                            ->placeholder('Không giới hạn')
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Số lần tối đa mỗi tài khoản được sử dụng mã. Nhập 1 nếu mã chỉ được dùng một lần; để trống nếu không giới hạn.',
                            ),

                        Forms\Components\DatePicker::make('expires_at')
                            ->label('Ngày hết hạn')
                            ->placeholder('Không hết hạn')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->dehydrateStateUsing(fn ($state) => filled($state)
                                ? Carbon::parse($state)->endOfDay()
                                : null)
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Mã có hiệu lực đến hết ngày đã chọn. Để trống nếu mã không có thời hạn.',
                            ),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Kích hoạt mã giảm giá')
                            ->default(true)
                            ->inline(false)
                            ->onColor('success')
                            ->offColor('gray')
                            ->onIcon('heroicon-m-check')
                            ->offIcon('heroicon-m-x-mark')
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: 'Tắt tùy chọn này để tạm ngừng mã. Người dùng sẽ không thể xem hoặc áp dụng mã.',
                            ),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã giảm giá')
                    ->searchable(['code', 'description'])
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Đã sao chép mã')
                    ->badge()
                    ->color('primary')
                    ->alignment('center')
                    ->description(fn (Voucher $record) => $record->description ?: 'Không có mô tả')
                    ->wrap(),

                Tables\Columns\TextColumn::make('discount_label')
                    ->label('Ưu đãi')
                    ->weight('bold')
                    ->badge()
                    ->alignment('center')
                    ->color(fn (Voucher $record) => match ($record->type) {
                        'percent' => 'success',
                        'freeship' => 'info',
                        default => 'warning',
                    })
                    ->description(function (Voucher $record): string {
                        $conditions = [];
                        if ($record->min_order_value) {
                            $conditions[] = 'Phí từ '.number_format($record->min_order_value, 0, ',', '.').'₫';
                        }
                        if ($record->max_discount && in_array($record->type, ['percent', 'freeship'])) {
                            $conditions[] = 'Tối đa '.number_format($record->max_discount, 0, ',', '.').'₫';
                        }

                        return $conditions ? implode(' · ', $conditions) : 'Không kèm điều kiện phí';
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('audience')
                    ->label('Phạm vi áp dụng')
                    ->badge()
                    ->alignment('center')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'customer' => 'Khách hàng',
                        'shop' => 'Cửa hàng',
                        default => 'Tất cả người dùng',
                    })
                    ->color(fn ($state) => match ($state) {
                        'customer' => 'info',
                        'shop' => 'warning',
                        default => 'gray',
                    })
                    ->description(function (Voucher $record): string {
                        if ($record->user) {
                            return 'Riêng: '.$record->user->name;
                        }

                        $labels = [
                            'delivery' => $record->audience === 'shop' ? 'Giao hàng cửa hàng' : 'Lấy Hộ',
                            'shopping' => 'Mua Hộ',
                            'topup' => 'Nạp Tiền',
                            'bike' => 'Xe Ôm',
                            'motor' => 'Lái Xe Máy',
                            'car' => 'Lái Xe Hơi',
                        ];
                        $services = collect($record->service_types ?? [])
                            ->map(fn ($service) => $labels[$service] ?? $service)
                            ->implode(', ');

                        return $services ?: 'Tất cả dịch vụ';
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('usage')
                    ->label('Lượt sử dụng')
                    ->state(fn (Voucher $record) => $record->used_count.' / '.($record->usage_limit ?? '∞')
                    )
                    ->badge()
                    ->alignment('center')
                    ->color(fn (Voucher $record) => $record->usage_limit && $record->used_count >= $record->usage_limit
                        ? 'danger'
                        : 'success')
                    ->description(fn (Voucher $record) => 'Mỗi người: '.($record->per_user_limit ?? '∞').' lần'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Ngày hết hạn')
                    ->date('d/m/Y')
                    ->placeholder('Không hết hạn')
                    ->icon('heroicon-m-calendar-days')
                    ->alignment('center')
                    ->color(fn ($state) => $state && Carbon::parse($state)->isPast() ? 'danger' : 'gray')
                    ->description(fn (Voucher $record) => $record->expires_at?->isPast()
                        ? 'Đã hết hạn'
                        : ($record->expires_at ? 'Còn hiệu lực' : 'Không giới hạn thời gian'))
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hoạt động')
                    ->alignment('center')
                    ->onColor('success')
                    ->offColor('gray'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Đã tắt'),

                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        'fixed' => 'Giảm số tiền cố định',
                        'percent' => 'Giảm theo phần trăm',
                        'freeship' => 'Miễn phí vận chuyển',
                    ]),

                SelectFilter::make('audience')
                    ->label('Đối tượng áp dụng')
                    ->options([
                        'customer' => 'Khách hàng',
                        'shop' => 'Cửa hàng',
                        'all' => 'Tất cả người dùng',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Chỉnh sửa')
                    ->icon('heroicon-m-pencil-square'),
                Tables\Actions\DeleteAction::make()
                    ->label('Xóa'),
            ])
            ->actionsAlignment('center')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->recordAction('edit')
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
