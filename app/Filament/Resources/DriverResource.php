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
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use App\Filament\Traits\HideFromCityManager;

class DriverResource extends Resource
{
    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

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
                    ->preload()
                    ->live(),
            ])->columns(2),

            Forms\Components\Section::make('Ca làm việc')->schema([
                Forms\Components\Select::make('registeredShifts')
                    ->label('Ca đang đăng ký')
                    ->relationship(
                        'registeredShifts',
                        'name',
                        modifyQueryUsing: fn (Builder $query, Forms\Get $get) => $query
                            ->where('city_id', $get('city_id'))
                            ->where('is_active', true),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Gán trực tiếp ca làm việc cho tài xế — bỏ qua luồng gửi yêu cầu đổi ca chờ duyệt. Chỉ hiện ca đang kích hoạt của thành phố đã chọn ở trên.'),
            ]),

            Forms\Components\Section::make('Trạng thái')->schema([
                Forms\Components\Toggle::make('is_online')
                    ->label('Đang trực tuyến'),
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

                Tables\Columns\ImageColumn::make('profile_photo_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=Driver&background=random')
                    ->width(36)->height(36),

                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->weight('semibold')
                    ->description(fn (User $record) => $record->phone),

                Tables\Columns\TextColumn::make('is_online')
                    ->label('Online')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Online' : 'Offline')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('cccd_review')
                    ->label('CCCD')
                    ->alignCenter()
                    ->badge()
                    ->state(fn ($record) => $record->driverCccdImages()->latest()->value('status') ?? 'none')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        'pending'  => 'Chờ duyệt',
                        default    => 'Chưa tải lên',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending'  => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('license_review')
                    ->label('Bằng lái')
                    ->alignCenter()
                    ->badge()
                    ->state(fn ($record) => $record->driverLicenses()->latest()->value('status') ?? 'none')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        'pending'  => 'Chờ duyệt',
                        default    => 'Chưa tải lên',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending'  => 'warning',
                        default    => 'gray',
                    }),


                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->alignCenter()
                    ->dateTime('d/m/Y'),
            ])
            ->filters([])
            ->actions([
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
                    ->modalHeading('Khóa tài khoản')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Lý do khóa (tuỳ chọn)')
                            ->rows(2),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['status' => 2]);
                        $record->tokens()->delete();
                        RTDBService::setAccountLocked($record->id, true);
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
                        RTDBService::setAccountLocked($record->id, false);
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
