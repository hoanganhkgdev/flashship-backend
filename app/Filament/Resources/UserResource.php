<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;
use App\Filament\Traits\HideFromCityManager;

class UserResource extends Resource
{
    use HideFromCityManager;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Người dùng';
    protected static ?string $modelLabel      = 'Khách hàng';
    protected static ?string $pluralModelLabel = 'Khách hàng';
    protected static ?string $slug            = 'customers';
    protected static ?int    $navigationSort  = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_type', 'customer');
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

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email(),

                Forms\Components\Select::make('city_id')
                    ->label('Thành phố')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload(),
            ])->columns(2),

            Forms\Components\Section::make('Tài khoản')->schema([
                Forms\Components\TextInput::make('password')
                    ->label('Mật khẩu')
                    ->password()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(6)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state)),

                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options([1 => 'Hoạt động', 0 => 'Chờ duyệt', 2 => 'Bị khóa'])
                    ->required(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->label('#')
                    ->rowIndex()
                    ->width(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Thành phố')
                    ->badge()
                    ->color('info')
                    ->default('—'),

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

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([0 => 'Chờ duyệt', 1 => 'Hoạt động', 2 => 'Bị khóa']),

                SelectFilter::make('city_id')
                    ->label('Thành phố')
                    ->relationship('city', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
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
