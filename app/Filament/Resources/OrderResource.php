<?php

namespace App\Filament\Resources;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource\Pages;
use App\Support\AdminAccess;
use App\Support\OrderListPresenter as P;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\Order;
use Modules\Order\Services\ManualOrderAssignment;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderTimeline;

class OrderResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_VIEW);
    }

    public static function canCreate(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE);
    }

    public static function canEdit($record): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE);
    }

    public static function canDelete($record): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE);
    }

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
            .(($journeyNote = self::displayNote($record->order_note)) !== '' ? '<p class="fs-order-journey__note" title="'.e($journeyNote).'">'.e($journeyNote).'</p>' : '')
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

        if ($record->status === 'pending') {
            $waited = max(0, (int) $record->created_at?->diffInMinutes(now()));
            $warning = 'Chờ '.$waited.' phút';
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
            .($warning ? '<em class="fs-order-assignment__warning">'.e($warning).'</em>' : '')
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

    /**
     * Phụ phí đêm áp cho phí sửa tay: đã ghi trên đơn thì giữ nguyên; đơn tạo với phí 0 (tài xế báo phí sau) thì tính theo
     * giờ TẠO đơn, giống lúc đặt. Đơn đã có phí mà chưa từng có phụ phí thì không tự thêm.
     */
    /** Link "Đặt lại đơn" sang trang Tổng đài: một nơi dựng tham số cho cả bảng và trang chi tiết. */
    public static function reorderUrl(Order $record): string
    {
        return CallCenterPage::getUrl().'?'.http_build_query(array_filter([
            'reorder' => $record->id,
            'service' => $record->service_type,
            'pickup_address' => $record->pickup_address,
            'pickup_place_name' => $record->pickup_place_name,
            'pickup_lat' => $record->pickup_lat,
            'pickup_lng' => $record->pickup_lng,
            'order_note' => $record->order_note,
        ]));
    }

    public static function nightSurchargeFor(?Order $record): int
    {
        if (! $record) {
            return 0;
        }
        if ((int) $record->night_surcharge > 0) {
            return (int) $record->night_surcharge;
        }
        if ((int) $record->shipping_fee > 0) {
            return 0;
        }

        return OperationalSettings::nightSurcharge($record->city_id, $record->created_at ?? now());
    }

    public static function manualAssignmentForm(Order $record): array
    {
        // Tài xế gán được hiện trước; người chưa gán được vẫn hiện mờ kèm lý do để tổng đài biết vì sao thiếu người.
        $candidates = self::manualAssignmentCandidates($record);
        $assignable = self::manualAssignmentOptionsFrom($candidates);
        $blocked = collect($candidates)
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
        abort_unless(AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE), 403);

        $fresh = $record->fresh();
        if (! $fresh || $fresh->status !== 'pending') {
            Notification::make()
                ->title('Đơn không còn ở trạng thái chờ tài xế.')
                ->warning()
                ->send();

            return false;
        }

        $result = app(ManualOrderAssignment::class)->assign($fresh, (int) $data['driver_id']);
        $notification = Notification::make()->title($result['message']);
        ($result['success'] ? $notification->success() : $notification->danger())->send();

        return $result['success'];
    }

    /** Tài xế đủ điều kiện nhận gán tay cho đơn này (id => nhãn): online, không nợ quá hạn, không bị khóa điểm/nghỉ phép, chưa đủ đơn. */
    public static function manualAssignmentDriverOptions(Order $record): array
    {
        return self::manualAssignmentOptionsFrom(self::manualAssignmentCandidates($record));
    }

    /**
     * Rút danh sách chọn (id => nhãn) từ kết quả manualAssignmentCandidates(), để nơi đã có candidates không phải truy vấn lại.
     *
     * @param  array<int, array{id:int, name:string, phone:?string, active:int, max:int, reason:?string}>  $candidates
     * @return array<int, string>
     */
    public static function manualAssignmentOptionsFrom(array $candidates): array
    {
        return collect($candidates)
            ->filter(fn (array $c) => $c['reason'] === null)
            ->mapWithKeys(fn (array $c) => [$c['id'] => trim("{$c['name']} · {$c['phone']} · {$c['active']}/{$c['max']} đơn đang chạy")])
            ->all();
    }

    /**
     * Mọi tài xế đang hoạt động của khu vực kèm lý do chưa gán được (null = gán được). Dùng để tổng đài thấy
     * "vì sao không chọn được người này" thay vì tài xế biến mất khỏi danh sách.
     * Điều kiện khớp với ManualOrderAssignment::assign() (nợ quá hạn chỉ chặn khi tài xế áp dụng công nợ).
     *
     * @return array<int, array{id:int, name:string, phone:?string, online:bool, active:int, max:int, reason:?string}>
     */
    public static function manualAssignmentCandidates(Order $record): array
    {
        $max = OperationalSettings::maxActiveOrdersPerDriver($record->city_id);
        $now = now();
        $onLeave = DB::table('driver_leave_requests')->where('status', 'approved')->whereDate('leave_date', today())->pluck('driver_id')->flip();

        return User::query()
            ->where('user_type', 'driver')
            ->where('city_id', $record->city_id)
            ->where('status', 1)
            ->select(['id', 'name', 'phone', 'city_id', 'is_online', 'has_car_license', 'debt_enabled'])
            ->withExists(['debts as has_overdue_debt' => fn (Builder $query) => $query->where('status', 'overdue')])
            ->withCount(['orders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing'])])
            ->orderBy('name')
            ->get()
            ->map(function (User $d) use ($record, $max, $now, $onLeave) {
                $reason = match (true) {
                    ! $d->is_online => 'đang offline',
                    $record->service_type === 'car' && ! $d->has_car_license => 'không có bằng lái ô tô',
                    $d->has_overdue_debt && $d->debtApplies() => 'nợ quá hạn',
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
                    // Toạ độ đi cùng địa chỉ: JS điền khi chọn gợi ý/bản đồ; gõ tay thì EditOrder tự geocode.
                    Forms\Components\Hidden::make('pickup_lat'),
                    Forms\Components\Hidden::make('pickup_lng'),
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
                    Forms\Components\Hidden::make('delivery_lat'),
                    Forms\Components\Hidden::make('delivery_lng'),
                ])->columns(2),

                Forms\Components\Section::make('Phí & thanh toán')->icon('heroicon-o-banknotes')->schema([
                    // Ô hiện phí CHƯA gồm phụ phí đêm; EditOrder cộng lại khi lưu (shipping_fee lưu đã gồm, xem OrderMoney).
                    Forms\Components\TextInput::make('shipping_fee')->label('Phí ship')->numeric()->minValue(0)->suffix('đ')
                        ->disabled(fn (?Order $record) => self::isFinished($record))
                        ->helperText(fn (?Order $record) => self::isFinished($record)
                            ? 'Đơn đã hoàn thành hoặc hủy: không sửa được địa chỉ, số điện thoại và phí (chỉ sửa ghi chú).'
                            : (($night = self::nightSurchargeFor($record)) > 0
                                ? 'Chưa gồm '.number_format($night, 0, ',', '.').'đ phụ phí đêm — hệ thống tự cộng khi có phí. Mọi thay đổi được ghi vào dòng thời gian của đơn.'
                                : 'Để 0 nếu chưa biết phí. Mọi thay đổi được ghi vào dòng thời gian của đơn.')),
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

    /**
     * Trang chi tiết dùng chung khung "Xem nhanh" của danh sách: một thiết kế, một nơi sửa (trạng thái + tiến trình,
     * hành trình, tài xế, thanh toán, lịch sử).
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->columns(1)->schema([
            Infolists\Components\ViewEntry::make('quick_view')
                ->hiddenLabel()
                ->view('filament.resources.order-resource.partials.quick-view')
                ->columnSpanFull(),
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
            ->visible(fn (?Order $record) => AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE) && $record?->status === 'pending')
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
            ->visible(fn (?Order $record) => AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE) && in_array($record?->status, ['pending', 'assigned', 'processing'], true))
            ->modalHeading(fn (?Order $record) => $record ? 'Hủy đơn #'.$record->code : 'Hủy đơn')
            ->modalDescription(fn (?Order $record) => $record?->status === 'processing'
                ? 'Đơn đã được tài xế bấm lấy hàng. Hủy sẽ chặn thanh toán freeship/bonus mưa khi hoàn thành; lý do và người thao tác được lưu để đối soát.'
                : 'Lý do hủy được lưu lại để thống kê và đối soát.')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do hủy')->options(OrderTimeline::CANCEL_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Ghi chú')->rows(2)->maxLength(250)
                    ->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(3),
            ])
            ->action(fn (?Order $record, array $data) => self::cancelOrder($record, $data['reason'], $data['note'] ?? null));
    }

    public static function cancelOrder(?Order $record, string $reason, ?string $note): bool
    {
        abort_unless(AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE), 403);

        $fresh = $record?->fresh();
        if (! $fresh) {
            return self::notifyRecordGone();
        }
        $by = auth()->id();

        if ($fresh->status === 'pending') {
            $result = app(OrderService::class)->cancelPendingOrder($fresh, $reason, $note, $by);
        } elseif (in_array($fresh->status, ['assigned', 'processing'], true)) {
            $result = app(OrderService::class)->cancelActiveOrderByAdmin($fresh, $reason, $note, $by);
            if ($result['success']) {
                // Đơn đã hủy trong DB: lỗi Firebase/Redis lúc dọn realtime hoặc báo tài xế không được thành 500
                // (admin bấm lại sẽ gặp "đơn không còn đang thực hiện" và tài xế có thể không được báo).
                try {
                    RTDBService::clearOrder($fresh->code);
                    if ($fresh->driver?->fcm_token) {
                        FCMService::getInstance()->sendDriverNotice(
                            $fresh->driver->fcm_token,
                            "Đơn #{$fresh->code} đã bị hủy",
                            'Tổng đài đã hủy đơn hàng này.',
                            ['type' => 'order_status', 'order_code' => $fresh->code, 'status' => 'cancelled'],
                        );
                    }
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Đã hủy đơn, nhưng chưa báo được cho tài xế qua app — hãy gọi tài xế.')->warning()->send();
                }
            }
        } else {
            Notification::make()->title('Chỉ có thể hủy đơn đang chờ, đã nhận hoặc đã lấy hàng.')->danger()->send();

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
            ->visible(fn (?Order $record) => AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE) && $record?->status === 'processing')
            ->requiresConfirmation()
            ->modalHeading('Hoàn thành đơn hàng')
            ->modalDescription(fn (?Order $record) => $record
                ? 'Xác nhận tài xế đã giao xong đơn '.$record->code.'? Điểm, ví, voucher và các khoản thưởng sẽ được xử lý đầy đủ. Việc này được ghi lại là nhân viên xác nhận.'
                : null)
            ->action(function (?Order $record) {
                abort_unless(AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE), 403);

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

    /** 45 phút · 1g04 · 12 ngày — dùng chung cho bảng và ô KPI "Chờ lâu nhất". */
    public static function minutesLabel(int $m): string
    {
        return $m >= 1440 ? intdiv($m, 1440).' ngày' : ($m >= 60 ? intdiv($m, 60).'g'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : $m.' phút');
    }

    private static function colOrder(Order $o): string
    {
        $waitMinutes = $o->status === 'pending' ? (int) $o->created_at->diffInMinutes(now()) : 0;
        $age = $o->status === 'pending'
            ? '<span class="fs-ol-wait'.($waitMinutes >= 5 ? ' is-alert' : '').'">Chờ '.self::minutesLabel($waitMinutes).'</span>'
            : ($o->status === 'completed' && $o->completed_at ? '<span class="fs-ol-speed">Giao '.self::minutesLabel((int) $o->created_at->diffInMinutes($o->completed_at)).'</span>' : '');

        return '<div class="fs-ol-order"><span class="fs-ol-l1"><b>#'.e($o->code).'</b></span>'
            .'<span class="fs-ol-dim" title="'.e($o->created_at?->format('d/m/Y H:i:s')).'"><span class="fs-ol-created">'.e($o->created_at?->format('H:i · d/m')).'</span>'.$age.'</span></div>';
    }

    /** Ghi chú hiển thị cho nhân viên: bỏ nhãn "[Chưa có điểm giao …]" tổng đài chèn sẵn cho tài xế. */
    private static function displayNote(?string $note): string
    {
        return trim((string) preg_replace('/^\s*\[Chưa có điểm giao[^\]]*\]\s*/u', '', (string) $note));
    }

    private static function colJourney(Order $o): string
    {
        $note = self::displayNote($o->order_note);
        $pickup = trim((string) $o->pickup_address) ?: '—';
        $delivery = trim((string) $o->delivery_address);
        $service = (self::serviceLabels()[$o->service_type] ?? $o->service_type) ?: '—';
        $fee = number_format((int) $o->shipping_fee, 0, ',', '.').'đ';

        return '<div class="fs-ol-journey"><span class="fs-ol-stop fs-ol-stop--pickup"><span><small class="fs-ol-stop__label">Điểm lấy:</small><b title="'.e($pickup).'">'.e($pickup).'</b></span></span>'
            .(in_array($o->platform, ['customer_app', 'shop_app'], true)
                ? '<span class="fs-ol-stop fs-ol-stop--delivery"><span><small class="fs-ol-stop__label">Điểm giao:</small><b title="'.e($delivery).'">'.($delivery !== '' ? e($delivery) : '<em>Chưa có điểm giao</em>').'</b></span></span>'
                : '')
            .($note !== '' ? '<details class="fs-ol-note"><summary><span class="fs-ol-note__txt" title="'.e($note).'">'.e($note).'</span><span class="fs-ol-note__toggle"></span></summary></details>' : '')
            .'<span class="fs-ol-journey-meta"><span class="fs-ol-type">'.e($service).'</span><span class="fs-ol-meta-separator">·</span><span class="fs-ol-fee"><small>Phí ship</small><b>'.$fee.'</b></span></span></div>';
    }

    private static function colCustomer(Order $o): string
    {
        $phone = P::contactPhone($o);
        $name = P::customerName($o);
        $initial = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '?';
        $by = $o->platform === 'call_center'
            ? 'Tổng đài'.($o->creator && mb_strtolower(trim($o->creator->name)) !== 'tổng đài' ? ' · '.$o->creator->name : '')
            : self::sourceLabel($o);

        return '<div class="fs-ol-customer"><span class="fs-ol-avatar">'.e($initial).'</span><span class="fs-ol-customer__body"><b>'.e($name).'</b>'
            .'<span>'.($phone ? '<span class="fs-ol-phone" title="Bấm để sao chép" onclick="navigator.clipboard&&navigator.clipboard.writeText(\''.e(preg_replace('/\D+/', '', $phone)).'\');event.stopPropagation()">'.e(P::phone($phone)).'</span>' : '<em>Chưa có SĐT</em>').'</span><small>'.e($by).'</small></span></div>';
    }

    private static function colStatus(Order $o): string
    {
        $color = self::$statusColors[$o->status] ?? 'gray';
        $label = self::$statusLabels[$o->status] ?? $o->status;
        $second = $o->status === 'cancelled'
            ? '<span class="fs-ol-reason" title="'.e($o->cancel_note).'">'.e(OrderTimeline::cancelReasonLabel($o->cancel_reason)).'</span>'
            : ($o->driver
                ? '<span class="fs-ol-driver"><i>'.e(mb_strtoupper(mb_substr(trim((string) $o->driver->name), 0, 1)) ?: '?').'</i><span><b>'.e($o->driver->name).'</b><small>'.e(P::phone($o->driver->phone)).'</small></span></span>'
                : '<span class="fs-ol-no-driver">Chưa có tài xế</span>');

        return '<div class="fs-ol-status"><span class="fs-order-pill fs-order-pill--'.e($color).'">'.e($label).'</span>'.$second.'</div>';
    }

    /** Tìm thông minh: SĐT / mã đơn / địa chỉ-tài xế-ghi chú. */
    public static function smartSearch(Builder $query, string $search): Builder
    {
        $s = trim($search);
        $digits = preg_replace('/\D+/', '', $s);

        if ($s === '') {
            return $query;
        }

        // Mỗi cụm được phép nằm ở một cột khác nhau. Ví dụ "421 102" sẽ
        // khớp điểm lấy "421 Ngô Quyền" và điểm giao "102 ..." trên cùng đơn.
        $terms = array_values(array_filter(preg_split('/\s+/u', $s) ?: []));

        $matchTerms = function (Builder $q) use ($terms): void {
            foreach ($terms as $term) {
                $numericHouseNumber = ctype_digit($term) && strlen($term) <= 3;

                $q->where(function (Builder $part) use ($term, $numericHouseNumber) {
                    if ($numericHouseNumber) {
                        // "173" thường là số nhà. Không dò chuỗi này trong
                        // SĐT/mã đơn vì sẽ trả về rất nhiều kết quả không liên quan.
                        $pattern = '(^|[^0-9])'.preg_quote($term, '/').'([^0-9]|$)';
                        $part->where('pickup_address', 'regexp', $pattern)
                            ->orWhere('delivery_address', 'regexp', $pattern);

                        return;
                    }

                    $part->where('code', 'like', "%{$term}%")
                        ->orWhere('pickup_address', 'like', "%{$term}%")
                        ->orWhere('delivery_address', 'like', "%{$term}%")
                        ->orWhere('pickup_phone', 'like', "%{$term}%")
                        ->orWhere('delivery_phone', 'like', "%{$term}%")
                        ->orWhere('order_note', 'like', "%{$term}%")
                        ->orWhere('cargo_note', 'like', "%{$term}%")
                        ->orWhere('sender_name', 'like', "%{$term}%")
                        ->orWhere('receiver_name', 'like', "%{$term}%")
                        ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                        ->orWhereHas('sender', fn (Builder $d) => $d->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
                });
            }
        };

        // Chỉ gồm số và ký tự phân cách → SĐT (đầy đủ hoặc vài số cuối) hoặc mã đơn.
        // Một số ngắn (1–3 chữ số) là số nhà và được xử lý bởi $matchTerms.
        if ($digits !== '' && strlen($digits) >= 4 && preg_match('/^[\d\s.+\-]+$/', $s)) {
            $tail = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

            return $query->where(function (Builder $q) use ($digits, $tail, $matchTerms) {
                $q->where(fn (Builder $phone) => $phone
                    ->where('code', 'like', "%{$digits}%")
                    ->orWhere('pickup_phone', 'like', "%{$tail}")
                    ->orWhere('delivery_phone', 'like', "%{$tail}")
                    ->orWhereHas('driver', fn (Builder $d) => $d->where('phone', 'like', "%{$tail}"))
                    ->orWhereHas('sender', fn (Builder $d) => $d->where('phone', 'like', "%{$tail}")))
                    ->orWhere(fn (Builder $text) => $matchTerms($text));
            });
        }

        return $query->where(fn (Builder $q) => $matchTerms($q));
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
                    ->html()
                    ->disabledClick(),

                Tables\Columns\TextColumn::make('customer')
                    ->label('Khách / nguồn')
                    ->state(fn (Order $record): string => self::colCustomer($record))
                    ->html(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Điều phối')
                    ->formatStateUsing(fn ($state, Order $record): string => self::colStatus($record))
                    ->html(),

            ])
            ->recordClasses(fn (Order $record): string => 'fs-ol-row fs-order-row--'.(self::$statusColors[$record->status] ?? 'gray')
                .($record->status === 'pending' && $record->created_at->diffInMinutes(now()) >= 5 ? ' fs-ol-row--urgent' : ''))
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
                        ->visible(fn (Order $record) => AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE) && in_array($record->status, ['cancelled', 'completed']))
                        ->url(fn (Order $record) => self::reorderUrl($record)),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            // Bấm dòng mở ngăn kéo xem nhanh (dòng thời gian, hành trình, tiền); "Mở trang đầy đủ" trong ngăn kéo hoặc menu ⋯.
            ->recordUrl(null)
            ->recordAction(Tables\Actions\ViewAction::class)
            ->bulkActions([])
            ->actionsAlignment('end')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->searchPlaceholder('Tìm mã đơn, SĐT, địa chỉ hoặc tài xế...')
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading('Không có đơn phù hợp')
            ->emptyStateDescription('Thử chọn tab khác hoặc bỏ bớt bộ lọc đang áp dụng.');
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
