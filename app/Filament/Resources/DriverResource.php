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

    protected static ?string $navigationGroup = 'Người dùng & đối tác';

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
        return parent::getEloquentQuery()
            ->where('user_type', 'driver')
            ->with([
                'registeredShifts' => fn ($query) => $query->select(['shifts.id', 'shifts.name']),
                'latestDriverCccdImage',
                'latestDriverLicense',
            ])
            ->withCount([
                'orders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing']),
                'orders as completed_orders_count' => fn (Builder $query) => $query->where('status', 'completed'),
            ]);
    }

    private static function accountSummary(User $record): string
    {
        [$status, $statusClass] = match ((int) $record->status) {
            0 => ['Chờ duyệt', 'warning'],
            1 => ['Hoạt động', 'success'],
            2 => ['Bị khóa', 'danger'],
            default => ['Không rõ', 'gray'],
        };
        $online = $record->is_online ? 'Đang online' : 'Đang offline';
        $onlineClass = $record->is_online ? 'success' : 'gray';
        $order = $record->active_orders_count > 0 ? ' · '.$record->active_orders_count.' đơn đang chạy' : '';

        return '<div class="fs-driver-state">'
            .'<span class="fs-driver-state--'.e($statusClass).'">'.e($status).'</span>'
            .'<span class="fs-driver-state--'.e($onlineClass).'">'.e($online.$order).'</span>'
            .'</div>';
    }

    private static function documentSummary(User $record): string
    {
        $label = fn (?string $status): array => match ($status) {
            'approved' => ['Đã duyệt', 'success'],
            'rejected' => ['Từ chối', 'danger'],
            'pending' => ['Chờ duyệt', 'warning'],
            default => ['Chưa tải lên', 'gray'],
        };
        [$cccd, $cccdClass] = $label($record->latestDriverCccdImage?->status);
        [$license, $licenseClass] = $label($record->latestDriverLicense?->status);

        return '<div class="fs-driver-docs">'
            .'<div><span>CCCD:</span><em class="fs-driver-state--'.e($cccdClass).'">'.e($cccd).'</em></div>'
            .'<div><span>Bằng lái:</span><em class="fs-driver-state--'.e($licenseClass).'">'.e($license).'</em></div>'
            .'</div>';
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
                    ->label('Tài xế')
                    ->searchable(['name', 'phone', 'cccd', 'license_plate'])
                    ->sortable()
                    ->description(fn (User $record) => $record->phone),

                Tables\Columns\TextColumn::make('account_summary')
                    ->label('Trạng thái vận hành')
                    ->state(fn (User $record): string => self::accountSummary($record))
                    ->html(),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm & ca làm việc')
                    ->formatStateUsing(fn ($state): string => (string) ($state ?? 80).' điểm')
                    ->description(fn (User $record): string => $record->registeredShifts->pluck('name')->implode(', ') ?: 'Chưa đăng ký ca')
                    ->sortable(),

                Tables\Columns\TextColumn::make('document_summary')
                    ->label('Hồ sơ xác minh')
                    ->state(fn (User $record): string => self::documentSummary($record))
                    ->html(),

                Tables\Columns\TextColumn::make('vehicle_type')
                    ->label('Phương tiện')
                    ->placeholder('Chưa cập nhật')
                    ->description(fn (User $record): string => $record->license_plate ?: 'Chưa có biển số'),

                Tables\Columns\TextColumn::make('completed_orders_count')
                    ->label('Đơn hoàn thành')
                    ->alignCenter()
                    ->sortable(),

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
                Tables\Filters\SelectFilter::make('registered_shift')
                    ->label('Ca làm việc')
                    ->relationship('registeredShifts', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->tooltip('Duyệt tài xế')
                    ->visible(fn (User $record) => $record->status == 0)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['status' => 1]);
                        Notification::make()->title('Đã duyệt tài xế '.$record->name)->success()->send();
                    }),

                Tables\Actions\Action::make('block')
                    ->label('')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->tooltip('Khóa tài xế')
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
                    ->label('')
                    ->icon('heroicon-o-lock-open')
                    ->color('info')
                    ->tooltip('Mở khóa tài xế')
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

                Tables\Actions\ViewAction::make()->label('')->tooltip('Xem hồ sơ'),
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa'),
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
