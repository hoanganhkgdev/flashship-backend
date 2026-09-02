<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Filament\Traits\HideFromCityManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverGpsEligibleSession;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;

class DriverResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Người dùng';

    protected static ?string $modelLabel = 'Tài xế';

    protected static ?string $pluralModelLabel = 'Tài xế';

    protected static ?string $slug = 'drivers';

    protected static ?int $navigationSort = 2;

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

            Forms\Components\Section::make('Phương tiện')
                ->description('Thông tin phương tiện tài xế sử dụng để giao hàng')
                ->icon('heroicon-o-truck')
                ->schema([
                    Forms\Components\TextInput::make('vehicle_type')
                        ->label('Loại phương tiện'),
                    Forms\Components\TextInput::make('license_plate')
                        ->label('Biển số xe')
                        ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                ])->columns(2),

            Forms\Components\Section::make('Ca làm việc')->icon('heroicon-o-calendar-days')->schema([
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
                    ->searchable(['name', 'phone', 'cccd', 'license_plate'])
                    ->sortable()
                    ->description(fn (User $record) => $record->phone),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm')
                    ->alignCenter()
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ?? 80)
                    ->color(fn ($state) => match (true) {
                        ($state ?? 80) >= 80 => 'success',
                        ($state ?? 80) >= 60 => 'info',
                        ($state ?? 80) >= 40 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('is_online')
                    ->label('Online')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Online' : 'Offline')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('registeredShifts.name')
                    ->label('Ca đăng ký')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->placeholder('Chưa đăng ký'),

                Tables\Columns\TextColumn::make('cccd_review')
                    ->label('CCCD')
                    ->alignCenter()
                    ->badge()
                    ->state(fn ($record) => $record->driverCccdImages()->latest()->value('status') ?? 'none')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        'pending' => 'Chờ duyệt',
                        default => 'Chưa tải lên',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('license_review')
                    ->label('Bằng lái')
                    ->alignCenter()
                    ->badge()
                    ->state(fn ($record) => $record->driverLicenses()->latest()->value('status') ?? 'none')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        'pending' => 'Chờ duyệt',
                        default => 'Chưa tải lên',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->alignCenter()
                    ->dateTime('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Trạng thái kết nối')
                    ->trueLabel('Đang online')
                    ->falseLabel('Đang offline'),
                Tables\Filters\SelectFilter::make('document_status')
                    ->label('Hồ sơ xác minh')
                    ->options([
                        'pending' => 'Có hồ sơ chờ duyệt',
                        'approved' => 'Hồ sơ đã duyệt',
                        'rejected' => 'Hồ sơ bị từ chối',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['value'] ?? null, fn (Builder $query, string $status): Builder => $query->where(function (Builder $query) use ($status) {
                            $query->whereHas('driverCccdImages', fn (Builder $query) => $query->where('status', $status))
                                ->orWhereHas('driverLicenses', fn (Builder $query) => $query->where('status', $status));
                        }));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record) => $record->status == 0)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['status' => 1]);
                        Notification::make()->title('Đã duyệt tài xế '.$record->name)->success()->send();
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
                        $result = DB::transaction(function () use ($record) {
                            $driver = User::whereKey($record->id)->lockForUpdate()->firstOrFail();

                            $activeOrder = Order::where('delivery_man_id', $driver->id)
                                ->whereIn('status', ['assigned', 'processing'])
                                ->lockForUpdate()
                                ->first(['id', 'code']);

                            if ($activeOrder) {
                                return ['blocked' => false, 'active_order' => $activeOrder];
                            }

                            $offers = Order::where('dispatching_to_driver_id', $driver->id)
                                ->where('status', 'pending')
                                ->lockForUpdate()
                                ->get();

                            foreach ($offers as $offer) {
                                $offer->update([
                                    'dispatching_to_driver_id' => null,
                                    'offer_viewed_at' => null,
                                ]);
                                OrderDispatchLog::where('order_id', $offer->id)
                                    ->where('driver_id', $driver->id)
                                    ->where('result', 'pending')
                                    ->update(['result' => 'expired', 'responded_at' => now()]);
                            }

                            DriverShiftSession::where('driver_id', $driver->id)
                                ->whereNull('ended_at')
                                ->lockForUpdate()
                                ->update(['ended_at' => now()]);

                            $gpsSessions = DriverGpsEligibleSession::where('driver_id', $driver->id)
                                ->whereNull('ended_at')->lockForUpdate()->get();
                            foreach ($gpsSessions as $session) {
                                $endedAt = $session->last_gps_at->copy()
                                    ->addSeconds(DriverLocationService::POS_MAX_AGE_SECS)
                                    ->min(now());
                                $session->update(['ended_at' => $endedAt]);
                            }

                            $driver->update([
                                'status' => 2,
                                'is_online' => false,
                                'online_since' => null,
                                'fcm_token' => null,
                            ]);

                            return ['blocked' => true, 'offers' => $offers];
                        });

                        if (! $result['blocked']) {
                            $order = $result['active_order'];
                            Notification::make()
                                ->title("Không thể khóa: tài xế đang giữ đơn #{$order->code}")
                                ->body('Hãy hoàn tất hoặc điều phối đơn đang chạy trước khi khóa tài khoản.')
                                ->danger()->send();

                            return;
                        }

                        $record->tokens()->delete();
                        RTDBService::removeDriverLocation($record->id);
                        RTDBService::setAccountLocked($record->id, true);
                        Redis::del("dispatch:lock:driver:{$record->id}");

                        foreach ($result['offers'] as $offer) {
                            RTDBService::clearDriverOffer($record->id, $offer->id);
                            app(DispatchService::class)->sendToNextDriver($offer->fresh());
                        }

                        Notification::make()->title('Đã khóa tài xế '.$record->name)->warning()->send();
                    }),

                Tables\Actions\Action::make('unblock')
                    ->label('Mở khóa')
                    ->icon('heroicon-o-lock-open')
                    ->color('info')
                    ->visible(fn (User $record) => $record->status == 2)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        DB::transaction(function () use ($record) {
                            User::whereKey($record->id)->lockForUpdate()->update([
                                'status' => 1,
                                'is_online' => false,
                                'online_since' => null,
                            ]);
                        });
                        RTDBService::setAccountLocked($record->id, false);
                        Notification::make()->title('Đã mở khóa tài xế '.$record->name)->success()->send();
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
                        ->action(function ($records) {
                            $pendingIds = $records->where('status', 0)->pluck('id');
                            DB::transaction(fn () => User::whereIn('id', $pendingIds)
                                ->where('user_type', 'driver')
                                ->where('status', 0)
                                ->update(['status' => 1]));
                            Notification::make()->title('Đã duyệt '.$pendingIds->count().' tài xế đang chờ')->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
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
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'view' => Pages\ViewDriver::route('/{record}'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
