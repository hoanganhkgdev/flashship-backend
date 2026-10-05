<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Filament\Traits\HideFromCityManager;
use App\Services\DriverAccountService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverShiftSession;
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

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Tài xế';

    protected static ?string $modelLabel = 'Tài xế';

    protected static ?string $pluralModelLabel = 'Tài xế';

    protected static ?string $slug = 'drivers';

    protected static ?int $navigationSort = 1;

    /** Tài xế dưới ngưỡng điểm này (đang hoạt động) bị gắn nhãn "điểm thấp". */
    public const LOW_SCORE = 60;

    /**
     * Tài xế tự đăng ký trên app (OTP + CCCD + bằng lái) rồi chờ duyệt. Admin không tạo hộ: form cũ không có mật khẩu,
     * không OTP và không giấy tờ nên tài khoản tạo ra không đăng nhập được và bỏ qua mọi bước xác minh.
     */
    public static function canCreate(): bool
    {
        return false;
    }

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
            ->select('users.*')
            ->addSelect([
                'orders_30d' => DB::table('orders')->selectRaw('COUNT(*)')->whereColumn('orders.delivery_man_id', 'users.id')
                    ->where('orders.status', 'completed')->where('orders.completed_at', '>=', now()->subDays(30)),
                'last_locked_at' => DB::table('driver_status_logs')->select('created_at')->whereColumn('driver_id', 'users.id')->where('action', 'locked')->orderByDesc('id')->limit(1),
                'last_lock_reason' => DB::table('driver_status_logs')->select('reason')->whereColumn('driver_id', 'users.id')->where('action', 'locked')->orderByDesc('id')->limit(1),
            ])
            ->withCount([
                'orders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing']),
                'orders as completed_orders_count' => fn (Builder $query) => $query->where('status', 'completed'),
            ]);
    }

    /**
     * Cấu hình dùng chung cho nút Xóa tài xế (bảng + trang hồ sơ).
     * Xóa cứng: kéo theo ví, công nợ, lịch sử điểm, ca; đơn đã xong mất tham chiếu tài xế.
     */
    public static function configureDeleteAction(Tables\Actions\DeleteAction|\Filament\Actions\DeleteAction $action): void
    {
        $action
            ->modalHeading('Xóa tài xế')
            ->modalDescription('Xóa vĩnh viễn tài khoản tài xế cùng ví, công nợ, lịch sử điểm và ca làm việc. Các đơn cũ sẽ không còn thông tin tài xế. Không thể khôi phục.')
            ->before(function (User $record, $action) {
                $busy = Order::where(fn ($q) => $q->where('delivery_man_id', $record->id)->whereIn('status', ['assigned', 'processing'])
                    ->orWhere(fn ($q) => $q->where('dispatching_to_driver_id', $record->id)->where('status', 'pending')))
                    ->first(['id', 'code']);

                if ($busy) {
                    Notification::make()
                        ->title("Không thể xóa: tài xế đang giữ/được đề nghị đơn #{$busy->code}")
                        ->body('Hãy hoàn tất hoặc điều phối lại đơn trước khi xóa tài xế.')
                        ->danger()->send();
                    $action->cancel();
                }
            })
            ->after(function (User $record) {
                $record->tokens()->delete();
                RTDBService::removeDriverLocation($record->id);
                RTDBService::setAccountLocked($record->id, true);
                try {
                    Redis::del("dispatch:lock:driver:{$record->id}");
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[DriverDelete] Redis cleanup failed: '.$e->getMessage());
                }
            })
            ->successNotificationTitle('Đã xóa tài xế');
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

            Forms\Components\Section::make('Giới thiệu shop')
                ->description('Mã tài xế đưa cho chủ shop nhập khi đăng ký để nhận thưởng')
                ->icon('heroicon-o-gift')
                ->schema([
                    Forms\Components\TextInput::make('referral_code')
                        ->label('Mã giới thiệu')
                        ->disabled()
                        ->dehydrated(false),
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

    /** Dòng mô tả dưới nhãn trạng thái: chờ bao lâu, đang chạy đơn gì, hay bị khóa vì sao. */
    private static function statusNote(User $record): string
    {
        return match ((int) $record->status) {
            0 => 'Chờ '.(int) $record->created_at->diffInDays(now()).' ngày',
            2 => ($record->last_locked_at ? 'Khóa '.\Carbon\Carbon::parse($record->last_locked_at)->format('d/m/Y') : 'Khóa (chưa ghi lý do)')
                .($record->last_lock_reason ? ' · '.\Illuminate\Support\Str::limit($record->last_lock_reason, 26) : ''),
            default => $record->active_orders_count > 0
                ? $record->active_orders_count.' đơn đang chạy'
                : ($record->is_online ? 'Cờ online' : 'Offline'),
        };
    }

    public static function table(Table $table): Table
    {
        $service = app(DriverAccountService::class);

        return $table
            ->columns([
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
                    ->limit(28)
                    ->description(fn (User $record) => $record->phone.($record->license_plate ? ' · '.$record->license_plate : ($record->vehicle_type ? ' · '.$record->vehicle_type : ''))),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        0 => 'Chờ duyệt', 1 => 'Hoạt động', 2 => 'Bị khóa', default => 'Không rõ',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        0 => 'warning', 1 => 'success', 2 => 'danger', default => 'gray',
                    })
                    ->description(fn (User $record): string => self::statusNote($record)),

                Tables\Columns\TextColumn::make('document_summary')
                    ->label('Hồ sơ xác minh')
                    ->state(fn (User $record): string => self::documentSummary($record))
                    ->html(),

                Tables\Columns\TextColumn::make('completed_orders_count')
                    ->label('Đơn hoàn thành')
                    ->numeric()
                    ->sortable()
                    ->description(fn (User $record): string => number_format((int) $record->orders_30d).' trong 30 ngày'),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm')
                    ->formatStateUsing(fn ($state): string => (string) ($state ?? 80))
                    ->color(fn ($state): ?string => ($state ?? 80) < self::LOW_SCORE ? 'danger' : null)
                    ->description(fn (User $record): string => \Illuminate\Support\Str::limit($record->registeredShifts->pluck('name')->implode(', ') ?: 'Chưa đăng ký ca', 20))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Cờ online (chưa kiểm tra GPS)')
                    ->trueLabel('Đang bật online')
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
                Tables\Actions\ViewAction::make()->label('')->tooltip('Xem hồ sơ'),

                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record) => $record->status == 0)
                    ->requiresConfirmation()
                    ->modalHeading('Duyệt tài xế')
                    ->modalDescription(fn (User $record): HtmlString => self::approvalDescription($service->approvalCheck($record)))
                    ->action(function (User $record) use ($service) {
                        $result = $service->approve($record, auth()->id());
                        Notification::make()->title($result['message'])
                            ->body($result['warnings'] ? 'Lưu ý: '.implode('; ', $result['warnings']) : null)
                            ->{$result['ok'] ? 'success' : 'danger'}()->send();
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),

                    Tables\Actions\Action::make('block')
                        ->label('Khóa tài khoản')
                        ->icon('heroicon-m-lock-closed')
                        ->color('danger')
                        ->visible(fn (User $record) => $record->status == 1)
                        ->modalHeading('Khóa tài khoản')
                        ->modalDescription('Lý do được lưu vào lịch sử tài khoản để lần sau biết vì sao tài xế bị khóa.')
                        ->form([
                            Forms\Components\Textarea::make('reason')->label('Lý do khóa')->required()->minLength(3)->maxLength(500)->rows(2),
                        ])
                        ->action(function (User $record, array $data) use ($service) {
                            $result = $service->lock($record, $data['reason'], auth()->id());
                            Notification::make()->title($result['message'])->{$result['ok'] ? 'warning' : 'danger'}()->send();
                        }),

                    Tables\Actions\Action::make('unblock')
                        ->label('Mở khóa')
                        ->icon('heroicon-m-lock-open')
                        ->color('info')
                        ->visible(fn (User $record) => $record->status == 2)
                        ->modalHeading('Mở khóa tài khoản')
                        ->form([
                            Forms\Components\Textarea::make('reason')->label('Ghi chú (tuỳ chọn)')->maxLength(500)->rows(2),
                        ])
                        ->action(function (User $record, array $data) use ($service) {
                            $result = $service->unlock($record, $data['reason'] ?? null, auth()->id());
                            Notification::make()->title($result['message'])->success()->send();
                        }),

                    // Tài xế đã có đơn, ví hoặc công nợ thì không được xóa hẳn: hãy khóa để giữ lịch sử tài chính.
                    tap(Tables\Actions\DeleteAction::make()->label('Xóa')->visible(fn (User $record): bool => ! $service->hasHistory($record)), fn ($a) => static::configureDeleteAction($a)),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Thao tác khác'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_all')
                        ->label('Duyệt các tài xế đã chọn')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Chỉ duyệt những tài xế đang chờ duyệt và đã có CCCD được duyệt. Hồ sơ chưa đủ sẽ được bỏ qua.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) use ($service) {
                            $ok = 0;
                            $skipped = [];
                            foreach ($records->where('status', 0) as $driver) {
                                $result = $service->approve($driver, auth()->id());
                                $result['ok'] ? $ok++ : $skipped[] = $driver->name;
                            }
                            Notification::make()->title("Đã duyệt {$ok} tài xế")
                                ->body($skipped ? 'Bỏ qua '.count($skipped).' hồ sơ chưa đủ CCCD: '.implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? '…' : '') : null)
                                ->{$ok ? 'success' : 'warning'}()->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->poll('30s');
    }

    /** Nội dung hộp xác nhận duyệt: nêu rõ giấy tờ nào chặn và giấy tờ nào chỉ là cảnh báo. */
    public static function approvalDescription(array $check): HtmlString
    {
        $html = '';
        foreach ($check['blockers'] as $b) {
            $html .= '<div style="color:#dc2626">⛔ '.e($b).' — chưa thể duyệt.</div>';
        }
        foreach ($check['warnings'] as $w) {
            $html .= '<div style="color:#d97706">⚠ '.e($w).'.</div>';
        }
        if ($check['ok'] && ! $check['warnings']) {
            $html = '<div style="color:#16a34a">✓ CCCD và bằng lái đều đã được duyệt.</div>';
        }

        return new HtmlString($html ?: 'Xác nhận duyệt tài xế này?');
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
            'view' => Pages\ViewDriver::route('/{record}'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
