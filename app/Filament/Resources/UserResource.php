<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Modules\Core\Models\City;
use Modules\Core\Models\User;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Người dùng';

    protected static ?string $modelLabel = 'Người dùng';

    protected static ?string $pluralModelLabel = 'Người dùng';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Họ tên')
                    ->required(),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email(),

                Forms\Components\TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->tel(),

                Forms\Components\Select::make('user_type')
                    ->label('Loại tài khoản')
                    ->options([
                        'admin'    => 'Quản trị viên',
                        'subadmin' => 'Quản trị viên phụ',
                        'driver'   => 'Tài xế',
                        'customer' => 'Khách hàng',
                        'shop'     => 'Cửa hàng',
                    ])
                    ->required()
                    ->live(),

                Forms\Components\TextInput::make('password')
                    ->label('Mật khẩu')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(6)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state)),

                Forms\Components\Toggle::make('status')
                    ->label('Kích hoạt')
                    ->default(true),

                Forms\Components\Toggle::make('has_car_license')
                    ->label('Có bằng lái xe')
                    ->visible(fn (Forms\Get $get): bool => $get('user_type') === 'driver'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('user_type')
                    ->label('Loại tài khoản')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin'    => 'Quản trị viên',
                        'subadmin' => 'Phụ quản trị',
                        'driver'   => 'Tài xế',
                        'customer' => 'Khách hàng',
                        'shop'     => 'Cửa hàng',
                        default    => $state,
                    })
                    ->colors([
                        'danger'  => 'admin',
                        'warning' => 'subadmin',
                        'primary' => 'driver',
                        'success' => 'customer',
                        'info'    => 'shop',
                    ]),

                Tables\Columns\IconColumn::make('status')
                    ->label('Trạng thái')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean()
                    ->trueIcon('heroicon-o-wifi')
                    ->falseIcon('heroicon-o-signal-slash')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Thành phố')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_type')
                    ->label('Loại tài khoản')
                    ->options([
                        'admin'    => 'Quản trị viên',
                        'subadmin' => 'Phụ quản trị',
                        'driver'   => 'Tài xế',
                        'customer' => 'Khách hàng',
                        'shop'     => 'Cửa hàng',
                    ]),

                TernaryFilter::make('status')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Bị khoá'),
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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
