<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverLeaveRequestResource\Pages;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    protected static ?string $navigationGroup = 'Ca làm việc';

    protected static ?string $modelLabel = 'Xin nghỉ phép';

    protected static ?string $pluralModelLabel = 'Xin nghỉ phép';

    protected static ?int $navigationSort = 3;

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
        return parent::getEloquentQuery()->with(['driver.city', 'driver.registeredShifts', 'creator']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Ghi nhận nghỉ phép')
                ->description('Tài xế báo nghỉ trước qua điện thoại/Zalo — admin tạo bản ghi này để miễn chấm điểm "Có mặt" cho ngày nghỉ, không bị tính -15 điểm vì không online.')
                ->icon('heroicon-o-calendar-days')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('driver_id')
                        ->label('Tài xế')
                        ->options(fn () => User::where('user_type', 'driver')
                            ->where('status', 1)
                            ->where('city_id', Filament::getTenant()?->id)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->phone]))
                        ->searchable()
                        ->required(),

                    Forms\Components\DatePicker::make('leave_date')
                        ->label('Ngày nghỉ')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->minDate(fn (?DriverLeaveRequest $record) => $record?->leave_date?->isPast() ? $record->leave_date : today())
                        ->required(),

                    Forms\Components\Textarea::make('note')
                        ->label('Ghi chú')
                        ->rows(2)
                        ->columnSpanFull(),
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
                        ->whereHas('driver', fn (Builder $driverQuery) => $driverQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (DriverLeaveRequest $record) => collect([
                        $record->driver?->phone,
                        $record->driver?->city?->name,
                    ])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('leave_date')
                    ->label('Ngày nghỉ')
                    ->date('d/m/Y')
                    ->description(fn (DriverLeaveRequest $record) => self::dateDescription($record->leave_date))
                    ->color(fn (DriverLeaveRequest $record) => $record->leave_date->isToday() ? 'warning' : ($record->leave_date->isFuture() ? 'info' : 'gray')),

                Tables\Columns\TextColumn::make('affected_shifts')
                    ->label('Ca được miễn chấm')
                    ->state(fn (DriverLeaveRequest $record) => $record->driver?->registeredShifts
                        ?->sortBy('start_time')
                        ->map(fn ($shift) => $shift->name.' ('.substr($shift->start_time, 0, 5).'–'.substr($shift->end_time, 0, 5).')')
                        ->implode(', ') ?: 'Chưa đăng ký ca')
                    ->wrap(),

                Tables\Columns\TextColumn::make('leave_status')
                    ->label('Hiệu lực')
                    ->state(fn (DriverLeaveRequest $record) => match (true) {
                        $record->leave_date->isToday() => 'Đang nghỉ',
                        $record->leave_date->isFuture() => 'Sắp nghỉ',
                        default => 'Đã kết thúc',
                    })
                    ->color(fn (DriverLeaveRequest $record) => match (true) {
                        $record->leave_date->isToday() => 'warning',
                        $record->leave_date->isFuture() => 'info',
                        default => 'gray',
                    })
                    ->description(fn (DriverLeaveRequest $record) => $record->leave_date->isPast() && ! $record->leave_date->isToday()
                        ? 'Ngày nghỉ đã qua'
                        : 'Miễn chấm điểm ca'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Ghi chú')
                    ->default('Không có ghi chú')
                    ->wrap(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Ghi nhận bởi')
                    ->default('Không xác định')
                    ->description(fn (DriverLeaveRequest $record) => $record->created_at?->format('d/m/Y H:i')),
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
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa lịch nghỉ'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Xóa lịch nghỉ')
                    ->modalDescription('Xóa lịch nghỉ có thể khiến tài xế bị chấm điểm ca hoặc được phép bật online trở lại trong ngày này.'),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
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

        return $date->isFuture() ? 'Còn '.(int) $date->diffInDays(today()).' ngày' : 'Đã qua '.(int) $date->diffInDays(today()).' ngày';
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
            'edit' => Pages\EditDriverLeaveRequest::route('/{record}/edit'),
        ];
    }
}
