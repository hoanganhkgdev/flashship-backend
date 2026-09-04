<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Shift;

class ShiftResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']);
    }

    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Ca làm việc';

    protected static ?int $navigationSort = 1;

    protected static ?string $label = 'Ca làm việc';

    protected static ?string $pluralLabel = 'Ca làm việc';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('users')
            ->withCount([
                'users as online_users_count' => fn (Builder $query) => $query->where('is_online', true),
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
                        ->helperText('Chọn 00:00 nếu ca kết thúc lúc nửa đêm. Cùng lưu ý như giờ bắt đầu — tránh đổi giữa/ngay sau khi ca hôm nay đang chạy.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Kích hoạt')
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
                    ->description(fn (Shift $record) => 'Mã ca: '.$record->code),

                Tables\Columns\TextColumn::make('schedule')
                    ->label('Khung giờ')
                    ->state(fn (Shift $record) => substr($record->start_time, 0, 5).' → '.substr($record->end_time, 0, 5))
                    ->description(fn (Shift $record) => self::scheduleDescription($record)),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Tài xế đăng ký')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' tài xế')
                    ->description(fn (Shift $record) => number_format((int) $record->online_users_count).' đang online'),

                Tables\Columns\TextColumn::make('current_status')
                    ->label('Thời điểm hiện tại')
                    ->state(fn (Shift $record) => $record->is_active && $record->isNowInShift() ? 'Đang trong ca' : 'Ngoài giờ ca')
                    ->color(fn (Shift $record) => $record->is_active && $record->isNowInShift() ? 'success' : 'gray')
                    ->description(fn (Shift $record) => $record->is_active ? 'Ca đang được sử dụng' : 'Ca đã tắt'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Trạng thái')
                    ->options([1 => 'Đang kích hoạt', 0 => 'Đã tắt']),
                Tables\Filters\Filter::make('current')
                    ->label('Đang trong giờ ca')
                    ->query(fn (Builder $query) => self::scopeCurrentShifts($query)),
            ])
            ->defaultSort('start_time')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa ca'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Xóa ca')
                    ->visible(fn (Shift $record) => (int) $record->users_count === 0)
                    ->modalDescription('Chỉ nên xóa ca chưa có tài xế đăng ký.'),
            ])
            ->recordAction('edit')
            ->paginated(false);
    }

    public static function scheduleDescription(Shift $shift): string
    {
        $start = \Carbon\Carbon::parse($shift->start_time);
        $end = \Carbon\Carbon::parse($shift->end_time);
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
            'edit' => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
