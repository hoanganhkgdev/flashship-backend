<?php

namespace App\Filament\Resources\DriverScoreResource\RelationManagers;

use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ScoreSettlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'scoreSettlements';

    protected static ?string $title = 'Lịch sử chốt điểm';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('week_start')
                    ->label('Tuần')
                    ->formatStateUsing(fn ($state, $record) => Carbon::parse($state)->format('d/m').' → '.
                        Carbon::parse($record->week_end)->format('d/m/Y'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('score_at_settlement')
                    ->label('Điểm chốt')
                    ->alignCenter()
                    ->weight('bold')
                    ->color(fn ($state) => match (true) {
                        $state >= \Modules\Driver\Services\DriverScoreService::WEEKLY_BONUS_SCORE => 'success',
                        $state <= 70 => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'bonus' => 'Thưởng',
                        'penalty' => 'Phạt',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'bonus' => 'success',
                        'penalty' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format($state).'₫')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Chờ xử lý',
                        'processed' => 'Đã xử lý',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'processed' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->alignCenter()
                    ->dateTime('d/m/Y'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
