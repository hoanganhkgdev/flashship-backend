<?php

namespace App\Filament\Resources;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource\Pages;
use Filament\Forms;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderTimeline;
use App\Support\OrderListPresenter as P;
use Filament\Tables\Enums\FiltersLayout;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $navigationLabel = 'Đơn hàng';

    protected static ?string $modelLabel = 'Đơn hàng';

    protected static ?string $pluralModelLabel = 'Đơn hàng';

    protected static ?int $navigationSort = 1;

    private static function serviceLabels(): array
    {
        static $cache = null;

        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    private static array $statusLabels = [
        'pending' => 'Chờ tài xế',
        'assigned' => 'Đã phân công',
        'processing' => 'Đã lấy hàng',
        'completed' => 'Hoàn thành',
        'cancelled' => 'Đã huỷ',
    ];

    private static array $statusColors = [
        'pending' => 'warning',
        'assigned' => 'info',
        'processing' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];

    private static array $paymentLabels = [
        'cod' => 'COD',
        'prepaid' => 'Thanh toán trước',
        'wallet' => 'Ví',
    ];

    private static function sourceLabel(Order $record): string
    {
        return match ($record->platform) {
            'customer_app' => 'App khách',
            'shop_app' => 'App shop',
            'call_center' => 'Tổng đài',
            default => $record->platform ?: 'Chưa rõ',
        };
    }

    private static function journeySummary(Order $record): string
    {
        $sender = $record->sender?->name;

        return '<div class="fs-order-journey">'
            .'<div class="fs-order-journey__stop fs-order-journey__stop--pickup"><span>'.e($record->pickup_address ?: '—').'</span></div>'
            .'<div class="fs-order-journey__stop fs-order-journey__stop--delivery"><span>'.e($record->delivery_address ?: '—').'</span></div>'
            .($record->order_note ? '<p class="fs-order-journey__note" title="'.e($record->order_note).'">'.e($record->order_note).'</p>' : '')
            .'<div class="fs-order-journey__source">'.e(self::sourceLabel($record)).($sender ? ' · '.e($sender) : '').'</div>'
            .'</div>';
    }

    private static function orderSummary(Order $record): string
    {
        $service = self::serviceLabels()[$record->service_type] ?? $record->service_type;

        return '<div class="fs-order-summary">'
            .'<strong class="fs-order-summary__code">#'.e($record->code).'</strong>'
            .'<span class="fs-order-summary__service">'.e($service ?: '—').'</span>'
            .'<span class="fs-order-summary__time" title="'.e($record->created_at?->format('d/m/Y H:i:s')).'">'.e($record->created_at?->format('H:i · d/m')).'</span>'
            .'</div>';
    }

    private static function paymentSummary(Order $record): string
    {
        $payment = self::$paymentLabels[$record->payment_method] ?? $record->payment_method ?: '—';
        $cod = (int) $record->cod_amount;

        return '<div class="fs-order-payment">'
            .'<strong class="fs-order-payment__fee">'.number_format((int) $record->shipping_fee, 0, ',', '.').'đ</strong>'
            .'<span class="fs-order-chip">'.e($payment).'</span>'
            .($cod > 0 ? '<span class="fs-order-payment__cod">Thu hộ '.number_format($cod, 0, ',', '.').'đ</span>' : '')
            .'</div>';
    }

    private static function statusSummary(Order $record): string
    {
        $color = self::$statusColors[$record->status] ?? 'gray';
        $status = self::$statusLabels[$record->status] ?? $record->status;
        $warning = null;
        $alert = false;

        if ($record->status === 'pending') {
            $waited = max(0, (int) $record->created_at?->diffInMinutes(now()));
            // Chờ đủ thời gian cấu hình mà vẫn chưa ai nhận (hoặc hệ thống đã dừng
            // tìm) thì đổi "Chờ N phút" thành cảnh báo đỏ cho tổng đài/admin.
            $alert = $record->cancel_reason === 'no_driver'
                || $waited >= OperationalSettings::dispatchTimeoutMinutes($record->city_id);
            $warning = $alert ? 'Không có tài xế' : 'Chờ '.$waited.' phút';
        }

        $driver = $record->driver
            ? '<div class="fs-order-assignment__driver"><span title="'.e($record->driver->name).'">'.e($record->driver->name).'</span><small>'.e($record->driver->phone ?: '—').'</small></div>'
            : '<div class="fs-order-assignment__driver fs-order-assignment__driver--empty">Chưa có tài xế</div>';

        if ($record->status === 'cancelled') {
            $warning = OrderTimeline::cancelReasonLabel($record->cancel_reason);
        }

        return '<div class="fs-order-assignment">'
            .'<span class="fs-order-pill fs-order-pill--'.e($color).'">'.e($status).'</span>'
            .$driver
            .($warning ? '<em class="fs-order-assignment__warning'.($alert ? ' is-alert' : '').'">'.e($warning).'</em>' : '')
            .'</div>';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'city:id,name',
            'driver:id,name,phone',
            'sender:id,name,phone',
            'creator:id,name',
        ]);
    }

    /** Đơn đã kết thúc (hoàn thành hoặc hủy): chỉ cho sửa ghi chú, tránh lệch tiền và lịch sử. */
    public static function isFinished(?Order $record): bool
    {
        return in_array($record?->status, ['completed', 'cancelled'], true);
    }

    public static function manualAssignmentForm(Order $record): array
    {
        // Tài xế gán được hiện trước; người chưa gán được vẫn hiện mờ kèm lý do để tổng đài biết vì sao thiếu người.
        $assignable = self::manualAssignmentDriverOptions($record);
        $blocked = collect(self::manualAssignmentCandidates($record))
            ->filter(fn (array $c) => $c['reason'] !== null)
            ->mapWithKeys(fn (array $c) => [$c['id'] => trim($c['name'].($c['phone'] ? ' · '.$c['phone'] : '').' — '.$c['reason'])]);

        return [
            Forms\Components\Select::make('driver_id')
                ->label('Tài xế')
                ->placeholder($assignable ? 'Chọn tài xế đang online' : 'Chưa có tài xế nào gán được ngay')
                ->options(fn (): array => $assignable + $blocked->all())
                ->disableOptionWhen(fn (string $value): bool => $blocked->has((int) $value))
                ->searchable()
                ->preload()
                ->native(false)
                ->required()
                ->noSearchResultsMessage('Không có tài xế phù hợp trong khu vực')
                ->helperText($assignable
                    ? 'Gán trực tiếp, không cần tài xế bấm nhận. Offer đang tìm sẽ được thu hồi tự động. Người bị mờ chưa gán được (lý do ghi bên cạnh).'
                    : ($blocked->isNotEmpty()
                        ? 'Không ai gán được ngay: tài xế phải đang online, không nợ quá hạn, không nghỉ phép và chưa đủ đơn. Lý do của từng người ghi trong danh sách.'
                        : 'Khu vực này chưa có tài xế nào đang hoạt động.')),
        ];
    }

    /** Đơn đã đổi trạng thái/rời khỏi bảng trong lúc hộp thoại đang mở. */
    private static function notifyRecordGone(): bool
    {
        Notification::make()
            ->title('Đơn vừa thay đổi trạng thái — danh sách đã được làm mới, vui lòng kiểm tra lại.')
            ->warning()
            ->send();

        return false;
    }

    public static function assignDriverManually(Order $record, array $data): bool
    {
        $fresh = $record->fresh();
        if (! $fresh || $fresh->status !== 'pending') {
            Notification::make()
                ->title('Đơn không còn ở trạng thái chờ tài xế.')
                ->warning()
                ->send();

            return false;
        }

        $result = app(DispatchService::class)->assignDriverDirectly($fresh, (int) $data['driver_id']);
        $notification = Notification::make()->title($result['message']);
        ($result['success'] ? $notification->success() : $notification->danger())->send();

        return $result['success'];
    }

    /** Tài xế đủ điều kiện nhận gán tay cho đơn này (id => nhãn): online, không nợ quá hạn, không bị khóa điểm/nghỉ phép, chưa đủ đơn. */
    public static function manualAssignmentDriverOptions(Order $record): array
    {
        $now = now();

        return User::query()
            ->where('user_type', 'driver')
            ->where('city_id', $record->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->when($record->service_type === 'car', fn (Builder $query) => $query->where('has_car_license', true))
            ->whereDoesntHave('debts', fn (Builder $query) => $query->where('status', 'overdue'))
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('score_suspended_until')
                    ->orWhere('score_suspended_until', '<=', $now);
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('driver_leave_requests')
                    ->whereColumn('driver_leave_requests.driver_id', 'users.id')
                    ->whereDate('driver_leave_requests.leave_date', today());
            })
            ->select(['id', 'name', 'phone'])
            ->withCount([
                'orders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing']),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $driver) => $driver->active_orders_count < OperationalSettings::maxActiveOrdersPerDriver($record->city_id))
            ->mapWithKeys(fn (User $driver) => [
                $driver->id => trim("{$driver->name} · {$driver->phone} · {$driver->active_orders_count}/".OperationalSettings::maxActiveOrdersPerDriver($record->city_id).' đơn đang chạy'),
            ])
            ->all();
    }

    /**
     * Mọi tài xế đang hoạt động của khu vực kèm lý do chưa gán được (null = gán được). Dùng để tổng đài thấy
     * "vì sao không chọn được người này" thay vì tài xế biến mất khỏi danh sách.
     * Điều kiện khớp với manualAssignmentDriverOptions().
     *
     * @return array<int, array{id:int, name:string, phone:?string, online:bool, active:int, max:int, reason:?string}>
     */
    public static function manualAssignmentCandidates(Order $record): array
    {
        $max = OperationalSettings::maxActiveOrdersPerDriver($record->city_id);
        $now = now();
        $onLeave = DB::table('driver_leave_requests')->whereDate('leave_date', today())->pluck('driver_id')->flip();

        return User::query()
            ->where('user_type', 'driver')
            ->where('city_id', $record->city_id)
            ->where('status', 1)
            ->select(['id', 'name', 'phone', 'is_online', 'has_car_license', 'score_suspended_until'])
            ->withExists(['debts as has_overdue_debt' => fn (Builder $query) => $query->where('status', 'overdue')])
            ->withCount(['orders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing'])])
            ->orderBy('name')
            ->get()
            ->map(function (User $d) use ($record, $max, $now, $onLeave) {
                $reason = match (true) {
                    ! $d->is_online => 'đang offline',
                    $record->service_type === 'car' && ! $d->has_car_license => 'không có bằng lái ô tô',
                    (bool) $d->has_overdue_debt => 'nợ quá hạn',
                    $d->score_suspended_until && $d->score_suspended_until > $now => 'bị khóa nhận đơn do điểm',
                    isset($onLeave[$d->id]) => 'nghỉ phép hôm nay',
                    $d->active_orders_count >= $max => "đủ đơn ({$d->active_orders_count}/{$max})",
                    default => null,
                };

                return ['id' => (int) $d->id, 'name' => (string) $d->name, 'phone' => $d->phone, 'online' => (bool) $d->is_online, 'active' => (int) $d->active_orders_count, 'max' => $max, 'reason' => $reason];
            })
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // ── Cột trái: các trường có thể sửa ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Trạng thái')
                    ->description('Cập nhật tiến trình xử lý của đơn hàng')
                    ->icon('heroicon-o-signal')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Trạng thái')
                            ->options(self::$statusLabels)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Dùng nút thao tác của đơn để hệ thống xử lý điểm, ví, voucher và thông báo đầy đủ.'),
                    ])->columns(2),

                Forms\Components\Section::make('Điểm lấy hàng')->icon('heroicon-o-arrow-up-circle')->schema([
                    Forms\Components\TextInput::make('pickup_address')->label('Địa chỉ lấy')->disabled(fn (?Order $record) => self::isFinished($record))
                        ->extraInputAttributes(['id' => 'edit-pickup-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('pickupMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditPickupMap')),
                        ]),
                    Forms\Components\TextInput::make('pickup_phone')->label('SĐT lấy hàng')->disabled(fn (?Order $record) => self::isFinished($record)),
                ])->columns(2),

                Forms\Components\Section::make('Điểm giao hàng')->icon('heroicon-o-arrow-down-circle')->schema([
                    Forms\Components\TextInput::make('delivery_address')->label('Địa chỉ giao')->disabled(fn (?Order $record) => self::isFinished($record))
                        ->extraInputAttributes(['id' => 'edit-delivery-addr', 'autocomplete' => 'off'])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('deliveryMap')
                                ->label('Bản đồ')->icon('heroicon-o-map-pin')->color('warning')
                                ->action(fn ($livewire) => $livewire->dispatch('openEditDeliveryMap')),
                        ]),
                    Forms\Components\TextInput::make('delivery_phone')->label('SĐT giao hàng')->disabled(fn (?Order $record) => self::isFinished($record)),
                ])->columns(2),

                Forms\Components\Section::make('Phí & thanh toán')->icon('heroicon-o-banknotes')->schema([
                    Forms\Components\TextInput::make('shipping_fee')->label('Phí ship')->numeric()->suffix('đ')
                        ->disabled(fn (?Order $record) => self::isFinished($record))
                        ->helperText(fn (?Order $record) => self::isFinished($record) ? 'Đơn đã hoàn thành hoặc hủy: không sửa được địa chỉ, số điện thoại và phí (chỉ sửa ghi chú).' : 'Mọi thay đổi được ghi vào dòng thời gian của đơn.'),
                ])->columns(1),

                Forms\Components\Section::make('Ghi chú')->icon('heroicon-o-chat-bubble-left-ellipsis')->schema([
                    Forms\Components\Textarea::make('order_note')->label('Ghi chú đơn hàng')->rows(8)->columnSpanFull(),
                ]),

            ])->columnSpan(2),

            // ── Cột phải: thông tin chỉ xem ──
            Forms\Components\Group::make()->schema([

                Forms\Components\Section::make('Thông tin đơn')->icon('heroicon-o-information-circle')->schema([
                    Forms\Components\Placeholder::make('info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Mã đơn:</b> #'.e($record->code).'<br>'
                            .'<b>Dịch vụ:</b> '.e(self::serviceLabels()[$record->service_type] ?? $record->service_type).'<br>'
                            .'<b>Khu vực:</b> '.e($record->city?->name ?? '—').'<br>'
                            .'<b>Nguồn đơn:</b> '.e(match ($record->platform) {
                                'customer_app' => 'App khách',
                                'call_center' => 'Tổng đài',
                                'shop_app' => 'App cửa hàng',
                                default => $record->platform ?? '—',
                            }).'<br>'
                            .'<b>Tạo lúc:</b> '.e($record->created_at?->format('d/m/Y H:i')).'<br>'
                            .'<b>Hoàn thành:</b> '.e($record->completed_at?->format('d/m/Y H:i') ?? '—')
                            .'</div>'
                        )),
                ]),

                Forms\Components\Section::make('Tài xế')->icon('heroicon-o-truck')->schema([
                    Forms\Components\Placeholder::make('driver_info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Tài xế:</b> '.e($record->driver?->name ?? 'Chưa phân công').'<br>'
                            .'<b>SĐT:</b> '.($record->driver?->phone
                                ? '<a href="https://zalo.me/'.e(ltrim($record->driver->phone, '0')).'" target="_blank" style="text-decoration:underline">'.e($record->driver->phone).'</a>'
                                : '—')
                            .'</div>'
                        )),
                ]),

                Forms\Components\Section::make('Chi tiết phí')->icon('heroicon-o-receipt-percent')->schema([
                    Forms\Components\Placeholder::make('fee_info_display')
                        ->label('')
                        ->content(fn ($record) => new HtmlString(
                            '<div style="line-height:2">'
                            .'<b>Khoảng cách:</b> '.e($record->distance ? number_format((float) $record->distance, 1).' km' : '—').'<br>'
                            .'<b>Phụ phí đêm:</b> '.e(number_format($record->night_surcharge ?? 0)).'đ<br>'
                            .'<b>Giảm giá:</b> '.e($record->discount_amount ? number_format($record->discount_amount).'đ' : '—').($record->voucher_code ? ' ('.e($record->voucher_code).')' : '').'<br>'
                            .'<b>Thanh toán:</b> '.e(self::$paymentLabels[$record->payment_method] ?? $record->payment_method)
                            .'</div>'
                        )),
                ]),

            ])->columnSpan(1),

        ])->columns(3);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Chưa có tài xế')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->visible(fn (Order $record): bool => $record->status === 'pending' && $record->cancel_reason === 'no_driver')
                ->schema([
                    Infolists\Components\TextEntry::make('no_driver_alert')
                        ->hiddenLabel()
                        ->state(fn (Order $record): string => 'Quá '.OperationalSettings::dispatchTimeoutMinutes($record->city_id)
                            .' phút chưa có tài xế nhận. Hệ thống đã dừng tự động tìm — vui lòng gán tài xế thủ công hoặc liên hệ khách.')
                        ->weight('bold')
                        ->color('danger'),
                ]),

            Infolists\Components\Section::make('Tổng quan đơn hàng')
                ->description('Thông tin nhận diện và tiến trình xử lý hiện tại')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    Infolists\Components\TextEntry::make('code')
                        ->label('Mã đơn')
                        ->weight('bold')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('service_type')
                        ->label('Dịch vụ')
                        ->badge()
                        ->formatStateUsing(fn ($state) => self::serviceLabels()[$state] ?? $state)
                        ->color('info'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => self::$statusLabels[$state] ?? $state)
                        ->color(fn ($state) => self::$statusColors[$state] ?? 'gray'),

                    Infolists\Components\TextEntry::make('city.name')
                        ->label('Thành phố')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('cancel_reason')
                        ->label('Lý do huỷ')
                        ->default('—')
                        ->visible(fn ($record) => $record->status === 'cancelled'),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Tạo lúc')
                        ->dateTime('d/m/Y H:i'),

                    Infolists\Components\TextEntry::make('completed_at')
                        ->label('Hoàn thành lúc')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                ])->columns(3),

            Infolists\Components\Grid::make(['default' => 1, 'lg' => 2])->schema([
                Infolists\Components\Section::make('Điểm lấy hàng')
                    ->description('Thông tin người gửi và nơi nhận hàng')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->schema([
                        Infolists\Components\TextEntry::make('sender_name')->label('Người gửi')->default('—'),
                        Infolists\Components\TextEntry::make('pickup_phone')->label('SĐT')->default('—')->copyable(),
                        Infolists\Components\TextEntry::make('store_name')->label('Tên cửa hàng')->default('—'),
                        Infolists\Components\TextEntry::make('pickup_address')->label('Địa chỉ')->columnSpanFull(),
                    ])->columns(2),

                Infolists\Components\Section::make('Điểm giao hàng')
                    ->description('Thông tin người nhận và nơi giao hàng')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->schema([
                        Infolists\Components\TextEntry::make('receiver_name')->label('Người nhận')->default('—'),
                        Infolists\Components\TextEntry::make('delivery_phone')->label('SĐT')->default('—')->copyable(),
                        Infolists\Components\TextEntry::make('delivery_address')->label('Địa chỉ')->columnSpanFull(),
                    ])->columns(2),
            ]),

            Infolists\Components\Grid::make(['default' => 1, 'lg' => 2])->schema([
                Infolists\Components\Section::make('Tài xế phụ trách')
                    ->description('Thông tin phân công và đánh giá sau chuyến')
                    ->icon('heroicon-o-truck')
                    ->schema([
                        Infolists\Components\TextEntry::make('driver.name')
                            ->label('Tên tài xế')
                            ->default('Chưa phân công'),

                        Infolists\Components\TextEntry::make('driver.phone')
                            ->label('SĐT tài xế')
                            ->default('—'),

                        Infolists\Components\TextEntry::make('dispatch_attempts')
                            ->label('Lần thử dispatch')
                            ->default(0),

                        Infolists\Components\TextEntry::make('driver_rating')
                            ->label('Đánh giá')
                            ->default('Chưa đánh giá')
                            ->suffix(fn ($state) => $state ? ' ⭐' : ''),

                        Infolists\Components\TextEntry::make('driver_rating_note')
                            ->label('Ghi chú đánh giá')
                            ->default('—'),
                    ])->columns(2),

                Infolists\Components\Section::make('Chi phí & thanh toán')
                    ->description('Các khoản phí, thu hộ và ưu đãi của đơn')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Infolists\Components\TextEntry::make('shipping_fee')
                            ->label('Phí ship')
                            ->formatStateUsing(fn ($state) => number_format((int) $state).'đ'),

                        Infolists\Components\TextEntry::make('bonus_fee')
                            ->label('Thưởng thêm')
                            ->formatStateUsing(fn ($state) => number_format((int) $state).'đ')
                            ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                        Infolists\Components\TextEntry::make('cod_amount')
                            ->label('Thu hộ COD')
                            ->formatStateUsing(fn ($state) => $state ? number_format((int) $state).'đ' : '—'),

                        Infolists\Components\TextEntry::make('discount_amount')
                            ->label('Giảm giá')
                            ->formatStateUsing(fn ($state) => $state ? number_format((int) $state).'đ' : '—'),

                        Infolists\Components\TextEntry::make('distance')
                            ->label('Khoảng cách')
                            ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 1).' km' : '—'),

                        Infolists\Components\TextEntry::make('payment_method')
                            ->label('Thanh toán')
                            ->badge()
                            ->formatStateUsing(fn ($state) => self::$paymentLabels[$state] ?? $state)
                            ->color('info'),

                        Infolists\Components\TextEntry::make('voucher_code')
                            ->label('Voucher')
                            ->default('—'),

                        Infolists\Components\IconEntry::make('is_freeship')
                            ->label('Freeship')
                            ->boolean(),
                    ])->columns(2),
            ]),

            Infolists\Components\Section::make('Hàng hóa & ghi chú')
                ->description('Thông tin cần lưu ý trong quá trình giao nhận')
                ->icon('heroicon-o-archive-box')
                ->schema([
                    Infolists\Components\TextEntry::make('cargo_type')
                        ->label('Loại hàng')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('cargo_weight')
                        ->label('Khối lượng')
                        ->suffix(' kg')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('order_note')
                        ->label('Ghi chú đơn hàng')
                        ->default('Không có ghi chú')
                        ->columnSpanFull(),
                ])->columns(2)->collapsible(),

            Infolists\Components\Section::make('Lịch sử xử lý')
                ->description('Mọi sự kiện của đơn: tạo, phát, nhận, lấy hàng, hoàn thành, hủy, sửa — kèm người thực hiện')
                ->icon('heroicon-o-clock')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('timeline')
                        ->label('')
                        ->state(fn (Order $record): array => OrderTimeline::for($record)->map(fn ($e) => [
                            'text' => $e['text'],
                            'who' => $e['who'] ?: 'Hệ thống',
                            'when' => $e['at']?->format('H:i:s · d/m/Y'),
                        ])->all())
                        ->schema([
                            Infolists\Components\TextEntry::make('text')->label('Sự kiện')->weight('bold'),
                            Infolists\Components\TextEntry::make('who')->label('Bởi')->badge()->color('gray'),
                            Infolists\Components\TextEntry::make('when')->label('Thời gian'),
                        ])
                        ->columns(3),
                ])
                // Mở sẵn khi đơn đang cần tổng đài xử lý để dòng log hiện ngay.
                ->collapsed(false),
        ]);
    }

    // ─── Thao tác dùng chung cho bảng và trang chi tiết ──────────────────────────

    public static function assignAction($action)
    {
        return $action
            ->label('Gán tài xế')
            ->icon('heroicon-o-user-plus')
            ->color('info')
            // $record null khi đơn vừa rời khỏi bảng trong lúc hộp thoại đang mở.
            ->visible(fn (?Order $record) => $record?->status === 'pending')
            ->modalHeading(fn (?Order $record) => $record ? 'Gán tài xế cho đơn #'.$record->code : 'Gán tài xế')
            ->modalDescription('Đơn sẽ ngừng tìm tự động và chuyển thẳng vào danh sách đã nhận của tài xế.')
            ->form(fn (?Order $record): array => $record ? self::manualAssignmentForm($record) : [])
            ->action(fn (?Order $record, array $data) => $record ? self::assignDriverManually($record, $data) : self::notifyRecordGone());
    }

    /** Hủy đơn: bắt buộc chọn lý do, ghi người hủy và dòng thời gian. */
    public static function cancelAction($action)
    {
        return $action
            ->label('Hủy đơn')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (?Order $record) => in_array($record?->status, ['pending', 'assigned'], true))
            ->modalHeading(fn (?Order $record) => $record ? 'Hủy đơn #'.$record->code : 'Hủy đơn')
            ->modalDescription('Lý do hủy được lưu lại để thống kê và đối soát.')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do hủy')->options(OrderTimeline::CANCEL_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Ghi chú')->rows(2)->maxLength(250)
                    ->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(3),
            ])
            ->action(fn (?Order $record, array $data) => self::cancelOrder($record, $data['reason'], $data['note'] ?? null));
    }

    public static function cancelOrder(?Order $record, string $reason, ?string $note): bool
    {
        $fresh = $record?->fresh();
        if (! $fresh) {
            return self::notifyRecordGone();
        }
        $by = auth()->id();

        if ($fresh->status === 'pending') {
            $result = app(OrderService::class)->cancelPendingOrder($fresh, $reason, $note, $by);
        } elseif ($fresh->status === 'assigned') {
            $result = app(OrderService::class)->cancelAssignedOrderByAdmin($fresh, $reason, $note, $by);
            if ($result['success']) {
                RTDBService::clearOrder($fresh->code);
                if ($fresh->driver?->fcm_token) {
                    FCMService::getInstance()->sendDriverNotice(
                        $fresh->driver->fcm_token,
                        "Đơn #{$fresh->code} đã bị hủy",
                        'Tổng đài đã hủy đơn hàng này.',
                        ['type' => 'order_status', 'order_code' => $fresh->code, 'status' => 'cancelled'],
                    );
                }
            }
        } else {
            Notification::make()->title('Chỉ có thể hủy đơn đang chờ hoặc đã nhận.')->danger()->send();

            return false;
        }

        Notification::make()->title($result['success'] ? 'Đã hủy đơn '.$fresh->code : $result['message'])->{$result['success'] ? 'warning' : 'danger'}()->send();

        return $result['success'];
    }

    public static function completeAction($action)
    {
        return $action
            ->label('Hoàn thành đơn')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (?Order $record) => $record?->status === 'processing')
            ->requiresConfirmation()
            ->modalHeading('Hoàn thành đơn hàng')
            ->modalDescription(fn (?Order $record) => $record
                ? 'Xác nhận tài xế đã giao xong đơn '.$record->code.'? Điểm, ví, voucher và các khoản thưởng sẽ được xử lý đầy đủ. Việc này được ghi lại là nhân viên xác nhận.'
                : null)
            ->action(function (?Order $record) {
                $fresh = $record?->fresh(['driver']);
                if (! $fresh) {
                    self::notifyRecordGone();

                    return;
                }
                if (! $fresh->driver) {
                    Notification::make()->title('Đơn không có tài xế phụ trách.')->danger()->send();

                    return;
                }
                $result = app(OrderService::class)->completeOrder($fresh, $fresh->driver, true);
                Notification::make()->title($result['message'])->color($result['success'] ? 'success' : 'danger')->send();
            });
    }

    // ─── Danh sách đơn: dòng gọn ─────────────────────────────────────────────

    private static function cityName(Order $o): ?string
    {
        return $o->city?->name;
    }

    private static function minutesLabel(int $m): string
    {
        return $m >= 1440 ? intdiv($m, 1440).' ngày' : ($m >= 60 ? intdiv($m, 60).'g'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : $m.' phút');
    }

    private static function colOrder(Order $o): string
    {
        $service = self::serviceLabels()[$o->service_type] ?? $o->service_type;
        $age = $o->status === 'pending'
            ? ' · <span class="fs-ol-wait'.(self::isStaleWait($o) ? ' is-alert' : '').'">chờ '.self::minutesLabel((int) $o->created_at->diffInMinutes(now())).'</span>'
            : ($o->status === 'completed' && $o->completed_at ? ' · giao '.self::minutesLabel((int) $o->created_at->diffInMinutes($o->completed_at)) : '');

        return '<div class="fs-ol-order"><span class="fs-ol-l1"><b>#'.e($o->code).'</b><span class="fs-ol-svc" title="'.e($service).'">'.e(\Illuminate\Support\Str::limit((string) ($service ?: '—'), 14)).'</span></span>'
            .'<span class="fs-ol-dim" title="'.e($o->created_at?->format('d/m/Y H:i:s')).'">'.e($o->created_at?->format('H:i · d/m')).$age.'</span></div>';
    }

    private static function isStaleWait(Order $o): bool
    {
        static $cache = [];
        $min = $cache[$o->city_id] ??= OperationalSettings::dispatchTimeoutMinutes($o->city_id);

        return $o->cancel_reason === 'no_driver' || $o->created_at->diffInMinutes(now()) >= $min;
    }

    private static function colJourney(Order $o): string
    {
        $note = trim((string) $o->order_note);

        return '<div class="fs-ol-journey"><span class="fs-ol-from" title="'.e($o->pickup_address).'">'.e(trim((string) $o->pickup_address) ?: '—').'</span>'
            .'<span class="fs-ol-to" title="'.e($o->delivery_address).'">'.(trim((string) $o->delivery_address) !== '' ? e(trim((string) $o->delivery_address)) : '<i class="fs-ol-nodel">Chưa có điểm giao</i>').'</span>'
            .($note !== '' ? '<span class="fs-ol-note"><svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H9l-4 3v-3a2 2 0 01-2-2V4z"/></svg><b>Ghi chú</b><span class="fs-ol-note__txt">'.e($note).'</span><span class="fs-ol-note__pop">'.e($note).'</span></span>' : '').'</div>';
    }

    private static function colCustomer(Order $o): string
    {
        $phone = P::contactPhone($o);
        $by = $o->platform === 'call_center'
            ? 'Tổng đài'.($o->creator && mb_strtolower(trim($o->creator->name)) !== 'tổng đài' ? ' · '.$o->creator->name : '')
            : self::sourceLabel($o);

        return '<div class="fs-ol-customer"><b>'.e(P::customerName($o)).'</b>'
            .'<span class="fs-ol-dim">'.($phone ? '<span class="fs-ol-phone" title="Bấm để sao chép" onclick="navigator.clipboard&&navigator.clipboard.writeText(\''.e(preg_replace('/\D+/', '', $phone)).'\');event.stopPropagation()">'.e(P::phone($phone)).'</span>' : 'Chưa có SĐT').' · '.e($by).'</span></div>';
    }

    private static function colStatus(Order $o): string
    {
        $color = self::$statusColors[$o->status] ?? 'gray';
        $label = self::$statusLabels[$o->status] ?? $o->status;
        $second = $o->status === 'cancelled'
            ? '<span class="fs-ol-reason" title="'.e($o->cancel_note).'">'.e(OrderTimeline::cancelReasonLabel($o->cancel_reason)).'</span>'
            : ($o->driver
                ? '<span class="fs-ol-driver"><b>'.e($o->driver->name).'</b> <small>'.e(P::phone($o->driver->phone)).'</small></span>'
                : '<span class="fs-ol-dim">Chưa có tài xế</span>');

        return '<div class="fs-ol-status"><span class="fs-order-pill fs-order-pill--'.e($color).'">'.e($label).'</span>'.$second.'</div>';
    }

    private static function colMoney(Order $o): string
    {
        $payment = self::$paymentLabels[$o->payment_method] ?? $o->payment_method ?: '—';
        $cod = (int) $o->cod_amount;
        $flags = ($o->is_freeship ? '<span class="fs-order-chip" title="Khách không trả phí ship">Freeship</span>' : '')
            .($o->fee_note ? '<span class="fs-order-chip" title="'.e($o->fee_note).'">Phí đã chỉnh</span>' : '');

        return '<div class="fs-ol-money"><span class="fs-ol-l1"><b>'.number_format((int) $o->shipping_fee, 0, ',', '.').'đ</b>'.$flags.'</span>'
            .'<span class="fs-ol-dim">'.e($payment).($cod > 0 ? ' · thu hộ '.number_format($cod, 0, ',', '.').'đ' : '').'</span></div>';
    }

    /** Tìm thông minh: SĐT / mã đơn / địa chỉ-tài xế-ghi chú. */
    public static function smartSearch(Builder $query, string $search): Builder
    {
        $s = trim($search);
        $digits = preg_replace('/\D+/', '', $s);

        // Chỉ gồm số và ký tự phân cách → SĐT (đầy đủ hoặc vài số cuối) hoặc mã đơn.
        if ($digits !== '' && preg_match('/^[\d\s.+\-]+$/', $s)) {
            $tail = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

            return $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$digits}%")
                ->orWhere('pickup_phone', 'like', "%{$tail}")
                ->orWhere('delivery_phone', 'like', "%{$tail}")
                ->orWhereHas('driver', fn (Builder $d) => $d->where('phone', 'like', "%{$tail}"))
                ->orWhereHas('sender', fn (Builder $d) => $d->where('phone', 'like', "%{$tail}")));
        }

        return $query->where(fn (Builder $q) => $q
            ->where('code', 'like', "%{$s}%")
            ->orWhere('pickup_address', 'like', "%{$s}%")
            ->orWhere('delivery_address', 'like', "%{$s}%")
            ->orWhere('order_note', 'like', "%{$s}%")
            ->orWhere('sender_name', 'like', "%{$s}%")
            ->orWhere('receiver_name', 'like', "%{$s}%")
            ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$s}%"))
            ->orWhereHas('sender', fn (Builder $d) => $d->where('name', 'like', "%{$s}%")));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Đơn')
                    ->formatStateUsing(fn ($state, Order $record): string => self::colOrder($record))
                    ->html()
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::smartSearch($query, $search)),

                Tables\Columns\TextColumn::make('journey')
                    ->label('Hành trình')
                    ->state(fn (Order $record): string => self::colJourney($record))
                    ->html(),

                Tables\Columns\TextColumn::make('customer')
                    ->label('Khách')
                    ->state(fn (Order $record): string => self::colCustomer($record))
                    ->html(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state, Order $record): string => self::colStatus($record))
                    ->html(),

                Tables\Columns\TextColumn::make('money')
                    ->label('Tiền')
                    ->state(fn (Order $record): string => self::colMoney($record))
                    ->html(),
            ])
            ->recordClasses(fn (Order $record): string => 'fs-ol-row fs-order-row--'.(self::$statusColors[$record->status] ?? 'gray'))
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->filters([
                SelectFilter::make('period')
                    ->label('Thời gian')
                    ->options(['today' => 'Hôm nay', 'yesterday' => 'Hôm qua', '7d' => '7 ngày qua', '30d' => '30 ngày qua'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'today' => $query->whereDate('created_at', today()),
                        'yesterday' => $query->whereDate('created_at', today()->subDay()),
                        '7d' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
                        '30d' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
                        default => $query,
                    }),
                SelectFilter::make('service_type')->label('Dịch vụ')->options(fn (): array => self::serviceLabels()),
                SelectFilter::make('platform')->label('Nguồn đơn')->options(['customer_app' => 'App khách hàng', 'shop_app' => 'App cửa hàng', 'call_center' => 'Tổng đài']),
                SelectFilter::make('created_by')
                    ->label('Người tạo đơn')
                    ->options(fn (): array => User::whereIn('id', \Illuminate\Support\Facades\DB::table('orders')->whereNotNull('created_by')->where('created_at', '>=', now()->subDays(90))->distinct()->pluck('created_by'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('delivery_man_id')
                    ->label('Tài xế')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('payment_method')->label('Thanh toán')->options(self::$paymentLabels),
                SelectFilter::make('cancel_reason')
                    ->label('Lý do hủy')
                    ->options(fn (): array => collect(OrderTimeline::CANCEL_REASONS)->merge(['admin' => 'Admin hủy (chưa ghi lý do)', 'no_driver' => 'Hệ thống: hết thời gian tìm tài xế', 'customer' => 'Khách/cửa hàng hủy trong app'])->all()),
                Tables\Filters\Filter::make('created_at')
                    ->label('Khoảng ngày tuỳ chọn')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                // Thao tác hay dùng nhất hiện sẵn ngay trên dòng; phần còn lại trong "⋯".
                self::assignAction(Tables\Actions\Action::make('assignDriver'))->label('Gán')->button()->size('xs')->outlined(),
                self::completeAction(Tables\Actions\Action::make('complete'))->label('Hoàn thành')->button()->size('xs')->outlined(),
                self::cancelAction(Tables\Actions\Action::make('cancel'))->label('Hủy')->button()->size('xs')->outlined(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem nhanh')->icon('heroicon-o-eye')
                        ->slideOver()
                        ->modalHeading(fn (Order $r) => 'Đơn #'.$r->code)
                        // Đơn có thể rời danh sách (được gán/hủy) lúc xem nhanh đang mở: lúc đó $r là bản ghi rỗng, không có id để dựng link
                        ->extraModalFooterActions(fn (Order $r): array => $r->exists ? [
                            Tables\Actions\Action::make('openPage')->label('Mở trang đầy đủ')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                                ->url(static::getUrl('view', ['record' => $r])),
                        ] : []),
                    Tables\Actions\Action::make('openPageLink')->label('Mở trang đầy đủ')->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Order $r) => static::getUrl('view', ['record' => $r])),
                    Tables\Actions\EditAction::make()->label('Sửa đơn'),
                    Tables\Actions\Action::make('reorder')
                        ->label('Đặt lại')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->visible(fn (Order $record) => in_array($record->status, ['cancelled', 'completed']))
                        ->url(fn (Order $record) => CallCenterPage::getUrl().'?'.http_build_query(array_filter([
                            'reorder' => $record->id,
                            'service' => $record->service_type,
                            'city_id' => $record->city_id,
                            'pickup_address' => $record->pickup_address,
                            'pickup_phone' => $record->pickup_phone,
                            'pickup_lat' => $record->pickup_lat,
                            'pickup_lng' => $record->pickup_lng,
                            'delivery_address' => $record->delivery_address,
                            'delivery_phone' => $record->delivery_phone,
                            'delivery_lat' => $record->delivery_lat,
                            'delivery_lng' => $record->delivery_lng,
                            'order_note' => $record->order_note,
                        ]))),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            // Bấm dòng mở ngăn kéo xem nhanh (dòng thời gian, hành trình, tiền); "Mở trang đầy đủ" trong ngăn kéo hoặc menu ⋯.
            ->recordUrl(null)
            ->recordAction(Tables\Actions\ViewAction::class)
            ->bulkActions([])
            ->actionsAlignment('end')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
