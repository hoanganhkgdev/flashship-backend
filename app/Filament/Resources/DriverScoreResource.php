<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverScoreResource\Pages;
use App\Filament\Resources\DriverScoreResource\RelationManagers;
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
    protected static ?string $model            = User::class;
    protected static ?string $navigationIcon   = 'heroicon-o-trophy';
    protected static ?string $navigationGroup  = 'Người dùng';
    protected static ?string $modelLabel       = 'Điểm tài xế';
    protected static ?string $pluralModelLabel = 'Điểm tài xế';
    protected static ?string $slug             = 'driver-scores';
    protected static ?int    $navigationSort   = 4;

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

    public static function getNavigationBadgeColor(): ?string { return 'warning'; }

    public static function getEloquentQuery(): Builder
    {
        $weekStart = Carbon::now()->startOfWeek()->toDateString();

        return parent::getEloquentQuery()
            ->where('user_type', 'driver')
            ->addSelect([
                'weekly_settlement_type' => DB::table('driver_score_settlements')
                    ->select('type')
                    ->whereColumn('driver_id', 'users.id')
                    ->where('week_start', $weekStart)
                    ->limit(1),
            ]);
    }

    public static function canCreate(): bool { return false; }

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
                        ->formatStateUsing(fn ($state) => ($state ?? DriverScoreService::DEFAULT_SCORE) . ' / ' . DriverScoreService::MAX_SCORE)
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
                        ->formatStateUsing(fn ($state) => ($state ?? 0) . ' đơn liên tiếp')
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
                        ->state(Carbon::now()->startOfWeek()->format('d/m/Y') . ' → ' . Carbon::now()->endOfWeek()->format('d/m/Y')),

                    Infolists\Components\TextEntry::make('weekly_settlement_status')
                        ->label('Chốt điểm')
                        ->state(function (User $r) {
                            $weekStart = Carbon::now()->startOfWeek()->toDateString();
                            $s = DB::table('driver_score_settlements')
                                ->where('driver_id', $r->id)
                                ->where('week_start', $weekStart)
                                ->first();
                            if (!$s) return 'Chưa chốt';
                            return match ($s->type) {
                                'bonus'   => 'Thưởng ' . number_format($s->amount) . '₫',
                                'penalty' => 'Phạt ' . number_format($s->amount) . '₫',
                                default   => $s->type,
                            };
                        })
                        ->badge()
                        ->color(function (User $r) {
                            $weekStart = Carbon::now()->startOfWeek()->toDateString();
                            $type = DB::table('driver_score_settlements')
                                ->where('driver_id', $r->id)
                                ->where('week_start', $weekStart)
                                ->value('type');
                            return match ($type) {
                                'bonus'   => 'success',
                                'penalty' => 'danger',
                                default   => 'gray',
                            };
                        }),

                    Infolists\Components\TextEntry::make('weekly_settlement_process')
                        ->label('Trạng thái thanh toán')
                        ->state(function (User $r) {
                            $weekStart = Carbon::now()->startOfWeek()->toDateString();
                            $status = DB::table('driver_score_settlements')
                                ->where('driver_id', $r->id)
                                ->where('week_start', $weekStart)
                                ->value('status');
                            return match ($status) {
                                'pending'   => 'Chờ xử lý',
                                'processed' => 'Đã xử lý',
                                default     => '—',
                            };
                        })
                        ->badge()
                        ->color(fn (User $r) => match (DB::table('driver_score_settlements')->where('driver_id', $r->id)->where('week_start', Carbon::now()->startOfWeek()->toDateString())->value('status')) {
                            'pending'   => 'warning',
                            'processed' => 'success',
                            default     => 'gray',
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
                    ->weight('bold')
                    ->description(fn (User $r) => $r->phone ?? ''),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->alignCenter()
                    ->default('—'),

                Tables\Columns\TextColumn::make('driver_score')
                    ->label('Điểm')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state ?? DriverScoreService::DEFAULT_SCORE) . ' / ' . DriverScoreService::MAX_SCORE)
                    ->weight('bold')
                    ->color(fn ($state) => self::scoreColor($state ?? DriverScoreService::DEFAULT_SCORE))
                    ->sortable(),

                Tables\Columns\TextColumn::make('score_label')
                    ->label('Xếp loại')
                    ->alignCenter()
                    ->state(fn (User $r) => DriverScoreService::label($r->driver_score ?? DriverScoreService::DEFAULT_SCORE))
                    ->badge()
                    ->color(fn (User $r) => self::scoreColor($r->driver_score ?? DriverScoreService::DEFAULT_SCORE)),

                Tables\Columns\TextColumn::make('consecutive_completed')
                    ->label('Streak')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state ?? 0) . ' 🔥')
                    ->default('0'),

                Tables\Columns\TextColumn::make('weekly_settlement_type')
                    ->label('Tuần này')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'bonus'   => 'Thưởng 50k',
                        'penalty' => 'Phạt 50k',
                        default   => '—',
                    })
                    ->color(fn ($state) => match ($state) {
                        'bonus'   => 'success',
                        'penalty' => 'danger',
                        default   => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('Khu vực')
                    ->relationship('city', 'name'),

                SelectFilter::make('score_range')
                    ->label('Xếp loại')
                    ->options([
                        'excellent' => 'Xuất sắc (≥130)',
                        'good'      => 'Tốt (110–129)',
                        'average'   => 'Khá (90–109)',
                        'below'     => 'Trung bình (70–89)',
                        'poor'      => 'Cần cải thiện (<70)',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'excellent' => $q->where('driver_score', '>=', 130),
                        'good'      => $q->whereBetween('driver_score', [110, 129]),
                        'average'   => $q->whereBetween('driver_score', [90, 109]),
                        'below'     => $q->whereBetween('driver_score', [70, 89]),
                        'poor'      => $q->where('driver_score', '<', 70),
                        default     => $q,
                    }),

                SelectFilter::make('weekly_settlement')
                    ->label('Chốt điểm tuần')
                    ->options([
                        'bonus'   => 'Được thưởng 50k',
                        'penalty' => 'Bị phạt 50k',
                        'none'    => 'Chưa chốt',
                    ])
                    ->query(function (Builder $q, array $data) {
                        $weekStart = Carbon::now()->startOfWeek()->toDateString();
                        return match ($data['value'] ?? null) {
                            'bonus'   => $q->whereExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)->where('type', 'bonus')),
                            'penalty' => $q->whereExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)->where('type', 'penalty')),
                            'none'    => $q->whereNotExists(fn ($sub) => $sub->from('driver_score_settlements')->whereColumn('driver_id', 'users.id')->where('week_start', $weekStart)),
                            default   => $q,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),

                Tables\Actions\Action::make('reset_score')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->tooltip('Reset điểm về ' . DriverScoreService::DEFAULT_SCORE)
                    ->requiresConfirmation()
                    ->modalHeading('Reset điểm tài xế')
                    ->modalDescription(fn (User $r) => 'Reset điểm tài xế ' . $r->name . ' về ' . DriverScoreService::DEFAULT_SCORE . '?')
                    ->action(function (User $record) {
                        DriverScoreService::resetToDefault($record->id);
                        Notification::make()->success()
                            ->title('Đã reset điểm về ' . DriverScoreService::DEFAULT_SCORE)
                            ->send();
                    }),
            ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private static function scoreColor(int $score): string
    {
        return match (true) {
            $score >= 130 => 'success',
            $score >= 110 => 'info',
            $score >= 90  => 'primary',
            $score >= 70  => 'gray',
            default       => 'danger',
        };
    }

    private static function bonusTodayLabel(User $r): string
    {
        $today  = now()->toDateString();
        $points = ($r->daily_bonus_date === $today) ? ($r->daily_bonus_points ?? 0) : 0;
        return "+{$points} / " . DriverScoreService::DAILY_BONUS_CAP . ' hôm nay';
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
            'view'  => Pages\ViewDriverScore::route('/{record}'),
        ];
    }
}
