<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingResource\Pages;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Core\Models\City;
use Modules\Pricing\Models\PricingConfig;

class PricingResource extends Resource
{
    protected static ?string $model           = PricingConfig::class;
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?string $modelLabel      = 'Bảng giá';
    protected static ?string $pluralModelLabel = 'Bảng giá';
    protected static ?int    $navigationSort  = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('city_id')
                ->label('Khu vực')
                ->placeholder('Mặc định (tất cả khu vực)')
                ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->nullable(),

            Select::make('service_type')
                ->label('Dịch vụ')
                ->options([
                    'delivery' => 'Lấy Đồ Hộ',
                    'shopping' => 'Mua Hộ',
                    'bike'     => 'Xe Ôm',
                    'motor'    => 'Lái Hộ Xe Máy',
                    'car'      => 'Lái Hộ Ô Tô',
                    'topup'    => 'Nạp Tiền',
                ])
                ->required()
                ->disabledOn('edit'),

            TextInput::make('label')
                ->label('Tên hiển thị')
                ->required()
                ->maxLength(100),

            Toggle::make('is_active')
                ->label('Kích hoạt')
                ->inline(false),

            // ── Slab bậc thang (delivery, shopping) ───────────────────────────
            Section::make('Bảng giá bậc thang')
                ->description('Mỗi bậc áp dụng khi quãng đường ≤ "Đến km"')
                ->schema([
                    Repeater::make('config_json.slabs')
                        ->label('Các bậc')
                        ->schema([
                            TextInput::make('max_km')
                                ->label('Đến km')
                                ->numeric()
                                ->required()
                                ->step(0.5)
                                ->suffix('km'),
                            TextInput::make('fee')
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

                    TextInput::make('config_json.over_max_per_km')
                        ->label('Phí mỗi km vượt bậc cuối')
                        ->numeric()
                        ->suffix('₫/km'),
                ])
                ->visible(fn ($record, $get) =>
                    in_array($record?->service_type ?? $get('service_type'), ['delivery', 'shopping'])
                ),

            // ── Tuyến tính 2 bậc (bike) ───────────────────────────────────────
            Section::make('Bảng giá xe ôm')
                ->schema([
                    TextInput::make('config_json.base_km')
                        ->label('Km cơ bản')
                        ->numeric()
                        ->suffix('km'),
                    TextInput::make('config_json.base_fee')
                        ->label('Phí cơ bản')
                        ->numeric()
                        ->suffix('₫'),
                    TextInput::make('config_json.per_km_fee')
                        ->label('Phí/km (bình thường)')
                        ->numeric()
                        ->suffix('₫/km'),
                    TextInput::make('config_json.higher_from_km')
                        ->label('Áp giá cao từ km')
                        ->numeric()
                        ->suffix('km'),
                    TextInput::make('config_json.higher_per_km_fee')
                        ->label('Phí/km (giá cao)')
                        ->numeric()
                        ->suffix('₫/km'),
                ])
                ->columns(2)
                ->visible(fn ($record, $get) =>
                    ($record?->service_type ?? $get('service_type')) === 'bike'
                ),

            // ── Tuyến tính đơn giản (motor, car) ──────────────────────────────
            Section::make('Bảng giá tuyến tính')
                ->schema([
                    TextInput::make('config_json.base_km')
                        ->label('Km cơ bản (trong phí nền)')
                        ->numeric()
                        ->suffix('km'),
                    TextInput::make('config_json.base_fee')
                        ->label('Phí cơ bản')
                        ->numeric()
                        ->suffix('₫'),
                    TextInput::make('config_json.per_km_fee')
                        ->label('Phí mỗi km tiếp theo')
                        ->numeric()
                        ->suffix('₫/km'),
                ])
                ->columns(3)
                ->visible(fn ($record, $get) =>
                    in_array($record?->service_type ?? $get('service_type'), ['motor', 'car'])
                ),

            // ── Nạp tiền (topup) ───────────────────────────────────────────────
            Section::make('Bảng phí nạp tiền')
                ->description('Áp dụng theo số tiền nạp')
                ->schema([
                    Repeater::make('config_json.tiers')
                        ->label('Các mức phí')
                        ->schema([
                            TextInput::make('max_amount')
                                ->label('Dưới số tiền')
                                ->numeric()
                                ->required()
                                ->suffix('₫'),
                            TextInput::make('fee')
                                ->label('Phí')
                                ->numeric()
                                ->required()
                                ->suffix('₫'),
                        ])
                        ->columns(2)
                        ->addActionLabel('+ Thêm mức')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0),

                    TextInput::make('config_json.over_max_per_unit')
                        ->label('Mỗi đơn vị vượt')
                        ->numeric()
                        ->suffix('₫'),
                    TextInput::make('config_json.over_max_fee_step')
                        ->label('Phí mỗi đơn vị vượt')
                        ->numeric()
                        ->suffix('₫'),
                ])
                ->visible(fn ($record, $get) =>
                    ($record?->service_type ?? $get('service_type')) === 'topup'
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->default('Mặc định')
                    ->badge()
                    ->color(fn ($record) => $record->city_id ? 'info' : 'gray')
                    ->sortable(),

                TextColumn::make('label')
                    ->label('Dịch vụ')
                    ->weight('bold'),

                TextColumn::make('service_type')
                    ->label('Mã')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('base_fee')
                    ->label('Phí cơ bản')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . '₫'),

                TextColumn::make('base_km')
                    ->label('Km cơ bản')
                    ->formatStateUsing(fn ($state) => $state ? "{$state} km" : '—'),

                TextColumn::make('per_km_fee')
                    ->label('Phí/km')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) . '₫' : '—'),

                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->defaultSort(fn ($query) => $query->orderByRaw('city_id IS NOT NULL')->orderBy('service_type'))
            ->filters([
                SelectFilter::make('city_id')
                    ->label('Khu vực')
                    ->placeholder('Tất cả')
                    ->options(fn () => ['' => 'Mặc định'] + City::where('is_active', true)->pluck('name', 'id')->toArray()),
            ])
            ->headerActions([
                Action::make('create_city_pricing')
                    ->label('Tạo giá riêng cho khu vực')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => static::getUrl('create')),
            ])
            ->actions([
                EditAction::make()->label('Chỉnh giá'),

                Action::make('clone')
                    ->label('Nhân bản')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->form([
                        Select::make('city_id')
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
                            \Filament\Notifications\Notification::make()
                                ->title('Đã tồn tại')
                                ->body('Khu vực này đã có bảng giá cho dịch vụ ' . $record->label)
                                ->warning()
                                ->send();
                            return;
                        }

                        PricingConfig::create([
                            'service_type' => $record->service_type,
                            'city_id'      => $data['city_id'],
                            'label'        => $record->label,
                            'base_km'      => $record->base_km,
                            'base_fee'     => $record->base_fee,
                            'per_km_fee'   => $record->per_km_fee,
                            'min_fee'      => $record->min_fee,
                            'is_active'    => true,
                            'config_json'  => $record->config_json,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Đã nhân bản')
                            ->body('Bạn có thể chỉnh giá riêng cho khu vực này')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(false),

                Action::make('toggle')
                    ->label(fn (PricingConfig $record) => $record->is_active ? 'Tắt' : 'Bật')
                    ->icon(fn (PricingConfig $record) => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (PricingConfig $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PricingConfig $record) => ($record->is_active ? 'Tắt' : 'Bật') . ' ' . $record->label)
                    ->action(fn (PricingConfig $record) => $record->update(['is_active' => !$record->is_active])),

                DeleteAction::make()
                    ->visible(fn (PricingConfig $record) => $record->city_id !== null),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingConfigs::route('/'),
            'create' => Pages\CreatePricingConfig::route('/create'),
            'edit'   => Pages\EditPricingConfig::route('/{record}/edit'),
        ];
    }
}
