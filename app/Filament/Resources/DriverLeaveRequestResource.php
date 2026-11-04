<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverLeaveRequestResource\Pages;
use App\Services\DriverLeaveService;
use App\Support\AdminAccess;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLeaveRequest;

class DriverLeaveRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    // DriverLeaveRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', (int) $tenant?->id));
    }

    protected static ?string $model = DriverLeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Xin nghỉ phép';

    protected static ?string $modelLabel = 'Xin nghỉ phép';

    protected static ?string $pluralModelLabel = 'Xin nghỉ phép';

    protected static ?int $navigationSort = 9;

    public static function getNavigationBadge(): ?string
    {
        // Số yêu cầu tài xế gửi đang chờ duyệt (ngày nghỉ chưa qua).
        $count = static::scopeEloquentQueryToTenant(DriverLeaveRequest::query(), Filament::getTenant())
            ->where('status', DriverLeaveRequest::PENDING)
            ->whereDate('leave_date', '>=', today())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.city', 'driver.registeredShifts', 'creator', 'reviewer'])
            ->addSelect([
                'recent_leaves' => DB::table('driver_leave_requests as l2')->selectRaw('COUNT(*)')
                    ->whereColumn('l2.driver_id', 'driver_leave_requests.driver_id')->where('l2.status', 'approved')
                    ->where('l2.leave_date', '>=', today()->subDays(30)->toDateString())->where('l2.leave_date', '<=', today()->toDateString()),
                'worked_before' => DB::table('orders as o')->selectRaw('COUNT(*)')
                    ->whereColumn('o.delivery_man_id', 'driver_leave_requests.driver_id')->where('o.status', 'completed')
                    ->whereColumn('o.completed_at', '<=', 'driver_leave_requests.created_at')
                    ->whereRaw('DATE(o.completed_at) = driver_leave_requests.leave_date'),
                'has_shift' => DB::table('shift_user as su')->selectRaw('COUNT(*) > 0')
                    ->whereColumn('su.user_id', 'driver_leave_requests.driver_id'),
            ]);
    }

    /** Form ghi nhận nghỉ: một hoặc nhiều ngày, kèm xem trước tác động lên từng ca. */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Ghi nhận nghỉ phép')
                ->description('Admin ghi nhận là có hiệu lực ngay (không cần duyệt); ngày tài xế đã tự xin thì được duyệt luôn. Trong ngày nghỉ tài xế không bật được online, không nhận đơn và không bị tính phạt Offline.')
                ->icon('heroicon-o-calendar-days')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('driver_id')
                        ->label('Tài xế')
                        ->options(fn () => User::where('user_type', 'driver')->where('status', 1)->where('city_id', Filament::getTenant()?->id)
                            ->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->phone]))
                        ->searchable()->required()->live()->columnSpanFull(),

                    Forms\Components\DatePicker::make('from_date')->label('Nghỉ từ ngày')->native(false)->displayFormat('d/m/Y')
                        ->minDate(today())->default(today())->required()->live(),
                    Forms\Components\DatePicker::make('to_date')->label('Đến hết ngày')->native(false)->displayFormat('d/m/Y')
                        ->minDate(fn (Forms\Get $get) => $get('from_date') ?: today())
                        ->maxDate(fn (Forms\Get $get) => $get('from_date') ? Carbon::parse($get('from_date'))->addDays(DriverLeaveService::MAX_DAYS - 1) : null)
                        ->helperText('Để trống nếu chỉ nghỉ một ngày. Tối đa '.DriverLeaveService::MAX_DAYS.' ngày mỗi lần.')->live(),

                    Forms\Components\Textarea::make('note')->label('Lý do')->required()->minLength(3)->maxLength(250)->rows(2)->columnSpanFull(),

                    Forms\Components\Placeholder::make('impact')->label('Tác động lên ca')->columnSpanFull()
                        ->content(function (Forms\Get $get): HtmlString {
                            $driverId = (int) $get('driver_id');
                            if (! $driverId || ! $get('from_date')) {
                                return new HtmlString('<span style="opacity:.7">Chọn tài xế và ngày để xem số tài xế còn lại ở từng ca.</span>');
                            }
                            $from = Carbon::parse($get('from_date'));
                            $to = $get('to_date') ? Carbon::parse($get('to_date')) : $from;
                            $extra = [];
                            for ($d = $from->copy(); $d->lte($to) && $d->diffInDays($from) < DriverLeaveService::MAX_DAYS; $d->addDay()) {
                                $extra[$d->toDateString()] = [$driverId];
                            }
                            $impact = DriverLeaveService::impact((int) Filament::getTenant()?->id, DriverLeaveService::MAX_DAYS, $extra);
                            $lines = collect($impact['rows'])->filter(fn ($r) => isset($extra[$r['date']->toDateString()]))->map(function ($r) {
                                $cells = $r['cells']->map(fn ($c) => e($c['shift']->name).': '.$c['remaining'].'/'.$c['registered'].($c['bad'] ? ' ⚠' : ''))->implode(' · ');

                                return '<div><b>'.$r['date']->format('d/m').'</b> — '.$cells.'</div>';
                            })->implode('');

                            return new HtmlString($lines ?: '<span style="opacity:.7">Ngày chọn nằm ngoài khoảng xem trước.</span>');
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (DriverLeaveRequest $r) => collect([$r->driver?->phone, (int) $r->driver?->status === 2 ? 'Đã khóa' : null])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('leave_date')
                    ->label('Ngày nghỉ')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (DriverLeaveRequest $r) => self::dateDescription($r->leave_date))
                    ->color(fn (DriverLeaveRequest $r) => $r->leave_date->isToday() ? 'warning' : ($r->leave_date->isFuture() ? 'info' : 'gray')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (DriverLeaveRequest $r) => $r->statusLabel())
                    ->color(fn (DriverLeaveRequest $r) => $r->statusColor())
                    ->description(fn (DriverLeaveRequest $r) => $r->isRejected()
                        ? \Illuminate\Support\Str::limit((string) $r->reject_reason, 28)
                        : ($r->reviewed_at && $r->reviewer && $r->reviewed_by !== $r->created_by ? $r->reviewer->name.' · '.$r->reviewed_at->format('d/m H:i') : null)),

                Tables\Columns\TextColumn::make('affected_shifts')
                    ->label('Ca được miễn phạt Offline')
                    ->state(fn (DriverLeaveRequest $r) => $r->driver?->registeredShifts?->sortBy('start_time')->pluck('name')->implode(', ') ?: 'Chưa đăng ký ca'),

                Tables\Columns\TextColumn::make('flags')
                    ->label('Lưu ý')
                    ->html()
                    ->wrap()
                    ->extraAttributes(['style' => 'white-space:normal;max-width:15rem'])
                    ->state(fn (DriverLeaveRequest $r) => collect(DriverLeaveService::flags($r))
                        ->map(fn ($f) => '<span class="fs-order-pill fs-order-pill--'.$f['level'].'">'.e($f['label']).'</span>')->implode(' ') ?: '—'),

                Tables\Columns\TextColumn::make('note')->label('Lý do')->limit(22)->tooltip(fn (DriverLeaveRequest $r) => $r->note)->placeholder('—'),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Gửi / ghi nhận bởi')
                    ->placeholder('Không xác định')
                    ->description(fn (DriverLeaveRequest $r) => $r->created_at?->format('d/m H:i')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options([
                    DriverLeaveRequest::PENDING => 'Chờ duyệt',
                    DriverLeaveRequest::APPROVED => 'Đã duyệt',
                    DriverLeaveRequest::REJECTED => 'Từ chối',
                ]),
                Tables\Filters\Filter::make('leave_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Nghỉ từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Nghỉ đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('leave_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('leave_date', '<=', $date))),
            ])
            ->defaultSort('leave_date', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
                    self::approveAction(Tables\Actions\Action::make('approve_leave')),
                    self::rejectAction(Tables\Actions\Action::make('reject_leave')),
                    self::cancelAction(Tables\Actions\Action::make('cancel_leave')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve_selected')->label('Duyệt các yêu cầu đã chọn')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Chỉ duyệt các yêu cầu đang chờ và chưa qua ngày. Duyệt nghỉ hôm nay sẽ tắt online tài xế ngay.')
                    ->action(fn (\Illuminate\Support\Collection $records) => self::notifyBulk(
                        $records->map(fn (DriverLeaveRequest $r) => DriverLeaveService::approve($r, auth()->id())), 'duyệt'))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('reject_selected')->label('Từ chối các yêu cầu đã chọn')->icon('heroicon-o-no-symbol')->color('danger')
                    ->form([Forms\Components\Textarea::make('reason')->label('Lý do từ chối (gửi cho tài xế)')->required()->minLength(3)->maxLength(250)->rows(2)])
                    ->action(fn (\Illuminate\Support\Collection $records, array $data) => self::notifyBulk(
                        $records->map(fn (DriverLeaveRequest $r) => DriverLeaveService::reject($r, $data['reason'], auth()->id())), 'từ chối'))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordClasses(fn (DriverLeaveRequest $r) => match (true) {
                $r->isPending() && $r->leave_date->lt(today()) => 'fs-lv-row--expired',
                $r->isPending() => 'fs-lv-row--pending',
                $r->isApproved() && $r->leave_date->isToday() => 'fs-lv-row--today',
                $r->leave_date->lt(today()) => 'fs-lv-row--past',
                default => null,
            })
            ->recordUrl(fn (DriverLeaveRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function cancelAction($action)
    {
        return $action->label(fn (DriverLeaveRequest $r) => $r->isPending() ? 'Hủy yêu cầu' : 'Hủy phiếu nghỉ')->icon('heroicon-o-x-circle')->color('gray')
            ->visible(fn (DriverLeaveRequest $r) => ! $r->leave_date->lt(today()) && ! $r->isRejected())
            ->modalHeading('Hủy phiếu nghỉ')
            ->modalDescription(fn (DriverLeaveRequest $r) => 'Hủy sẽ cho '.$r->driver?->name.' bật online và nhận đơn trở lại'.($r->leave_date->isToday() ? ' ngay hôm nay. Phạt Offline chỉ tính từ lúc hủy, không tính khoảng đã nghỉ' : '').'. Việc hủy được ghi nhật ký.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do hủy')->required()->minLength(3)->maxLength(250)->rows(2)])
            ->action(function (DriverLeaveRequest $record, array $data): void {
                $res = DriverLeaveService::cancel($record, $data['reason'], auth()->id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    public static function approveAction($action)
    {
        return $action->label('Duyệt nghỉ')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (DriverLeaveRequest $r) => $r->isPending() && ! $r->leave_date->lt(today()))
            ->requiresConfirmation()
            ->modalHeading(fn (DriverLeaveRequest $r) => 'Duyệt nghỉ ngày '.$r->leave_date->format('d/m/Y'))
            ->modalDescription(fn (DriverLeaveRequest $r) => $r->driver?->name.' sẽ không bật được online, không nhận đơn và không bị tính phạt Offline trong ngày này'.($r->leave_date->isToday() ? ' — tắt online ngay bây giờ' : '').'.')
            ->modalSubmitActionLabel('Duyệt')
            ->action(function (DriverLeaveRequest $record): void {
                $res = DriverLeaveService::approve($record, auth()->id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    public static function rejectAction($action)
    {
        return $action->label('Từ chối')->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn (DriverLeaveRequest $r) => $r->isPending())
            ->modalHeading(fn (DriverLeaveRequest $r) => 'Từ chối nghỉ ngày '.$r->leave_date->format('d/m/Y'))
            ->modalDescription(fn (DriverLeaveRequest $r) => $r->driver?->name.' vẫn phải đi làm theo ca đã đăng ký. Lý do được gửi thông báo cho tài xế.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do từ chối')->required()->minLength(3)->maxLength(250)->rows(2)])
            ->modalSubmitActionLabel('Từ chối')
            ->action(function (DriverLeaveRequest $record, array $data): void {
                $res = DriverLeaveService::reject($record, $data['reason'], auth()->id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    /** @param \Illuminate\Support\Collection<int, array{ok:bool,message:string}> $results */
    private static function notifyBulk(\Illuminate\Support\Collection $results, string $verb): void
    {
        $done = $results->where('ok', true)->count();
        $failed = $results->where('ok', false);
        Notification::make()
            ->title('Đã '.$verb.' '.$done.'/'.$results->count().' yêu cầu')
            ->body($failed->isNotEmpty() ? 'Bỏ qua: '.$failed->pluck('message')->unique()->implode(' · ') : null)
            ->{$failed->isEmpty() ? 'success' : 'warning'}()
            ->send();
    }

    public static function dateDescription(Carbon $date): string
    {
        if ($date->isToday()) {
            return 'Hôm nay';
        }
        if ($date->isTomorrow()) {
            return 'Ngày mai';
        }
        if ($date->isYesterday()) {
            return 'Hôm qua';
        }
        $days = (int) $date->copy()->startOfDay()->diffInDays(today(), true);

        return $date->isFuture() ? 'Còn '.$days.' ngày' : 'Đã qua '.$days.' ngày';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverLeaveRequests::route('/'),
            'create' => Pages\CreateDriverLeaveRequest::route('/create'),
            'view' => Pages\ViewDriverLeaveRequest::route('/{record}'),
        ];
    }
}
