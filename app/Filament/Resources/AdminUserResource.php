<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUserResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class AdminUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Người dùng';
    protected static ?string $modelLabel      = 'Quản trị viên';
    protected static ?string $pluralModelLabel = 'Quản trị viên';
    protected static ?string $slug            = 'admins';
    protected static ?int    $navigationSort  = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('user_type', ['admin', 'subadmin', 'city_manager']);
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
                    ->tel(),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email(),
            ])->columns(3),

            Forms\Components\Section::make('Tài khoản')->schema([
                Forms\Components\Select::make('user_type')
                    ->label('Vai trò')
                    ->options([
                        'admin'        => 'Quản trị viên',
                        'subadmin'     => 'Quản trị viên phụ',
                        'city_manager' => 'Quản lý khu vực',
                    ])
                    ->live()
                    ->required(),

                Forms\Components\Select::make('city_id')
                    ->label('Khu vực phụ trách')
                    ->options(fn () => \Illuminate\Support\Facades\DB::table('cities')
                        ->where('is_active', 1)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get) => $get('user_type') === 'city_manager')
                    ->required(fn (Forms\Get $get) => $get('user_type') === 'city_manager'),

                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options([1 => 'Hoạt động', 2 => 'Bị khóa'])
                    ->default(1)
                    ->required(),

                Forms\Components\TextInput::make('password')
                    ->label('Mật khẩu')
                    ->password()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(6)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state)),
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
                    ->copyable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('user_type')
                    ->label('Vai trò')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'admin'        => 'Quản trị viên',
                        'subadmin'     => 'Phụ quản trị',
                        'city_manager' => 'Quản lý khu vực',
                        default        => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'admin'        => 'danger',
                        'subadmin'     => 'warning',
                        'city_manager' => 'info',
                        default        => 'gray',
                    }),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->default('—')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                        default => 'Không rõ',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'success',
                        2 => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_type')
                    ->label('Vai trò')
                    ->options([
                        'admin'        => 'Quản trị viên',
                        'subadmin'     => 'Quản trị viên phụ',
                        'city_manager' => 'Quản lý khu vực',
                    ]),
                SelectFilter::make('city_id')
                    ->label('Khu vực')
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
            'index'  => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit'   => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }
}
