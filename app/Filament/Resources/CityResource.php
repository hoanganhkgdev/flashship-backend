<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Core\Models\City;

class CityResource extends Resource
{
    protected static ?string $model = City::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?string $modelLabel      = 'Khu vực';
    protected static ?string $pluralModelLabel = 'Khu vực';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên khu vực')
                ->required()
                ->maxLength(255),

            TextInput::make('slug')
                ->label('Slug')
                ->maxLength(100),

            TextInput::make('lat')
                ->label('Vĩ độ (Latitude)')
                ->numeric()
                ->placeholder('10.7769'),

            TextInput::make('lng')
                ->label('Kinh độ (Longitude)')
                ->numeric()
                ->placeholder('106.7009'),

            Toggle::make('is_active')
                ->label('Hoạt động')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên khu vực')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lat')
                    ->label('Vĩ độ'),

                TextColumn::make('lng')
                    ->label('Kinh độ'),

                TextColumn::make('users_count')
                    ->label('Tài xế')
                    ->counts('users')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Trạng thái'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit'   => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
