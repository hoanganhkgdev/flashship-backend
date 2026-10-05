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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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

    /** Đối tượng mà resource này quản lý. Lớp con (ShopVoucherResource) đổi sang 'shop'. */
    protected static string $audience = 'customer';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('audience', static::$audience);
    }

    /** Mã nhân bản: đặt mã mới không trùng, xóa lượt dùng và để ở trạng thái tắt cho admin chỉnh lại. */
    public static function prepareReplica(Voucher $replica): void
    {
        $base = mb_substr((string) $replica->code, 0, 26);
        $i = 2;
        do {
            $code = $base.'-'.$i++;
        } while (Voucher::where('code', $code)->exists());
        $replica->code = $code;
        $replica->used_count = 0;
        $replica->is_active = false;
    }

    public static function audience(): string
    {
        return static::$audience;
    }

    /** Cách gọi người dùng của mã: "Khách" hoặc "Cửa hàng". */
    public static function noun(): string
    {
        return static::$audience === 'shop' ? 'Cửa hàng' : 'Khách';
    }

    /** Dịch vụ chọn sẵn khi tạo mã mới. */
    protected static function defaultServiceTypes(): array
    {
        return static::$audience === 'shop' ? ['delivery'] : ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Khách hàng';

    protected static ?string $navigationLabel = 'Mã giảm giá';

    protected static ?string $modelLabel = 'Mã giảm giá';

    protected static ?string $pluralModelLabel = 'Mã giảm giá';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Xem trước')
                    ->description(fn (): string => static::noun().' sẽ thấy mã này như sau')
                    ->icon('heroicon-o-eye')
                    ->schema([
                        Forms\Components\Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(function (Get $get): HtmlString {
                                $num = fn ($v) => filled($v) ? (float) str_replace(',', '', (string) $v) : null;
                                $offer = Voucher::describeOffer($get('type'), $num($get('value')), $num($get('max_discount')));
                                $parts = Voucher::describeConditions([
                                    'first_order_only' => $get('first_order_only'),
                                    'per_user_limit' => $num($get('per_user_limit')),
                                    'min_order_value' => $num($get('min_order_value')),
                                    'max_distance_km' => $num($get('max_distance_km')),
                                ]);
                                if ($limit = $num($get('usage_limit'))) {
                                    $parts[] = 'Giới hạn '.number_format($limit, 0, ',', '.').' lượt';
                                }
                                $parts[] = $get('expires_at') ? 'Hết hạn '.Carbon::parse($get('expires_at'))->format('d/m/Y') : 'Không hết hạn';

                                return new HtmlString('<div class="fs-vc-preview"><b>'.e($get('code') ?: 'MÃ GIẢM GIÁ').'</b><strong>'.e($offer).'</strong><span>'.e(implode(' · ', $parts)).'</span></div>');
                            }),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('tpl_freeship')
                                ->label('Mẫu: Freeship đơn đầu')->icon('heroicon-o-truck')->color('gray')
                                ->action(function (Set $set): void {
                                    $set('type', 'freeship');
                                    $set('value', null);
                                    $set('max_discount', 25000);
                                    $set('first_order_only', true);
                                    $set('per_user_limit', 1);
                                }),
                            Forms\Components\Actions\Action::make('tpl_fixed')
                                ->label('Mẫu: Giảm số tiền cố định')->icon('heroicon-o-banknotes')->color('gray')
                                ->action(function (Set $set): void {
                                    $set('type', 'fixed');
                                    $set('value', 5000);
                                    $set('max_discount', null);
                                    $set('first_order_only', false);
                                    $set('per_user_limit', 3);
                                }),
                            Forms\Components\Actions\Action::make('tpl_percent')
                                ->label('Mẫu: Giảm phần trăm')->icon('heroicon-o-receipt-percent')->color('gray')
                                ->action(function (Set $set): void {
                                    $set('type', 'percent');
                                    $set('value', 20);
                                    $set('max_discount', 25000);
                                    $set('first_order_only', false);
                                    $set('per_user_limit', 1);
                                }),
                        ])->visible(fn (string $operation): bool => $operation === 'create'),
                    ]),

                Forms\Components\Section::make('Thông tin mã')
                    ->description('Thiết lập loại ưu đãi và giá trị giảm cho mỗi đơn')
                    ->icon('heroicon-o-ticket')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->live(onBlur: true)
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
                            ->live(onBlur: true)
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
                            ->live(onBlur: true)
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
                    ->description('Giới hạn đối tượng, khu vực và dịch vụ được phép sử dụng mã')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('min_order_value')
                            ->live(onBlur: true)
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
                            ])
                            ->default(static::$audience)
                            ->disabled()
                            ->dehydrated()
                            ->hintIcon(
                                'heroicon-m-exclamation-circle',
                                tooltip: static::$audience === 'shop'
                                    ? 'Mã tạo ở đây chỉ dành cho cửa hàng. Mã khách hàng được quản lý ở nhóm Khách hàng.'
                                    : 'Mã tạo ở đây chỉ dành cho khách hàng. Mã cửa hàng được quản lý ở mục Mã giảm giá cửa hàng.',
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
                                default => [
                                    'delivery' => 'Lấy Hộ',
                                    'shopping' => 'Mua Hộ',
                                    'topup' => 'Nạp Tiền',
                                    'bike' => 'Xe Ôm',
                                    'motor' => 'Lái Xe Máy',
                                    'car' => 'Lái Xe Hơi',
                                ],
                            })
                            ->default(fn (): array => static::defaultServiceTypes())
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
                    ->description('Giới hạn lượt dùng, thời gian và trạng thái chiến dịch')
                    ->icon('heroicon-o-shield-check')
                    ->columns(4)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('usage_limit')
                            ->live(onBlur: true)
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
                            ->live(onBlur: true)
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

                        Forms\Components\Toggle::make('first_order_only')
                            ->live()
                            ->label('Chỉ đơn đầu tiên')
                            ->helperText('Chỉ tài khoản chưa từng hoàn thành đơn nào mới được sử dụng.')
                            ->default(false),

                        Forms\Components\TextInput::make('max_distance_km')
                            ->live(onBlur: true)
                            ->label('Cự ly tối đa (km)')
                            ->numeric()
                            ->minValue(0.1)
                            ->helperText('Để trống nếu mã không giới hạn cự ly.'),

                        Forms\Components\DatePicker::make('expires_at')
                            ->live()
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
        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
        $serviceLabels = [
            'delivery' => static::$audience === 'shop' ? 'Giao hàng cửa hàng' : 'Lấy Hộ',
            'shopping' => 'Mua Hộ', 'topup' => 'Nạp Tiền', 'bike' => 'Xe Ôm', 'motor' => 'Lái Xe Máy', 'car' => 'Lái Xe Hơi',
        ];

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('user')
                ->select('vouchers.*')
                ->addSelect([
                    // Số người đã dùng và chi phí giảm giá (đơn đã hoàn thành) của từng mã.
                    'users_count' => DB::table('voucher_usages')->selectRaw('COUNT(DISTINCT user_id)')->whereColumn('voucher_id', 'vouchers.id'),
                    'cost' => DB::table('orders')->selectRaw('COALESCE(SUM(discount_amount), 0)')->whereColumn('orders.voucher_code', 'vouchers.code')->where('orders.status', 'completed'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã giảm giá')
                    ->searchable(['code', 'description'])
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Đã sao chép mã')
                    ->badge()
                    ->color('primary')
                    ->description(fn (Voucher $record) => $record->description ?: 'Không có mô tả')
                    ->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Ưu đãi')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state, Voucher $record) => Voucher::describeOffer($record->type, $record->value, $record->max_discount))
                    ->description(fn (Voucher $record): string => implode(' · ', Voucher::describeConditions($record->only(['first_order_only', 'per_user_limit', 'min_order_value', 'max_distance_km']))) ?: 'Không kèm điều kiện')
                    ->wrap(),

                Tables\Columns\TextColumn::make('service_types')
                    ->label('Áp dụng cho')
                    ->state(fn (Voucher $record): string => collect($record->service_types ?? [])->map(fn ($s) => $serviceLabels[$s] ?? $s)->implode(', ') ?: 'Tất cả dịch vụ')
                    ->description(fn (Voucher $record): string => $record->user ? 'Riêng: '.$record->user->name : 'Tất cả '.mb_strtolower(static::noun() === 'Khách' ? 'khách hàng' : 'cửa hàng'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('used_count')
                    ->label('Hiệu quả')
                    ->sortable()
                    ->html()
                    ->state(function (Voucher $record) use ($money): HtmlString {
                        $used = number_format($record->used_count, 0, ',', '.');
                        $bar = '';
                        if ($record->usage_limit) {
                            $pct = min(100, (int) round($record->used_count / $record->usage_limit * 100));
                            $bar = '<span class="fs-vc-meter"><i style="width:'.$pct.'%"></i></span>'
                                .'<small>'.$used.' / '.number_format($record->usage_limit, 0, ',', '.').' lượt</small>';
                        } else {
                            $bar = '<small>'.$used.' lượt · không giới hạn</small>';
                        }

                        return new HtmlString('<div class="fs-vc-usage">'.$bar
                            .'<small>'.number_format((int) $record->users_count, 0, ',', '.').' người · tốn <b>'.$money($record->cost).'</b></small></div>');
                    }),

                Tables\Columns\TextColumn::make('status_key')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Voucher $record): string => $record->statusKey())
                    ->formatStateUsing(fn (string $state): string => ['active' => 'Đang chạy', 'full' => 'Hết lượt', 'expired' => 'Hết hạn', 'inactive' => 'Đã tắt'][$state])
                    ->color(fn (string $state): string => ['active' => 'success', 'full' => 'warning', 'expired' => 'danger', 'inactive' => 'gray'][$state]),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->date('d/m/Y')
                    ->placeholder('Không hết hạn')
                    ->sortable()
                    ->description(fn (Voucher $record): ?string => $record->expires_at && $record->expires_at->isFuture() ? 'còn '.now()->diffInDays($record->expires_at).' ngày' : null),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái hoạt động')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đang bật')
                    ->falseLabel('Đã tắt'),

                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        'fixed' => 'Giảm số tiền cố định',
                        'percent' => 'Giảm theo phần trăm',
                        'freeship' => 'Miễn phí vận chuyển',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Nhân bản')
                        ->excludeAttributes(['used_count', 'users_count', 'cost'])
                        ->beforeReplicaSaved(fn (Voucher $replica) => static::prepareReplica($replica))
                        ->successRedirectUrl(fn (Voucher $replica): string => static::getUrl('edit', ['record' => $replica]))
                        ->successNotificationTitle('Đã nhân bản mã (đang tắt, hãy chỉnh lại rồi bật)'),
                    Tables\Actions\Action::make('toggle_active')
                        ->label(fn (Voucher $record): string => $record->is_active ? 'Tắt mã' : 'Bật lại mã')
                        ->icon(fn (Voucher $record): string => $record->is_active ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
                        ->action(fn (Voucher $record) => $record->update(['is_active' => ! $record->is_active])),
                    // Mã đã từng dùng thì không xóa được: xóa kéo theo mất lịch sử lượt dùng và chi phí. Hãy tắt mã.
                    Tables\Actions\DeleteAction::make()
                        ->label('Xóa')
                        ->visible(fn (Voucher $record): bool => ! $record->hasHistory())
                        ->modalDescription('Mã chưa có lượt dùng nào nên xóa được. Không thể hoàn tác.'),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Thao tác khác'),
            ])
            ->actionsAlignment('center')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Tắt các mã đã chọn')
                        ->icon('heroicon-m-pause-circle')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->recordUrl(fn (Voucher $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
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
            'view' => Pages\ViewVoucher::route('/{record}'),
            'edit' => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
