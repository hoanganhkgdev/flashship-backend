<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverScoreResource\Pages;
use App\Filament\Resources\DriverScoreResource\RelationManagers;
use App\Filament\Traits\RestrictToFullAdmin;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverScoreService;

class DriverScoreResource extends Resource
{
    use RestrictToFullAdmin;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'Người dùng & đối tác';

    protected static ?string $modelLabel = 'Điểm tài xế';

    protected static ?string $pluralModelLabel = 'Điểm tài xế';

    protected static ?string $slug = 'driver-scores';

    protected static ?int $navigationSort = 4;

    // Badge: số settlement pending tuần này chưa xử lý
    public static function getNavigationBadge(): ?string
    {
        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $count = DB::table('driver_score_settlements')
            ->where('week_start', $weekStart)
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $weekStart = Carbon::now()->startOfWeek()->toDateString();

        return parent::getEloquentQuery()
            ->where('user_type', 'driver')
            ->with(['city', 'latestScoreLog'])
            ->withCount([
                'scoreLogs as score_changes_today_count' => fn (Builder $query) => $query
                    ->whereDate('created_at', now()->toDateString())
                    ->where('delta', '<>', 0),
            ])
            ->addSelect([
                'weekly_settlement_type' => DB::table('driver_score_settlements')
                    ->select('type')
                    ->whereColumn('driver_id', 'users.id')
                    ->where('week_start', $weekStart)
                    ->limit(1),
                'weekly_settlement_amount' => DB::table('driver_score_settlements')
                    ->select('amount')
                    ->whereColumn('driver_id', 'users.id')
                    ->where('week_start', $weekStart)
                    ->limit(1),
                'weekly_settlement_status' => DB::table('driver_score_settlements')
                    ->select('status')
                    ->whereColumn('driver_id', 'users.id')
                    ->where('week_start', $weekStart)
                    ->limit(1),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // ─── Infolist (trang chi tiết) ───────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tài xế')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Tên')->weight('bold'),
                    Infolists\Components\TextEntry::make('phone')->label('Số điện thoại')->default('—'),
                    Infolists\Components\TextEntry::make('city.name')->label('Khu vực')->default('—'),
                ]),

            Infolists\Components\Section::make('Điểm hiệu suất')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('driver_score')
                        ->label('Điểm hiện tại')
                        ->formatStateUsing(fn ($state) => ($state ?? DriverScoreService::DEFAULT_SCORE).' / '.DriverScoreService::MAX_SCORE)
                        ->weight('bold')
                        ->size('lg')
                        ->color(fn ($state) => self::scoreColor($state ?? DriverScoreService::DEFAULT_SCORE)),

                    Infolists\Components\TextEntry::make('score_label')
                        ->label('Xếp loại')
                        ->state(fn (User $r) => DriverScoreService::label($r->driver_score ?? DriverScoreService::DEFAULT_SCORE))
                        ->badge()
                        ->color(fn (User $r) => self::scoreColor($r->driver_score ?? DriverScoreService::DEFAULT_SCORE)),

                    Infolists\Components\TextEntry::make('consecutive_completed')
                        ->label('Streak')
                        ->formatStateUsing(fn ($state) => ($state ?? 0).' đơn liên tiếp')
                        ->default('0 đơn liên tiếp'),

                    Infolists\Components\TextEntry::make('daily_bonus_points')
                        ->label('Thưởng bonus hôm nay')
                        ->state(fn (User $r) => self::bonusTodayLabel($r))
                        ->color(fn (User $r) => ($r->daily_bonus_date === now()->toDateString() && ($r->daily_bonus_points ?? 0) >= DriverScoreService::DAILY_BONUS_CAP) ? 'warning' : 'gray'),
                ]),

            Infolists\Components\Section::make('Tuần hiện tại')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('week_start')
                        ->label('Tuần bắt đầu')
                        ->state(Carbon::now()->startOfWeek()->format('d/m/Y').' → '.Carbon::now()->endOfWeek()->format('d/m/Y')),

                    Infolists\Components\TextEntry::make('weekly_settlement_status')
                        ->label('Chốt điểm')
                        ->state(function (User $r) {
                            if (! $r->weekly_settlement_type) {
                                return 'Chưa chốt';
                            }

                            return match ($r->weekly_settlement_type) {
                                'bonus' => 'Thưởng '.number_format((int) $r->weekly_settlement_amount).'₫',
                                'penalty' => 'Phạt '.number_format((int) $r->weekly_settlement_amount).'₫',
                                default => $r->weekly_settlement_type,
                            };
                        })
                        ->badge()
                        ->color(fn (User $r) => match ($r->weekly_settlement_type) {
                                'bonus' => 'success',
                                'penalty' => 'danger',
                                default => 'gray',
                            }),

                    Infolists\Components\TextEntry::make('weekly_settlement_process')
                        ->label('Trạng thái thanh toán')
                        ->state(fn (User $r) => match ($r->weekly_settlement_status) {
                                'pending' => 'Chờ xử lý',
                                'processed' => 'Đã xử lý',
                                default => '—',
                            })
                        ->badge()
                        ->color(fn (User $r) => match ($r->weekly_settlement_status) {
                            'pending' => 'warning',
                            'processed' => 'success',
                            default => 'gray',
                        }),
                ]),
        ]);
    }

    // ─── Table (danh sách) ───────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()->label('#')->alignCenter()->width(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tài xế')
                    ->searchable()
                    ->description(fn (User $r) => collect([$r->phone, $r->city?->name])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state ?? DriverScoreService::DEFAULT_SCORE).' / '.DriverScoreService::MAX_SCORE)
                    ->color(fn ($state) => self::scoreColor($state ?? DriverScoreService::DEFAULT_SCORE))
                    ->description(fn (User $r) => DriverScoreService::label($r->driver_score ?? DriverScoreService::DEFAULT_SCORE))
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestScoreLog.reason')
                    ->label('Biến động gần nhất')
                    ->formatStateUsing(fn ($state) => $state ? self::reasonLabel($state) : 'Chưa có biến động')
                    ->color(fn ($state) => $state ? self::reasonColor($state) : 'gray')
                    ->description(function (User $r) {
                        $log = $r->latestScoreLog;
                        if (! $log) {
                            return null;
                        }

                        $delta = $log->delta > 0 ? "+{$log->delta}" : (string) $log->delta;

                        return "{$delta} điểm · {$log->score_before} → {$log->score_after} · ".$log->created_at?->format('d/m H:i');
                    }),

                Tables\Columns\TextColumn::make('consecutive_completed')
                    ->label('Hôm nay')
                    ->formatStateUsing(fn ($state) => ($state ?? 0).' đơn liên tiếp')
                    ->description(fn (User $r) => ($r->score_changes_today_count ?? 0).' lần đổi điểm · '.self::bonusTodayLabel($r)),

                Tables\Columns\TextColumn::make('weekly_settlement_type')
                    ->label('Tuần này')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'bonus' => 'Được thưởng',
                        'penalty' => 'Bị phạt',
                        default => 'Chưa chốt',
                    })
                    ->description(fn (User $r) => $r->weekly_settlement_type
                        ? number_format((int) $r->weekly_settlement_amount).'₫ · '.match ($r->weekly_settlement_status) {
                            'pending' => 'Chờ xử lý',
                            'processed' => 'Đã xử lý',
                            default => 'Chưa xác định',
                        }
                        : Carbon::now()->startOfWeek()->format('d/m').' → '.Carbon::now()->endOfWeek()->format('d/m'))
                    ->color(fn ($state) => match ($state) {
                        'bonus' => 'success',
                        'penalty' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('Khu vực')
                    ->relationship('city', 'name'),

                SelectFilter::make('score_range')
                    ->label('Xếp loại')
                    ->options([
                        'excellent' => 'Xuất sắc (140)',
                        'good' => 'Tốt (110–139)',
                        'average' => 'Khá (90–109)',
                        'below' => 'Trung bình (70–89)',
                        'poor' => 'Cần cải thiện (<70)',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'excellent' => $q->where('driver_score', '>=', DriverScoreService::WEEKLY_BONUS_SCORE),
                        'good' => $q->whereBetween('driver_score', [110, DriverScoreService::WEEKLY_BONUS_SCORE - 1]),
                        'average' => $q->whereBetween('driver_score', [90, 109]),
                        'below' => $q->whereBetween('driver_score', [70, 89]),
                        'poor' => $q->where('driver_score', '<', 70),
                        default => $q,
                    }),

                SelectFilter::make('weekly_settlement')
                    ->label('Chốt điểm tuần')
                    ->options([
                        'bonus' => 'Được thưởng 50k',
                        'penalty' => 'Bị phạt 50k',
                        'none' => 'Chưa chốt',
                    ])
                    ->query(function (Builder $q, array $data) {
                        $weekStart = Carbon::now()->startOfWeek()->toDateString();

                        return match ($data['value'] ?? null) {
                            'bonus' => $q->whereExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)->where('type', 'bonus')),
                            'penalty' => $q->whereExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)->where('type', 'penalty')),
                            'none' => $q->whereNotExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)),
                            default => $q,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),

                Tables\Actions\Action::make('reset_score')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->tooltip('Reset điểm về '.DriverScoreService::DEFAULT_SCORE)
                    ->requiresConfirmation()
                    ->modalHeading('Reset điểm tài xế')
                    ->modalDescription(fn (User $r) => 'Reset điểm tài xế '.$r->name.' về '.DriverScoreService::DEFAULT_SCORE.'?')
                    ->action(function (User $record) {
                        DriverScoreService::resetToDefault($record->id);
                        Notification::make()->success()
                            ->title('Đã reset điểm về '.DriverScoreService::DEFAULT_SCORE)
                            ->send();
                    }),
            ])
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('driver_score', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    public static function scoreColor(int $score): string
    {
        return match (true) {
            $score >= DriverScoreService::WEEKLY_BONUS_SCORE => 'success',
            $score >= 110 => 'info',
            $score >= 90 => 'primary',
            $score >= 70 => 'gray',
            default => 'danger',
        };
    }

    private static function bonusTodayLabel(User $r): string
    {
        $today = now()->toDateString();
        $points = ($r->daily_bonus_date === $today) ? ($r->daily_bonus_points ?? 0) : 0;

        return "+{$points} / ".DriverScoreService::DAILY_BONUS_CAP.' hôm nay';
    }

    public static function reasonLabel(string $reason): string
    {
        return match (true) {
            $reason === 'complete' => 'Hoàn thành đơn',
            $reason === 'decline' => 'Từ chối đơn',
            $reason === 'viewed_timeout' => 'Xem đơn nhưng không nhận',
            $reason === 'offer_unviewed_x3' => 'Không xem 3 đơn liên tiếp',
            str_starts_with($reason, 'streak_') => 'Thưởng chuỗi '.str_replace('streak_', '', $reason).' đơn',
            $reason === 'shift_online_normal' => 'Online đủ ca (85–100%)',
            $reason === 'shift_online_reduced' => 'Online 70–84% ca',
            $reason === 'shift_online_mid' => 'Online 60–69% ca',
            $reason === 'shift_online_low' => 'Online 50–59% ca',
            $reason === 'shift_online_critical' => 'Online dưới 50% ca',
            $reason === 'shift_never_online' => 'Không online trong ca',
            $reason === 'shift_online_high' => 'Online từ 90% ca',
            $reason === 'shift_online_neutral' => 'Online 70–90% ca',
            str_starts_with($reason, 'inactivity_') => 'Không hoạt động',
            $reason === 'online_below_8h' => 'Online dưới 8 giờ',
            $reason === 'weekly_reset' => 'Đặt lại điểm đầu tuần',
            str_starts_with($reason, 'manual_refund_shift_score_') => 'Hoàn điểm chấm ca sai (#'.str_replace('manual_refund_shift_score_', '', $reason).')',
            str_starts_with($reason, 'cap_blocked:') => 'Đã đạt trần thưởng ngày',
            str_starts_with($reason, 'rated_') => 'Đánh giá '.str_replace(['rated_', '_stars'], '', $reason).' sao',
            default => $reason,
        };
    }

    public static function reasonColor(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'manual_refund_'), str_starts_with($reason, 'streak_'), $reason === 'rated_5_stars' => 'success',
            in_array($reason, ['decline', 'viewed_timeout', 'shift_online_low', 'shift_online_critical', 'shift_never_online'], true),
            str_starts_with($reason, 'inactivity_') => 'danger',
            in_array($reason, ['offer_unviewed_x3', 'shift_online_reduced', 'shift_online_mid', 'online_below_8h'], true) => 'warning',
            default => 'gray',
        };
    }

    // ─── Relations & Pages ────────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [
            RelationManagers\ScoreLogsRelationManager::class,
            RelationManagers\ScoreSettlementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverScores::route('/'),
            'view' => Pages\ViewDriverScore::route('/{record}'),
        ];
    }
}
