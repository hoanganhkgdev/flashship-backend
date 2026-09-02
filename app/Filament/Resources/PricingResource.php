<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\City;
use Modules\Pricing\Models\PricingConfig;

class PricingResource extends Resource
{
    use RestrictToFullAdmin;

    // city_id = null nghĩa là áp dụng cho mọi khu vực — vẫn phải hiện ở mọi tenant.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->where(fn ($q) => $q->where('city_id', $tenant?->id)->orWhereNull('city_id'));
    }

    protected static ?string $model = PricingConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Cấu hình';

    protected static ?string $modelLabel = 'Bảng giá';

    protected static ?string $pluralModelLabel = 'Bảng giá';

    protected static ?int $navigationSort = 2;

    private static array $serviceLabels = [
        'delivery' => 'Lấy đồ hộ',
        'shopping' => 'Mua hộ',
        'bike' => 'Xe ôm',
        'motor' => 'Lái hộ xe máy',
        'car' => 'Lái hộ ô tô',
        'topup' => 'Nạp tiền',
    ];

    private static array $serviceColors = [
        'delivery' => 'info',
        'shopping' => 'warning',
        'bike' => 'success',
        'motor' => 'primary',
        'car' => 'danger',
        'topup' => 'gray',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin chung')
                ->description('Xác định dịch vụ, khu vực và trạng thái áp dụng bảng giá')
                ->icon('heroicon-o-information-circle')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('city_id')
                        ->label('Khu vực')
                        ->placeholder('Mặc định (tất cả khu vực)')
                        ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Select::make('service_type')
                        ->label('Dịch vụ')
                        ->options(self::$serviceLabels)
                        ->required()
                        ->disabledOn('edit'),

                    Forms\Components\TextInput::make('label')
                        ->label('Tên hiển thị')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->default(true)
                        ->inline(false),
                ]),

            // ── Slab bậc thang (delivery, shopping) ───────────────────────────
            Forms\Components\Section::make('Bảng giá bậc thang')
                ->description('Mỗi bậc áp dụng khi quãng đường ≤ "Đến km". Thứ tự các bậc ảnh hưởng trực tiếp đến phí.')
                ->icon('heroicon-o-bars-3-bottom-left')
                ->schema([
                    Forms\Components\Repeater::make('config_json.slabs')
                        ->label('Các bậc')
                        ->schema([
                            Forms\Components\TextInput::make('max_km')
                                ->label('Đến km')
                                ->numeric()
                                ->required()
                                ->step(0.5)
                                ->suffix('km'),
                            Forms\Components\TextInput::make('fee')
                                ->label('Phí')
                                ->numeric()
                                ->required()
                                ->suffix('₫'),
                        ])
                        ->columns(2)
                        ->addActionLabel('+ Thêm bậc')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0),

                    Forms\Components\TextInput::make('config_json.over_max_per_km')
                        ->label('Phí mỗi km vượt bậc cuối')
                        ->numeric()
                        ->suffix('₫/km'),
                ])
                ->visible(fn ($record, $get) => in_array($record?->service_type ?? $get('service_type'), ['delivery', 'shopping'])
                ),

            // ── Tuyến tính 2 bậc (bike) ───────────────────────────────────────
            Forms\Components\Section::make('Bảng giá xe ôm')
                ->description('Phí cơ bản và đơn giá theo quãng đường')
                ->icon('heroicon-o-calculator')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('config_json.base_km')
                        ->label('Km cơ bản')->numeric()->suffix('km'),
                    Forms\Components\TextInput::make('config_json.base_fee')
                        ->label('Phí cơ bản')->numeric()->suffix('₫'),
                    Forms\Components\TextInput::make('config_json.per_km_fee')
                        ->label('Phí/km (bình thường)')->numeric()->suffix('₫/km'),
                    Forms\Components\TextInput::make('config_json.higher_from_km')
                        ->label('Áp giá cao từ km')->numeric()->suffix('km'),
                    Forms\Components\TextInput::make('config_json.higher_per_km_fee')
                        ->label('Phí/km (giá cao)')->numeric()->suffix('₫/km'),
                ])
                ->visible(fn ($record, $get) => ($record?->service_type ?? $get('service_type')) === 'bike'
                ),

            // ── Tuyến tính đơn giản (motor, car) ──────────────────────────────
            Forms\Components\Section::make('Bảng giá tuyến tính')
                ->description('Phí cơ bản và đơn giá cho mỗi km tiếp theo')
                ->icon('heroicon-o-calculator')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('config_json.base_km')
                        ->label('Km cơ bản')->numeric()->suffix('km'),
                    Forms\Components\TextInput::make('config_json.base_fee')
                        ->label('Phí cơ bản')->numeric()->suffix('₫'),
                    Forms\Components\TextInput::make('config_json.per_km_fee')
                        ->label('Phí/km tiếp theo')->numeric()->suffix('₫/km'),
                ])
                ->visible(fn ($record, $get) => in_array($record?->service_type ?? $get('service_type'), ['motor', 'car'])
                ),

            // ── Nạp tiền (topup) ───────────────────────────────────────────────
            Forms\Components\Section::make('Bảng phí nạp tiền')
                ->description('Áp dụng theo số tiền nạp và các ngưỡng phí đã cấu hình')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Forms\Components\Repeater::make('config_json.tiers')
                        ->label('Các mức phí')
                        ->schema([
                            Forms\Components\TextInput::make('max_amount')
                                ->label('Dưới số tiền')->numeric()->required()->suffix('₫'),
                            Forms\Components\TextInput::make('fee')
                                ->label('Phí')->numeric()->required()->suffix('₫'),
                        ])
                        ->columns(2)
                        ->addActionLabel('+ Thêm mức')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0),

                    Forms\Components\TextInput::make('config_json.over_max_per_unit')
                        ->label('Mỗi đơn vị vượt')->numeric()->suffix('₫'),
                    Forms\Components\TextInput::make('config_json.over_max_fee_step')
                        ->label('Phí mỗi đơn vị vượt')->numeric()->suffix('₫'),
                ])
                ->visible(fn ($record, $get) => ($record?->service_type ?? $get('service_type')) === 'topup'
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()
                    ->label('#')
                    ->alignCenter()
                    ->width(40),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->default('Mặc định')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (PricingConfig $record) => $record->city_id ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('label')
                    ->label('Dịch vụ')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Mã')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::$serviceLabels[$state] ?? $state)
                    ->color(fn ($state) => self::$serviceColors[$state] ?? 'gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hoạt động')
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Khu vực')
                    ->placeholder('Tất cả')
                    ->options(fn () => ['0' => 'Mặc định'] + City::where('is_active', true)->pluck('name', 'id')->toArray()),

                Tables\Filters\SelectFilter::make('service_type')
                    ->label('Dịch vụ')
                    ->options(self::$serviceLabels),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Chỉnh giá'),

                Tables\Actions\Action::make('clone')
                    ->label('Nhân bản')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực đích')
                            ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (PricingConfig $record, array $data) {
                        $exists = PricingConfig::where('service_type', $record->service_type)
                            ->where('city_id', $data['city_id'])
                            ->exists();

                        if ($exists) {
                            Notification::make()->title('Đã tồn tại')
                                ->body('Khu vực này đã có bảng giá cho dịch vụ '.$record->label)
                                ->warning()->send();

                            return;
                        }

                        PricingConfig::create([
                            'service_type' => $record->service_type,
                            'city_id' => $data['city_id'],
                            'label' => $record->label,
                            'base_km' => $record->base_km,
                            'base_fee' => $record->base_fee,
                            'per_km_fee' => $record->per_km_fee,
                            'min_fee' => $record->min_fee,
                            'is_active' => true,
                            'config_json' => $record->config_json,
                        ]);

                        Notification::make()->title('Đã nhân bản')
                            ->body('Bạn có thể chỉnh giá riêng cho khu vực này')
                            ->success()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (PricingConfig $record) => $record->city_id !== null),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPricingConfigs::route('/'),
            'create' => Pages\CreatePricingConfig::route('/create'),
            'edit' => Pages\EditPricingConfig::route('/{record}/edit'),
        ];
    }
}
