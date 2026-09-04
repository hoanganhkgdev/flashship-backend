<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverShiftChangeRequestResource\Pages;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverShiftChangeRequest;

class DriverShiftChangeRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']) && static::canViewAny();
    }

    // DriverShiftChangeRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverShiftChangeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Ca làm việc';

    protected static ?string $modelLabel = 'Yêu cầu đổi ca';

    protected static ?string $pluralModelLabel = 'Yêu cầu đổi ca';

    protected static ?int $navigationSort = 2;

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
        return parent::getEloquentQuery()->with([
            'driver.city',
            'driver.registeredShifts',
            'processor',
        ]);
    }

    private static function shiftNames(array $ids): string
    {
        return Shift::whereIn('id', $ids)->orderBy('start_time')->pluck('name')->implode(', ');
    }

    private static function requestedShifts(DriverShiftChangeRequest $request): Collection
    {
        $lookup = once(fn () => Shift::query()->get()->keyBy('id'));

        return collect($request->shift_ids ?? [])
            ->map(fn ($id) => $lookup->get((int) $id))
            ->filter()
            ->sortBy('start_time')
            ->values();
    }

    private static function shiftSummary(Collection $shifts): string
    {
        if ($shifts->isEmpty()) {
            return 'Chưa có ca';
        }

        return $shifts->map(fn (Shift $shift) => $shift->name.' ('.substr($shift->start_time, 0, 5).'–'.substr($shift->end_time, 0, 5).')')->implode(', ');
    }

    private static function requestIssue(DriverShiftChangeRequest $request): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $request->shift_ids ?? [])));
        $shifts = self::requestedShifts($request);
        if ($ids === [] || $shifts->count() !== count($ids)) {
            return 'Có ca không còn tồn tại';
        }
        if ($shifts->contains(fn (Shift $shift) => ! $shift->is_active)) {
            return 'Có ca đã tắt';
        }
        if ($shifts->contains(fn (Shift $shift) => $shift->city_id !== $request->driver?->city_id)) {
            return 'Ca không thuộc khu vực tài xế';
        }
        if (self::shiftsOverlap($shifts)) {
            return 'Các ca đề nghị bị trùng giờ';
        }
        if ($request->driver?->is_online
            || self::hasShiftRunningNow($request->driver?->registeredShifts ?? collect())
            || self::hasShiftRunningNow($shifts)) {
            return 'Tài xế đang online hoặc ca đang diễn ra';
        }

        return null;
    }

    private static function shiftsOverlap($shifts): bool
    {
        $segments = function ($shift): array {
            [$sh, $sm] = array_map('intval', explode(':', $shift->start_time));
            [$eh, $em] = array_map('intval', explode(':', $shift->end_time));
            $start = $sh * 60 + $sm;
            $end = $eh * 60 + $em;

            return $end > $start ? [[$start, $end]] : [[$start, 1440], [0, $end]];
        };

        foreach ($shifts->values() as $i => $a) {
            foreach ($shifts->values() as $j => $b) {
                if ($i >= $j) {
                    continue;
                }
                foreach ($segments($a) as $ap) {
                    foreach ($segments($b) as $bp) {
                        if ($ap[0] < $bp[1] && $bp[0] < $ap[1]) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private static function hasShiftRunningNow($shifts): bool
    {
        $minute = now()->hour * 60 + now()->minute;

        return $shifts->contains(function ($shift) use ($minute) {
            [$sh, $sm] = array_map('intval', explode(':', $shift->start_time));
            [$eh, $em] = array_map('intval', explode(':', $shift->end_time));
            $start = $sh * 60 + $sm;
            $end = $eh * 60 + $em;

            return $end > $start
                ? $minute >= $start && $minute < $end
                : $minute >= $start || $minute < $end;
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $driverQuery) => $driverQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (DriverShiftChangeRequest $record) => collect([
                        $record->driver?->phone,
                        $record->driver?->city?->name,
                        $record->driver?->is_online ? 'Đang online' : 'Offline',
                    ])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('current_shifts')
                    ->label('Ca hiện tại')
                    ->state(fn (DriverShiftChangeRequest $record) => self::shiftSummary($record->driver?->registeredShifts ?? collect()))
                    ->wrap(),

                Tables\Columns\TextColumn::make('shift_ids')
                    ->label('Ca yêu cầu')
                    ->getStateUsing(fn (DriverShiftChangeRequest $record) => self::shiftSummary(self::requestedShifts($record)))
                    ->description(fn (DriverShiftChangeRequest $record) => $record->status === 'pending'
                        ? (self::requestIssue($record) ?? 'Có thể duyệt')
                        : null)
                    ->color(fn (DriverShiftChangeRequest $record) => $record->status === 'pending' && self::requestIssue($record) ? 'danger' : 'gray')
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (DriverShiftChangeRequest $record) => $record->processed_at
                        ? collect([$record->processor?->name, $record->processed_at->format('d/m H:i')])->filter()->join(' · ')
                        : 'Chưa xử lý'),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Ghi chú')
                    ->default('Không có ghi chú')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày yêu cầu')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                    ]),
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
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DriverShiftChangeRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Duyệt yêu cầu đổi ca')
                    ->modalDescription(fn (DriverShiftChangeRequest $record) => 'Tài xế '.$record->driver?->name.' đổi từ '.self::shiftSummary($record->driver?->registeredShifts ?? collect()).' sang '.self::shiftSummary(self::requestedShifts($record)).'.')
                    ->action(function (DriverShiftChangeRequest $record) {
                        $result = DB::transaction(function () use ($record) {
                            $locked = DriverShiftChangeRequest::where('id', $record->id)
                                ->lockForUpdate()->firstOrFail();
                            if ($locked->status !== 'pending') {
                                return 'handled';
                            }

                            $driver = $locked->driver()->lockForUpdate()->first();
                            $ids = array_values(array_unique(array_map('intval', $locked->shift_ids ?? [])));
                            $shifts = Shift::whereIn('id', $ids)
                                ->where('is_active', true)
                                ->where('city_id', $driver?->city_id)
                                ->get();
                            if (! $driver || count($ids) === 0 || $shifts->count() !== count($ids)
                                || self::shiftsOverlap($shifts)) {
                                return 'invalid';
                            }

                            $currentShifts = $driver->registeredShifts()->get();
                            if ($driver->is_online || self::hasShiftRunningNow($currentShifts)
                                || self::hasShiftRunningNow($shifts)) {
                                return 'active_shift';
                            }

                            $driver->registeredShifts()->sync($ids);
                            $locked->update([
                                'status' => 'approved',
                                'processed_by' => Auth::id(),
                                'processed_at' => now(),
                            ]);

                            return 'approved';
                        });

                        if ($result !== 'approved') {
                            Notification::make()->danger()->title(
                                match ($result) {
                                    'handled' => 'Yêu cầu đã được người khác xử lý.',
                                    'active_shift' => 'Không thể đổi ca khi tài xế đang online hoặc ca cũ/ca mới đang diễn ra.',
                                    default => 'Ca yêu cầu không còn hợp lệ hoặc bị trùng giờ.',
                                }
                            )->send();

                            return;
                        }
                        Notification::make()->success()->title('Đã duyệt đổi ca.')->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DriverShiftChangeRequest $record) => $record->status === 'pending')
                    ->modalHeading('Từ chối yêu cầu đổi ca')
                    ->modalDescription(fn (DriverShiftChangeRequest $record) => 'Từ chối ca đề nghị của tài xế '.$record->driver?->name.': '.self::shiftSummary(self::requestedShifts($record)).'.')
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Lý do từ chối')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DriverShiftChangeRequest $record, array $data) {
                        $updated = DB::transaction(function () use ($record, $data) {
                            $locked = DriverShiftChangeRequest::where('id', $record->id)
                                ->lockForUpdate()->firstOrFail();
                            if ($locked->status !== 'pending') {
                                return false;
                            }
                            $locked->update([
                                'status' => 'rejected',
                                'admin_note' => $data['admin_note'],
                                'processed_by' => Auth::id(),
                                'processed_at' => now(),
                            ]);

                            return true;
                        });
                        if (! $updated) {
                            Notification::make()->danger()->title('Yêu cầu đã được người khác xử lý.')->send();

                            return;
                        }
                        Notification::make()->success()->title('Đã từ chối yêu cầu đổi ca.')->send();
                    }),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->poll('20s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverShiftChangeRequests::route('/'),
        ];
    }
}
