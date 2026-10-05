<?php

namespace App\Filament\Resources;

use Filament\Facades\Filament;
use App\Filament\Resources\DriverScoreResource\Pages;
use App\Filament\Resources\DriverScoreResource\RelationManagers;
use App\Filament\Traits\RestrictToFullAdmin;
use App\Services\DriverScoreReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverScoreService;

class DriverScoreResource extends Resource
{
    use RestrictToFullAdmin;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Điểm tài xế';

    protected static ?string $modelLabel = 'Điểm tài xế';

    protected static ?string $pluralModelLabel = 'Điểm tài xế';

    protected static ?string $slug = 'driver-scores';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $since = now()->subDays(7)->toDateTimeString();
        $resets = "'".implode("','", DriverScoreReport::RESET_REASONS)."'";

        return parent::getEloquentQuery()
            ->where('user_type', 'driver')
            ->with(['city', 'latestScoreLog'])
            ->addSelect([
                // Điểm thực tăng/giảm 7 ngày qua, không tính lần đặt lại điểm.
                'week_change' => DB::table('driver_score_logs')
                    ->selectRaw('COALESCE(SUM('.DriverScoreReport::REAL_CHANGE.'), 0)')
                    ->whereColumn('driver_id', 'users.id')
                    ->where('created_at', '>=', $since)
                    ->whereRaw("reason NOT IN ({$resets})"),
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

    // ─── Table (danh sách) ───────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tài xế')
                    ->searchable(['name', 'phone'])
                    ->description(fn (User $r) => collect([$r->phone, (int) $r->status === 2 ? 'Bị khóa' : ((int) $r->status === 0 ? 'Chờ duyệt' : null)])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm')
                    ->html()
                    ->state(function (User $r) {
                        $score = (int) ($r->driver_score ?? DriverScoreService::DEFAULT_SCORE);
                        $max = max(1, DriverScoreService::maxScore($r->city_id));
                        $pct = max(0, min(100, round($score / $max * 100)));
                        $color = self::BAR_COLORS[self::scoreColor($score, $r->city_id)] ?? '#94a3b8';

                        return '<b>'.$score.'</b> <small>/ '.$max.'</small><span class="fs-sc-meter" style="--bar:'.$color.'"><i style="width:'.$pct.'%"></i></span>';
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('rank')
                    ->label('Xếp loại')
                    ->badge()
                    ->state(fn (User $r) => DriverScoreService::label((int) ($r->driver_score ?? DriverScoreService::DEFAULT_SCORE), $r->city_id))
                    ->color(fn (User $r) => self::scoreColor((int) ($r->driver_score ?? DriverScoreService::DEFAULT_SCORE), $r->city_id)),

                Tables\Columns\TextColumn::make('week_change')
                    ->label('7 ngày')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ((int) $state > 0 ? '+' : '').(int) $state)
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : ((int) $state < 0 ? 'danger' : 'gray'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('consecutive_completed')
                    ->label('Chuỗi đơn')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => (int) ($state ?? 0).' đơn')
                    ->description(fn (User $r) => self::bonusTodayLabel($r)),

                Tables\Columns\TextColumn::make('latestScoreLog.reason')
                    ->label('Biến động gần nhất')
                    ->formatStateUsing(fn ($state, User $record) => $state ? self::reasonLabel($state, $record->city_id) : 'Chưa có biến động')
                    ->color(fn ($state) => $state ? self::reasonColor($state) : 'gray')
                    ->description(function (User $r) {
                        $log = $r->latestScoreLog;
                        if (! $log) {
                            return null;
                        }

                        $delta = $log->delta > 0 ? "+{$log->delta}" : (string) $log->delta;

                        return "{$delta} điểm · {$log->score_before} → {$log->score_after} · ".$log->created_at?->format('d/m H:i');
                    }),

                Tables\Columns\TextColumn::make('projected')
                    ->label('Dự kiến chốt tuần')
                    ->badge()
                    ->state(function (User $r) {
                        if ((int) $r->status !== 1) {
                            return 'Không tính';
                        }
                        $outcome = DriverScoreReport::projectedOutcome((int) ($r->driver_score ?? DriverScoreService::DEFAULT_SCORE), $r->city_id);

                        return match ($outcome) {
                            'bonus' => 'Thưởng '.number_format(DriverScoreService::weeklyBonusAmount($r->city_id), 0, ',', '.').'₫',
                            'penalty' => 'Phạt '.number_format(DriverScoreService::weeklyPenaltyAmount($r->city_id), 0, ',', '.').'₫',
                            default => 'Không thưởng/phạt',
                        };
                    })
                    ->color(fn (string $state) => str_starts_with($state, 'Thưởng') ? 'success' : (str_starts_with($state, 'Phạt') ? 'danger' : 'gray')),
            ])
            ->filters([
                SelectFilter::make('score_range')
                    ->label('Xếp loại')
                    ->options(fn () => [
                        'excellent' => 'Xuất sắc (≥'.DriverScoreService::weeklyBonusScore(self::tenantCityId()).')',
                        'good' => 'Tốt ('.DriverScoreService::GOOD_SCORE.'–'.(DriverScoreService::weeklyBonusScore(self::tenantCityId()) - 1).')',
                        'average' => 'Khá ('.DriverScoreService::FAIR_SCORE.'–'.(DriverScoreService::GOOD_SCORE - 1).')',
                        'below' => 'Trung bình ('.(DriverScoreService::weeklyPenaltyScore(self::tenantCityId()) + 1).'–'.(DriverScoreService::FAIR_SCORE - 1).')',
                        'poor' => 'Cần cải thiện (≤'.DriverScoreService::weeklyPenaltyScore(self::tenantCityId()).')',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'excellent' => $q->where('driver_score', '>=', DriverScoreService::weeklyBonusScore(self::tenantCityId())),
                        'good' => $q->whereBetween('driver_score', [DriverScoreService::GOOD_SCORE, DriverScoreService::weeklyBonusScore(self::tenantCityId()) - 1]),
                        'average' => $q->whereBetween('driver_score', [DriverScoreService::FAIR_SCORE, DriverScoreService::GOOD_SCORE - 1]),
                        'below' => $q->whereBetween('driver_score', [DriverScoreService::weeklyPenaltyScore(self::tenantCityId()) + 1, DriverScoreService::FAIR_SCORE - 1]),
                        'poor' => $q->where('driver_score', '<=', DriverScoreService::weeklyPenaltyScore(self::tenantCityId())),
                        default => $q,
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem điểm')->icon('heroicon-o-eye'),
                    self::resetAction(Tables\Actions\Action::make('reset_score')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('driver_score', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    /** Đặt lại điểm: bắt buộc nêu lý do, được ghi vào nhật ký điểm cùng người thực hiện. */
    public static function resetAction($action)
    {
        return $action
            ->label('Đặt lại điểm')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading('Đặt lại điểm về '.DriverScoreService::DEFAULT_SCORE)
            ->modalDescription(fn (User $r) => 'Điểm '.$r->name.' sẽ về '.DriverScoreService::DEFAULT_SCORE.' và chuỗi đơn về 0. Việc này không hoàn tác được nhưng được ghi lại trong nhật ký.')
            ->form([Forms\Components\Textarea::make('note')->label('Lý do')->required()->minLength(3)->maxLength(500)->rows(2)])
            ->action(function (User $record, array $data): void {
                DriverScoreService::resetToDefault($record->id, $data['note'], auth()->id());
                Notification::make()->success()->title('Đã đặt lại điểm về '.DriverScoreService::DEFAULT_SCORE)->send();
            });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    /** Màu thanh điểm theo màu xếp loại. */
    private const BAR_COLORS = ['success' => '#16a34a', 'info' => '#0ea5e9', 'primary' => '#f97316', 'gray' => '#94a3b8', 'danger' => '#dc2626'];

    public static function scoreColor(int $score, ?int $cityId): string
    {
        return match (true) {
            $score >= DriverScoreService::weeklyBonusScore($cityId) => 'success',
            $score >= DriverScoreService::GOOD_SCORE => 'info',
            $score >= DriverScoreService::FAIR_SCORE => 'primary',
            $score > DriverScoreService::weeklyPenaltyScore($cityId) => 'gray',
            default => 'danger',
        };
    }

    private static function bonusTodayLabel(User $r): string
    {
        $today = now()->toDateString();
        $points = ($r->daily_bonus_date === $today) ? ($r->daily_bonus_points ?? 0) : 0;

        return "Thưởng hôm nay +{$points}/".DriverScoreService::dailyBonusCap($r->city_id);
    }

    public static function reasonLabel(string $reason, ?int $cityId): string
    {
        $thresholds = OperationalSettings::shiftOnlineThresholds($cityId);
        $percent = fn (float $value): int => (int) round($value * 100);

        return match (true) {
            $reason === 'complete' => 'Hoàn thành đơn',
            $reason === 'decline' => 'Từ chối đơn',
            $reason === 'viewed_timeout' => 'Xem đơn nhưng không nhận',
            $reason === 'offer_unviewed_x3' => 'Không xem '.OperationalSettings::unviewedLimit($cityId).'/'.OperationalSettings::unviewedWindowSize($cityId).' đơn gần nhất',
            str_starts_with($reason, 'streak_') => 'Thưởng chuỗi '.str_replace('streak_', '', $reason).' đơn',
            $reason === 'shift_online_normal' => 'Online đủ ca ('.$percent($thresholds['normal']).'–100%)',
            $reason === 'shift_online_reduced' => 'Online '.$percent($thresholds['reduced']).'–'.($percent($thresholds['normal']) - 1).'% ca',
            $reason === 'shift_online_mid' => 'Online '.$percent($thresholds['mid']).'–'.($percent($thresholds['reduced']) - 1).'% ca',
            $reason === 'shift_online_low' => 'Online '.$percent($thresholds['low']).'–'.($percent($thresholds['mid']) - 1).'% ca',
            $reason === 'shift_online_critical' => 'Online dưới '.$percent($thresholds['low']).'% ca',
            $reason === 'shift_never_online' => 'Không online trong ca',
            $reason === 'shift_online_high' => 'Online từ 90% ca',
            $reason === 'shift_online_neutral' => 'Online 70–90% ca',
            str_starts_with($reason, 'inactivity_') => 'Không hoạt động',
            $reason === 'online_below_8h' => 'Online dưới 8 giờ',
            $reason === 'weekly_reset' => 'Đặt lại điểm đầu tuần',
            $reason === 'manual_reset' => 'Quản trị viên đặt lại điểm',
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

    /** Bộ lọc bảng dùng cấu hình của khu vực đang chọn trên thanh trên cùng. */
    private static function tenantCityId(): ?int
    {
        return Filament::getTenant()?->getKey();
    }
}
