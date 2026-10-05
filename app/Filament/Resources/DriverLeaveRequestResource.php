<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverLeaveRequestResource\Pages;
use App\Services\DriverLeaveService;
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
use Illuminate\Support\HtmlString;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLeaveRequest;

class DriverLeaveRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']) && static::canViewAny();
    }

    // DriverLeaveRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
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
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), Filament::getTenant())
            ->whereDate('leave_date', '>=', today())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.city', 'driver.registeredShifts', 'creator'])
            ->addSelect([
                'recent_leaves' => \Illuminate\Support\Facades\DB::table('driver_leave_requests as l2')->selectRaw('COUNT(*)')
                    ->whereColumn('l2.driver_id', 'driver_leave_requests.driver_id')
                    ->where('l2.leave_date', '>=', today()->subDays(30)->toDateString())->where('l2.leave_date', '<=', today()->toDateString()),
                'worked_before' => \Illuminate\Support\Facades\DB::table('orders as o')->selectRaw('COUNT(*)')
                    ->whereColumn('o.delivery_man_id', 'driver_leave_requests.driver_id')->where('o.status', 'completed')
                    ->whereColumn('o.completed_at', '<=', 'driver_leave_requests.created_at')
                    ->whereRaw('DATE(o.completed_at) = driver_leave_requests.leave_date'),
                'has_shift' => \Illuminate\Support\Facades\DB::table('shift_user as su')->selectRaw('COUNT(*) > 0')
                    ->whereColumn('su.user_id', 'driver_leave_requests.driver_id'),
            ]);
    }

    /** Form ghi nhận nghỉ: một hoặc nhiều ngày, kèm xem trước tác động lên từng ca. */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Ghi nhận nghỉ phép')
                ->description('Tài xế báo nghỉ trước qua điện thoại/Zalo. Trong ngày nghỉ tài xế không bật được online, không nhận đơn và được miễn chấm điểm cuối ca.')
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

                Tables\Columns\TextColumn::make('affected_shifts')
                    ->label('Ca được miễn chấm')
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
                    ->label('Ghi nhận bởi')
                    ->placeholder('Không xác định')
                    ->description(fn (DriverLeaveRequest $r) => $r->created_at?->format('d/m H:i')),
            ])
            ->filters([
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
                    self::cancelAction(Tables\Actions\Action::make('cancel_leave')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (DriverLeaveRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function cancelAction($action)
    {
        return $action->label('Hủy phiếu nghỉ')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (DriverLeaveRequest $r) => ! $r->leave_date->lt(today()))
            ->modalHeading('Hủy phiếu nghỉ')
            ->modalDescription(fn (DriverLeaveRequest $r) => 'Hủy sẽ cho '.$r->driver?->name.' bật online và nhận đơn trở lại'.($r->leave_date->isToday() ? ' ngay hôm nay' : '').', và ngày này bị chấm điểm ca bình thường. Việc hủy được ghi nhật ký.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do hủy')->required()->minLength(3)->maxLength(250)->rows(2)])
            ->action(function (DriverLeaveRequest $record, array $data): void {
                $res = \App\Services\DriverLeaveService::cancel($record, $data['reason'], auth()->id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
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
