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
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('score_before')
                    ->label('Trước')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('score_after')
                    ->label('Sau')
                    ->alignCenter()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Lý do')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        str_contains($state, 'decline') => 'danger',
                        str_contains($state, 'timeout') => 'warning',
                        str_contains($state, 'bonus')   => 'success',
                        str_contains($state, 'rated')   => 'info',
                        str_contains($state, 'reset')   => 'gray',
                        default                         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([15, 30, 50]);
    }

    public function canCreate(): bool { return false; }
}
