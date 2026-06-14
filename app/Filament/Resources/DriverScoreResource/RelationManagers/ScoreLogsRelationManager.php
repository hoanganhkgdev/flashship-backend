<?php

namespace App\Filament\Resources\DriverScoreResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ScoreLogsRelationManager extends RelationManager
{
    protected static string  $relationship = 'scoreLogs';
    protected static ?string $title        = 'Lịch sử điểm';

    public function form(Form $form): Form { return $form->schema([]); }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('delta')
                    ->label('Thay đổi')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '') . $state)
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray')),

                Tables\Columns\TextColumn::make('score_before')
                    ->label('Trước')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('score_after')
                    ->label('Sau')
                    ->alignCenter()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Lý do')
                    ->formatStateUsing(fn ($state) => self::reasonLabel($state))
                    ->badge()
                    ->color(fn ($state) => self::reasonColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([15, 30, 50]);
    }

    public function canCreate(): bool { return false; }

    private static function reasonLabel(string $reason): string
    {
        return match (true) {
            $reason === 'decline'         => 'Từ chối đơn',
            $reason === 'timeout'         => 'Hết giờ nhận đơn',
            $reason === 'streak_3'        => 'Streak 3 đơn liên tiếp',
            $reason === 'streak_6'        => 'Streak 6 đơn liên tiếp',
            $reason === 'streak_10'       => 'Streak 10 đơn liên tiếp',
            $reason === 'inactivity_1d'   => 'Không HĐ 1 ngày',
            $reason === 'inactivity_2d'   => 'Không HĐ 2+ ngày',
            $reason === 'online_below_8h' => 'Online dưới 8h',
            $reason === 'weekly_reset'    => 'Reset đầu tuần',
            str_starts_with($reason, 'rated_') => (function () use ($reason) {
                $stars = str_replace(['rated_', '_stars'], '', $reason);
                return "Đánh giá {$stars}★";
            })(),
            default => $reason,
        };
    }

    private static function reasonColor(string $reason): string
    {
        return match (true) {
            $reason === 'decline'                          => 'danger',
            $reason === 'timeout'                          => 'warning',
            str_starts_with($reason, 'streak_')           => 'success',
            str_starts_with($reason, 'inactivity_')       => 'danger',
            $reason === 'online_below_8h'                 => 'warning',
            $reason === 'weekly_reset'                    => 'gray',
            $reason === 'rated_5_stars'                   => 'success',
            $reason === 'rated_4_stars'                   => 'gray',
            $reason === 'rated_3_stars'                   => 'gray',
            str_starts_with($reason, 'rated_')            => 'danger',
            default                                       => 'gray',
        };
    }
}
