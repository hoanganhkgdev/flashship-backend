<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use App\Services\ShiftService;
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
use Modules\Core\Models\Shift;

class ShiftResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Ca làm việc';

    protected static ?int $navigationSort = 7;

    protected static ?string $label = 'Ca làm việc';

    protected static ?string $pluralLabel = 'Ca làm việc';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('users')
            ->withCount([
                'users as active_users_count' => fn (Builder $q) => $q->where('users.status', 1),
                'users as locked_users_count' => fn (Builder $q) => $q->where('users.status', 2),
                'users as online_users_count' => fn (Builder $q) => $q->where('users.status', 1)->where('users.is_online', true),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin ca')
                ->description('Thiết lập khung giờ làm việc và trạng thái đăng ký của ca')
                ->icon('heroicon-o-clock')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên ca')
                        ->required()
                        ->placeholder('VD: Ca sáng'),

                    Forms\Components\TextInput::make('code')
                        ->label('Mã ca')
                        ->required()
                        ->alphaDash()
                        ->helperText('VD: morning — không dấu, không khoảng trắng, duy nhất trong khu vực này')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('city_id', Filament::getTenant()?->id),
                        ),

                    Forms\Components\TimePicker::make('start_time')
                        ->label('Bắt đầu')
                        ->seconds(false)
                        ->required()
                        ->helperText('⚠️ Đổi giờ ca sau khi ca hôm nay đã/đang chạy có thể làm sai lệch % online tính điểm cuối ca — hệ thống chấm điểm luôn dùng giờ ca hiện tại, không lưu lại giờ lúc tài xế vào ca.'),

                    Forms\Components\TimePicker::make('end_time')
                        ->label('Kết thúc')
                        ->seconds(false)
                        ->required()
                        ->rule(fn (Forms\Get $get, ?Shift $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $start = (string) $get('start_time');
                            if ($start === '' || substr($start, 0, 5) === substr((string) $value, 0, 5)) {
                                $start !== '' && $fail('Giờ kết thúc phải khác giờ bắt đầu.');

                                return;
                            }
                            if ($get('is_active') && ($c = ShiftService::conflictingShift((int) Filament::getTenant()?->id, $start, (string) $value, $record?->id))) {
                                $fail('Trùng giờ với "'.$c->name.'" ('.substr($c->start_time, 0, 5).'–'.substr($c->end_time, 0, 5).') đang kích hoạt.');
                            }
                        })
                        ->helperText('Chọn 00:00 nếu ca kết thúc lúc nửa đêm. Cùng lưu ý như giờ bắt đầu — tránh đổi giữa/ngay sau khi ca hôm nay đang chạy.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->live()
                        ->helperText('Tắt ca sẽ ngừng cho tài xế đăng ký mới; các liên kết ca hiện có vẫn được giữ lại.')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên ca')
                    ->searchable()
                    ->description(fn (Shift $r) => 'Mã ca: '.$r->code),

                Tables\Columns\TextColumn::make('schedule')
                    ->label('Khung giờ')
                    ->state(fn (Shift $r) => substr($r->start_time, 0, 5).' → '.substr($r->end_time, 0, 5))
                    ->description(fn (Shift $r) => self::scheduleDescription($r)),

                Tables\Columns\TextColumn::make('active_users_count')
                    ->label('Tài xế hoạt động')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->description(fn (Shift $r) => number_format((int) $r->online_users_count).' online'.($r->locked_users_count ? ' · '.number_format((int) $r->locked_users_count).' đã khóa' : '')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Shift $r) => ! $r->is_active ? 'Đã tắt' : ($r->isNowInShift() ? 'Đang trong ca' : 'Ngoài giờ ca'))
                    ->color(fn (Shift $r) => ! $r->is_active ? 'gray' : ($r->isNowInShift() ? 'success' : 'info')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')->label('Trạng thái')->options([1 => 'Đang kích hoạt', 0 => 'Đã tắt']),
                Tables\Filters\Filter::make('current')->label('Đang trong giờ ca')->query(fn (Builder $query) => self::scopeCurrentShifts($query)),
            ])
            ->defaultSort('start_time')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem tài xế & tải đơn')->icon('heroicon-o-users'),
                    self::editAction(Tables\Actions\Action::make('edit_time')),
                    self::toggleAction(Tables\Actions\Action::make('toggle')),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Shift $r) => (int) $r->users_count === 0)
                        ->modalDescription('Chỉ xóa được ca chưa có tài xế đăng ký.')
                        ->after(fn (Shift $r) => ShiftService::log($r->id, 'deleted', auth()->id(), $r->name)),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (Shift $r): string => static::getUrl('view', ['record' => $r]))
            ->paginated(false);
    }

    /** Sửa ca: bị chặn khi ca đang diễn ra vì điểm cuối ca tính theo giờ hiện tại. */
    public static function editAction($action)
    {
        return $action->label('Sửa ca')->icon('heroicon-o-pencil-square')
            ->url(fn (Shift $r) => static::getUrl('edit', ['record' => $r]))
            ->disabled(fn (Shift $r) => ShiftService::inProgress($r))
            ->tooltip(fn (Shift $r) => ShiftService::inProgress($r) ? 'Ca đang diễn ra, không sửa được' : null);
    }

    public static function toggleAction($action)
    {
        return $action
            ->label(fn (Shift $r) => $r->is_active ? 'Tắt ca' : 'Bật ca')
            ->icon(fn (Shift $r) => $r->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (Shift $r) => $r->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (Shift $r) => ($r->is_active ? 'Tắt ' : 'Bật ').$r->name)
            ->modalDescription(function (Shift $r) {
                if (! $r->is_active) {
                    return 'Ca sẽ được chấm điểm và cho tài xế đăng ký lại.';
                }
                $n = $r->users()->where('users.status', 1)->count();

                return 'Tắt ca sẽ DỪNG chấm điểm cuối ca và ngừng cho tài xế đăng ký mới. Hiện có '.$n.' tài xế hoạt động đang đăng ký ca này.';
            })
            ->action(function (Shift $record): void {
                if ($record->is_active) {
                    $n = ShiftService::deactivate($record, auth()->id());
                    Notification::make()->warning()->title('Đã tắt ca ('.$n.' tài xế hoạt động bị ảnh hưởng)')->send();
                } elseif ($err = ShiftService::activate($record, auth()->id())) {
                    Notification::make()->danger()->title($err)->send();
                } else {
                    Notification::make()->success()->title('Đã bật ca')->send();
                }
            });
    }

    public static function scheduleDescription(Shift $shift): string
    {
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);
        $crossesMidnight = $end->lessThanOrEqualTo($start);
        if ($crossesMidnight) {
            $end->addDay();
        }

        $hours = $start->diffInMinutes($end) / 60;
        $duration = fmod($hours, 1.0) === 0.0 ? number_format($hours, 0) : number_format($hours, 1, ',', '.');

        return $duration.' giờ · '.($crossesMidnight ? 'Qua ngày' : 'Trong ngày');
    }

    public static function scopeCurrentShifts(Builder $query): Builder
    {
        $time = now()->format('H:i:s');

        return $query->where('is_active', true)
            ->where(function (Builder $query) use ($time) {
                $query->where(function (Builder $sameDay) use ($time) {
                    $sameDay->whereColumn('end_time', '>', 'start_time')
                        ->where('start_time', '<=', $time)
                        ->where('end_time', '>=', $time);
                })->orWhere(function (Builder $overnight) use ($time) {
                    $overnight->whereColumn('end_time', '<=', 'start_time')
                        ->where(function (Builder $range) use ($time) {
                            $range->where('start_time', '<=', $time)
                                ->orWhere('end_time', '>=', $time);
                        });
                });
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
            'create' => Pages\CreateShift::route('/create'),
            'view' => Pages\ViewShift::route('/{record}'),
            'edit' => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
