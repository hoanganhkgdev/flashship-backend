<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverShiftChangeRequestResource\Pages;
use App\Services\ShiftChangeService;
use App\Support\AdminAccess;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverShiftChangeRequest;

class DriverShiftChangeRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    // DriverShiftChangeRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverShiftChangeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Yêu cầu đổi ca';

    protected static ?string $modelLabel = 'Yêu cầu đổi ca';

    protected static ?string $pluralModelLabel = 'Yêu cầu đổi ca';

    protected static ?int $navigationSort = 8;

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), Filament::getTenant())
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.city', 'driver.registeredShifts', 'processor']);
    }

    public static function shiftSummary(Collection $shifts): string
    {
        if ($shifts->isEmpty()) {
            return 'Chưa có ca';
        }

        return $shifts->map(fn (Shift $s) => $s->name.' ('.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5).')')->implode(', ');
    }

    public static function statusLabel(string $status): array
    {
        return ['pending' => ['Chờ duyệt', 'warning'], 'approved' => ['Đã duyệt', 'success'], 'rejected' => ['Từ chối', 'danger']][$status] ?? [$status, 'gray'];
    }

    public static function waitLabel(DriverShiftChangeRequest $r): string
    {
        $h = (int) $r->created_at->diffInHours(now());

        return 'Đã chờ '.($h >= 48 ? intdiv($h, 24).' ngày' : ($h >= 1 ? $h.' giờ' : 'dưới 1 giờ'));
    }

    private static function notify(array $res): void
    {
        Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
    }

    // ─── Thao tác dùng chung cho bảng và trang chi tiết ──────────────────────────

    public static function approveAction($action)
    {
        return $action->label('Duyệt')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (DriverShiftChangeRequest $r) => $r->status === 'pending')
            ->disabled(fn (DriverShiftChangeRequest $r) => ! ShiftChangeService::canApproveNow($r)['ok'])
            ->tooltip(fn (DriverShiftChangeRequest $r) => ShiftChangeService::canApproveNow($r)['ok'] ? null : ShiftChangeService::canApproveNow($r)['reason'])
            ->requiresConfirmation()
            ->modalHeading('Duyệt yêu cầu đổi ca')
            ->modalDescription(function (DriverShiftChangeRequest $r) {
                $impact = ShiftChangeService::impact($r)->map(function ($i) {
                    $line = $i['shift']->name.': '.$i['before'].' → '.$i['after'].' tài xế';
                    if ($i['perBefore'] !== null || $i['perAfter'] !== null) {
                        $line .= ' ('.($i['perBefore'] ?? '—').' → '.($i['perAfter'] ?? '—').' đơn/tài xế/ngày)';
                    }

                    return $line.($i['warn'] ? ' ⚠ '.$i['warn'] : '');
                })->implode('; ');

                return $r->driver?->name.' đổi từ '.self::shiftSummary($r->driver?->registeredShifts ?? collect()).' sang '.self::shiftSummary(ShiftChangeService::requestedShifts($r)).'. Tác động: '.$impact.'.';
            })
            ->action(fn (DriverShiftChangeRequest $record) => self::notify(ShiftChangeService::approve($record, Auth::id())));
    }

    public static function rejectAction($action)
    {
        return $action->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (DriverShiftChangeRequest $r) => $r->status === 'pending')
            ->modalHeading('Từ chối yêu cầu đổi ca')
            ->modalDescription(fn (DriverShiftChangeRequest $r) => 'Lý do được gửi đến tài xế '.$r->driver?->name.'.')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do')->options(ShiftChangeService::REJECT_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Giải thích thêm')->rows(2)->maxLength(300)
                    ->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(5),
            ])
            ->action(fn (DriverShiftChangeRequest $record, array $data) => self::notify(ShiftChangeService::reject($record, $data['reason'], $data['note'] ?? null, Auth::id())));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (DriverShiftChangeRequest $r) => collect([$r->driver?->phone, $r->driver?->is_online ? 'Đang online' : null])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('current_shifts')
                    ->label('Ca hiện tại → Ca đề nghị')
                    ->html()
                    ->state(fn (DriverShiftChangeRequest $r) => e(($r->driver?->registeredShifts ?? collect())->sortBy('start_time')->pluck('name')->implode(', ') ?: 'Chưa có ca')
                        .' <span style="opacity:.6">→</span> <b>'.e(ShiftChangeService::requestedShifts($r)->pluck('name')->implode(', ') ?: '—').'</b>'),

                Tables\Columns\TextColumn::make('flags')
                    ->label('Lưu ý')
                    ->html()
                    ->state(function (DriverShiftChangeRequest $r) {
                        $flags = collect(ShiftChangeService::flags($r))->map(fn ($f) => '<span class="fs-order-pill fs-order-pill--'.$f['level'].'">'.e($f['label']).'</span>');
                        $can = $r->status === 'pending' ? ShiftChangeService::canApproveNow($r) : null;

                        return $flags->implode(' ').($can ? '<br><small>'.e($can['reason']).'</small>' : '');
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::statusLabel($state)[0])
                    ->color(fn ($state) => self::statusLabel($state)[1])
                    ->description(fn (DriverShiftChangeRequest $r) => $r->status === 'pending'
                        ? self::waitLabel($r)
                        : collect([$r->processor?->name, $r->processed_at?->format('d/m H:i')])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Lý do')
                    ->placeholder('—')
                    ->limit(36)
                    ->tooltip(fn (DriverShiftChangeRequest $r) => $r->admin_note),

                Tables\Columns\TextColumn::make('created_at')->label('Gửi lúc')->dateTime('d/m H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối']),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
                    self::approveAction(Tables\Actions\Action::make('approve')),
                    self::rejectAction(Tables\Actions\Action::make('reject')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (DriverShiftChangeRequest $record): string => static::getUrl('view', ['record' => $record]))
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
            'index' => Pages\ListDriverShiftChangeRequests::route('/'),
            'view' => Pages\ViewDriverShiftChangeRequest::route('/{record}'),
        ];
    }
}
