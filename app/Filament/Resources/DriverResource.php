<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class DriverResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Người dùng';

    protected static ?string $modelLabel = 'Tài xế';

    protected static ?string $pluralModelLabel = 'Tài xế';

    protected static ?string $slug = 'drivers';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_type', 'driver');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Họ tên')
                    ->required(),

                Forms\Components\TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->tel(),

                Forms\Components\Toggle::make('status')
                    ->label('Kích hoạt')
                    ->default(true),

                Forms\Components\Toggle::make('is_online')
                    ->label('Đang online'),

                Forms\Components\Toggle::make('has_car_license')
                    ->label('Có bằng lái xe'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state): string => $state ? 'Hoạt động' : 'Bị khoá')
                    ->colors([
                        'success' => fn ($state): bool => (bool) $state,
                        'danger'  => fn ($state): bool => ! $state,
                    ]),

                Tables\Columns\BadgeColumn::make('is_online')
                    ->label('Trực tuyến')
                    ->formatStateUsing(fn ($state): string => $state ? 'Online' : 'Offline')
                    ->colors([
                        'success' => fn ($state): bool => (bool) $state,
                        'gray'    => fn ($state): bool => ! $state,
                    ]),

                Tables\Columns\IconColumn::make('has_car_license')
                    ->label('Bằng lái')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Thành phố')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_online')
                    ->label('Đang online')
                    ->trueLabel('Đang online')
                    ->falseLabel('Offline'),

                TernaryFilter::make('has_car_license')
                    ->label('Bằng lái xe')
                    ->trueLabel('Có bằng lái')
                    ->falseLabel('Không có bằng lái'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index'  => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit'   => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
