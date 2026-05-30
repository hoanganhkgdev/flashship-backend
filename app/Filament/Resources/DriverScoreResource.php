<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverScoreResource\Pages;
use App\Filament\Resources\DriverScoreResource\RelationManagers;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    public static function getNavigationBadge(): ?string
    {
        $count = User::where('user_type', 'driver')
            ->where('score_suspended_until', '>', now())
            ->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_type', 'driver');
    }

    public static function canCreate(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

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
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('driver_score')
                        ->label('Điểm hiện tại')
                        ->formatStateUsing(fn ($state) => ($state ?? 80) . ' / 100')
                        ->weight('bold')
                        ->size('lg')
                        ->color(fn ($state) => match (true) {
                            ($state ?? 80) >= 80 => 'success',
                            ($state ?? 80) >= 60 => 'info',
                            ($state ?? 80) >= 40 => 'warning',
                            default              => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('score_label')
                        ->label('Xếp loại')
                        ->state(fn (User $r) => DriverScoreService::label($r->driver_score ?? 80))
                        ->badge()
                        ->color(fn (User $r) => match (true) {
                            ($r->driver_score ?? 80) >= 80 => 'success',
                            ($r->driver_score ?? 80) >= 60 => 'info',
                            ($r->driver_score ?? 80) >= 40 => 'warning',
                            default                        => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('consecutive_completed')
                        ->label('Streak hoàn thành')
                        ->formatStateUsing(fn ($state) => ($state ?? 0) . ' đơn liên tiếp')
                        ->default('0 đơn liên tiếp'),

                    Infolists\Components\TextEntry::make('score_suspended_until')
                        ->label('Bị suspend đến')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('Không bị suspend')
                        ->color(fn ($state) => $state && \Carbon\Carbon::parse($state)->isFuture() ? 'danger' : null),
                ]),
        ]);
    }

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
                    ->formatStateUsing(fn ($state) => ($state ?? 80) . ' / 100')
                    ->weight('bold')
                    ->color(fn ($state) => match (true) {
                        ($state ?? 80) >= 80 => 'success',
                        ($state ?? 80) >= 60 => 'info',
                        ($state ?? 80) >= 40 => 'warning',
                        default              => 'danger',
                    }),

                Tables\Columns\TextColumn::make('score_label')
                    ->label('Xếp loại')
                    ->alignCenter()
                    ->state(fn (User $r) => DriverScoreService::label($r->driver_score ?? 80))
                    ->badge()
                    ->color(fn (User $r) => match (true) {
                        ($r->driver_score ?? 80) >= 80 => 'success',
                        ($r->driver_score ?? 80) >= 60 => 'info',
                        ($r->driver_score ?? 80) >= 40 => 'warning',
                        default                        => 'danger',
                    }),

                Tables\Columns\TextColumn::make('consecutive_completed')
                    ->label('Streak')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state ?? 0) . ' 🔥')
                    ->default('0'),

                Tables\Columns\TextColumn::make('score_suspended_until')
                    ->label('Suspend đến')
                    ->alignCenter()
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->color(fn ($state) => $state && \Carbon\Carbon::parse($state)->isFuture() ? 'danger' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('Khu vực')
                    ->relationship('city', 'name'),

                TernaryFilter::make('suspended')
                    ->label('Đang bị suspend')
                    ->queries(
                        true:  fn (Builder $q) => $q->where('score_suspended_until', '>', now()),
                        false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('score_suspended_until')->orWhere('score_suspended_until', '<=', now())),
                    ),

                SelectFilter::make('score_range')
                    ->label('Xếp loại')
                    ->options([
                        'excellent' => 'Xuất sắc (≥80)',
                        'good'      => 'Tốt (60–79)',
                        'average'   => 'Trung bình (40–59)',
                        'poor'      => 'Cần cải thiện / Kém (<40)',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'excellent' => $q->where('driver_score', '>=', 80),
                        'good'      => $q->whereBetween('driver_score', [60, 79]),
                        'average'   => $q->whereBetween('driver_score', [40, 59]),
                        'poor'      => $q->where('driver_score', '<', 40),
                        default     => $q,
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),

                Tables\Actions\Action::make('reset_score')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->tooltip('Reset điểm về 80')
                    ->requiresConfirmation()
                    ->modalHeading('Reset điểm tài xế')
                    ->modalDescription(fn (User $r) => 'Reset điểm tài xế ' . $r->name . ' về ' . DriverScoreService::DEFAULT_SCORE . '?')
                    ->action(function (User $record) {
                        DriverScoreService::resetToDefault($record->id);
                        Notification::make()->success()
                            ->title('Đã reset điểm về ' . DriverScoreService::DEFAULT_SCORE)
                            ->send();
                    }),

                Tables\Actions\Action::make('unsuspend')
                    ->label('')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->tooltip('Gỡ suspend')
                    ->visible(fn (User $r) => $r->score_suspended_until && \Carbon\Carbon::parse($r->score_suspended_until)->isFuture())
                    ->requiresConfirmation()
                    ->modalHeading('Gỡ suspend tài xế')
                    ->action(function (User $record) {
                        $record->update(['score_suspended_until' => null]);
                        Notification::make()->success()->title('Đã gỡ suspend.')->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ScoreLogsRelationManager::class,
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
