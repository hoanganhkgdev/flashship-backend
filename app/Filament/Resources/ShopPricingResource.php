<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopPricingResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Shop\Models\ShopPricingConfig;
use App\Filament\Traits\HideFromCityManager;

class ShopPricingResource extends Resource
{
    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter();
    }

    use HideFromCityManager;

    protected static ?string $model           = ShopPricingConfig::class;
    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?string $modelLabel       = 'Bảng giá cửa hàng';
    protected static ?string $pluralModelLabel = 'Bảng giá cửa hàng';
    protected static ?int    $navigationSort   = 3;

    private static array $cargoLabels = [
        'food'    => 'Đồ ăn / Nước uống',
        'flowers' => 'Hoa / Giỏ trái cây / Bó hoa',
        'parcel'  => 'Bưu kiện / Kệ hoa / Hàng thùng',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin chung')->schema([
                Forms\Components\Select::make('cargo_type')
                    ->label('Loại hàng')
                    ->options(self::$cargoLabels)
                    ->required()
                    ->disabledOn('edit'),

                Forms\Components\Select::make('city_id')
                    ->label('Khu vực (để trống = áp dụng tất cả)')
                    ->placeholder('Mặc định — tất cả khu vực')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Đang hoạt động')
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Bảng giá theo km')->schema([
                Forms\Components\Repeater::make('slabs')
                    ->label('Các mức giá')
                    ->schema([
                        Forms\Components\TextInput::make('max_km')
                            ->label('Tới (km)')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->suffix('km'),

                        Forms\Components\TextInput::make('fee')
                            ->label('Phí (đ)')
                            ->numeric()
                            ->required()
                            ->suffix('đ'),
                    ])
                    ->columns(2)
                    ->addActionLabel('Thêm mức giá')
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): string =>
                        '≤ ' . ($state['max_km'] ?? '?') . ' km  →  ' .
                        number_format((int)($state['fee'] ?? 0)) . ' đ'
                    )
                    ->helperText('Sắp xếp từ nhỏ đến lớn theo km. Mức cuối áp dụng cho đến max rồi tính theo km vượt.'),
            ]),

            Forms\Components\Section::make('Phụ phí')->schema([
                Forms\Components\TextInput::make('over_max_per_km')
                    ->label('Phí mỗi km vượt max')
                    ->numeric()
                    ->default(3000)
                    ->required()
                    ->suffix('đ/km')
                    ->helperText('Áp dụng khi vượt km lớn nhất trong bảng giá'),

                Forms\Components\TextInput::make('weight_per_kg')
                    ->label('Phụ phí theo kg')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->suffix('đ/kg')
                    ->helperText('Chỉ dùng cho Bưu kiện. Để 0 nếu không tính theo kg'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cargo_type')
                    ->label('Loại hàng')
                    ->formatStateUsing(fn ($state) => self::$cargoLabels[$state] ?? $state)
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->default('Mặc định (tất cả)')
                    ->badge()
                    ->color('info'),

                // Hiển thị tóm tắt bảng giá
                Tables\Columns\TextColumn::make('slabs')
                    ->label('Bảng giá')
                    ->formatStateUsing(function ($state) {
                        if (!is_array($state)) return '—';
                        $first = $state[0]  ?? null;
                        $last  = end($state) ?: null;
                        if (!$first || !$last) return '—';
                        return number_format((int)$first['fee']) . 'đ – ' .
                               number_format((int)$last['fee'])  . 'đ';
                    })
                    ->description(fn ($record) =>
                        count($record->slabs ?? []) . ' mức giá' .
                        ($record->weight_per_kg > 0
                            ? ' + ' . number_format($record->weight_per_kg) . 'đ/kg'
                            : '')
                    ),

                Tables\Columns\TextColumn::make('over_max_per_km')
                    ->label('Vượt km')
                    ->formatStateUsing(fn ($state) => '+' . number_format((int)$state) . 'đ/km')
                    ->color('warning'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ])
            ->defaultSort('cargo_type');
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListShopPricings::route('/'),
            'create' => Pages\CreateShopPricing::route('/create'),
            'edit'   => Pages\EditShopPricing::route('/{record}/edit'),
        ];
    }
}
