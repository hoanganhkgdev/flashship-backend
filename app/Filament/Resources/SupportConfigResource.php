<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportConfigResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Admin\Models\SupportConfig;
use Modules\Core\Models\City;

class SupportConfigResource extends Resource
{
    protected static ?string $model            = SupportConfig::class;
    protected static ?string $navigationIcon   = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup  = 'Cài đặt';
    protected static ?string $modelLabel       = 'Hỗ trợ';
    protected static ?string $pluralModelLabel = 'Cấu hình hỗ trợ';
    protected static ?int    $navigationSort   = 2;

    private static array $typeOptions = [
        'phone'    => '📞 Số điện thoại',
        'zalo'     => '💬 Zalo',
        'facebook' => '📘 Facebook',
        'website'  => '🌐 Website',
        'email'    => '✉️ Email',
        'other'    => '🔗 Khác',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin nút hỗ trợ')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('VD: Hotline hỗ trợ'),

                    Forms\Components\Select::make('type')
                        ->label('Loại liên kết')
                        ->options(self::$typeOptions)
                        ->required()
                        ->live(),

                    Forms\Components\TextInput::make('value')
                        ->label('Giá trị / URL')
                        ->required()
                        ->maxLength(255)
                        ->placeholder(fn (Forms\Get $get) => match ($get('type')) {
                            'phone'    => '0901234567',
                            'zalo'     => 'https://zalo.me/...',
                            'facebook' => 'https://fb.me/...',
                            'website'  => 'https://...',
                            'email'    => 'support@example.com',
                            default    => 'https://...',
                        }),

                    Forms\Components\Select::make('city_id')
                        ->label('Khu vực áp dụng')
                        ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->placeholder('Tất cả khu vực'),

                    Forms\Components\TextInput::make('priority')
                        ->label('Thứ tự ưu tiên')
                        ->numeric()
                        ->default(0)
                        ->helperText('Số nhỏ hơn hiện lên trên'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Hiển thị trong app')
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('priority')
                    ->label('#')
                    ->alignCenter()
                    ->width(40),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::$typeOptions[$state] ?? $state)
                    ->color('info'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('value')
                    ->label('Giá trị')
                    ->limit(40)
                    ->copyable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->alignCenter()
                    ->default('Tất cả'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị')
                    ->alignCenter(),
            ])
            ->defaultSort('priority')
            ->reorderable('priority')
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportConfigs::route('/'),
            'create' => Pages\CreateSupportConfig::route('/create'),
            'edit'   => Pages\EditSupportConfig::route('/{record}/edit'),
        ];
    }
}
