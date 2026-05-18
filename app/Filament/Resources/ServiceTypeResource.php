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
    protected static ?string $model = ServiceType::class;
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?int $navigationSort = 1;
    protected static ?string $label = 'Dịch vụ';
    protected static ?string $pluralLabel = 'Dịch vụ';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')
                ->label('Tên hiển thị')
                ->required(),

            Forms\Components\TextInput::make('key')
                ->label('Key (slug)')
                ->required()
                ->unique(ignoreRecord: true)
                ->alphaNum()
                ->helperText('Ví dụ: delivery, shopping, bike — không dấu, không khoảng trắng'),

            Forms\Components\FileUpload::make('icon_url')
                ->label('Hình ảnh')
                ->image()
                ->disk('public')
                ->directory('service-types')
                ->imagePreviewHeight('80')
                ->nullable()
                ->helperText('PNG/SVG nền trong suốt, tối thiểu 100×100px'),

            Forms\Components\ColorPicker::make('bg_color_hex')
                ->label('Màu nền')
                ->required(),

            Forms\Components\TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('is_active')
                ->label('Hiển thị')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width(50),

                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('label')
                    ->label('Tên')
                    ->searchable(),

                Tables\Columns\ImageColumn::make('icon_url')
                    ->label('Icon')
                    ->disk('public')
                    ->square()
                    ->size(40),

                Tables\Columns\ColorColumn::make('bg_color_hex')
                    ->label('Màu nền'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
