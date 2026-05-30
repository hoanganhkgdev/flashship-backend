<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceTypeResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Core\Models\ServiceType;

class ServiceTypeResource extends Resource
{
    protected static ?string $model          = ServiceType::class;
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?int    $navigationSort = 3;
    protected static ?string $label         = 'Dịch vụ';
    protected static ?string $pluralLabel   = 'Dịch vụ';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label('Tên hiển thị')
                        ->required(),

                    Forms\Components\TextInput::make('key')
                        ->label('Key')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->alphaNum()
                        ->helperText('VD: delivery, shopping, bike — không dấu, không khoảng trắng'),
                ]),

            Forms\Components\Section::make('Hiển thị')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('icon_url')
                        ->label('Hình ảnh')
                        ->image()
                        ->disk('public')
                        ->directory('service-types')
                        ->imagePreviewHeight('80')
                        ->nullable()
                        ->helperText('PNG/SVG nền trong suốt, tối thiểu 100×100px')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Thứ tự hiển thị')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Hiển thị')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->alignCenter()
                    ->width(50),

                Tables\Columns\ImageColumn::make('icon_url')
                    ->label('Icon')
                    ->disk('public')
                    ->alignCenter()
                    ->square()
                    ->size(40),

                Tables\Columns\TextColumn::make('label')
                    ->label('Tên dịch vụ')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị')
                    ->alignCenter(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListServiceTypes::route('/'),
            'create' => Pages\CreateServiceType::route('/create'),
            'edit'   => Pages\EditServiceType::route('/{record}/edit'),
        ];
    }
}
