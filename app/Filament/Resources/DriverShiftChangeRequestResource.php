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

    private static function shiftNames(array $ids): string
    {
        return Shift::whereIn('id', $ids)->orderBy('start_time')->pluck('name')->implode(', ');
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
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('shift_ids')
                    ->label('Ca yêu cầu')
                    ->getStateUsing(fn (DriverShiftChangeRequest $record) => static::shiftNames(
                        is_array($record->shift_ids) ? $record->shift_ids : (json_decode((string) $record->shift_ids, true) ?? [])
                    ))
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
                    }),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->default('—')
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('processor.name')
                    ->label('Người duyệt')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày yêu cầu')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),

                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Ngày xử lý')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
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
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DriverShiftChangeRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Duyệt yêu cầu đổi ca')
                    ->modalDescription(fn (DriverShiftChangeRequest $record) => 'Đổi ca của tài xế '.$record->driver?->name.' sang: '.static::shiftNames($record->shift_ids).'?'
                    )
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
