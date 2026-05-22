<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class DriverResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Người dùng';
    protected static ?string $modelLabel      = 'Tài xế';
    protected static ?string $pluralModelLabel = 'Tài xế';
    protected static ?string $slug            = 'drivers';
    protected static ?int    $navigationSort  = 2;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->where('status', 0)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_type', 'driver');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cá nhân')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Họ tên')
                    ->required(),

                Forms\Components\TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->tel()
                    ->required(),

                Forms\Components\TextInput::make('cccd')
                    ->label('CCCD / CMND'),

                Forms\Components\Select::make('city_id')
                    ->label('Thành phố')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload(),
            ])->columns(2),

            Forms\Components\Section::make('Trạng thái')->schema([
                Forms\Components\Select::make('status')
                    ->label('Trạng thái tài khoản')
                    ->options([
                        0 => 'Chờ duyệt',
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                    ])
                    ->required(),

                Forms\Components\Toggle::make('is_online')
                    ->label('Đang trực tuyến'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=Driver&background=random')
                    ->width(36)->height(36),

                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('cccd')
                    ->label('CCCD')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Thành phố')
                    ->default('—')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        0 => 'Chờ duyệt',
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                        default => 'Không rõ',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        0 => 'warning',
                        1 => 'success',
                        2 => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('is_online')
                    ->label('Online')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Online' : 'Offline')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('wallet.balance')
                    ->label('Số dư')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ')
                    ->default('0đ')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        0 => 'Chờ duyệt',
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                    ]),

                SelectFilter::make('city_id')
                    ->label('Thành phố')
                    ->relationship('city', 'name'),

                TernaryFilter::make('is_online')
                    ->label('Trực tuyến')
                    ->trueLabel('Đang online')
                    ->falseLabel('Offline'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Xem hồ sơ'),

                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record) => $record->status == 0)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['status' => 1]);
                        Notification::make()->title('Đã duyệt tài xế ' . $record->name)->success()->send();
                    }),

                Tables\Actions\Action::make('block')
                    ->label('Khóa')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (User $record) => $record->status == 1)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['status' => 2]);
                        Notification::make()->title('Đã khóa tài xế ' . $record->name)->warning()->send();
                    }),

                Tables\Actions\Action::make('unblock')
                    ->label('Mở khóa')
                    ->icon('heroicon-o-lock-open')
                    ->color('info')
                    ->visible(fn (User $record) => $record->status == 2)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['status' => 1]);
                        Notification::make()->title('Đã mở khóa tài xế ' . $record->name)->success()->send();
                    }),

                Tables\Actions\EditAction::make()->label('Sửa'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_all')
                        ->label('Duyệt tất cả')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 1])),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DriverCccdImagesRelationManager::class,
            RelationManagers\DriverLicensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'view'   => Pages\ViewDriver::route('/{record}'),
            'edit'   => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
