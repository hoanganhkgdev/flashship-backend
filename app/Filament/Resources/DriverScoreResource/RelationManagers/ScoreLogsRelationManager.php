<?php

namespace App\Filament\Resources\DriverScoreResource\RelationManagers;

use App\Filament\Resources\DriverScoreResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ScoreLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'scoreLogs';

    protected static ?string $title = 'Lịch sử điểm';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('delta')
                    ->label('Thay đổi')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').$state)
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray')),

                Tables\Columns\TextColumn::make('score_after')
                    ->label('Số điểm')
                    ->alignCenter()
                    ->description(fn ($record) => $record->score_before.' → '.$record->score_after),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Lý do')
                    ->formatStateUsing(fn ($state) => DriverScoreResource::reasonLabel($state))
                    ->color(fn ($state) => DriverScoreResource::reasonColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('change_type')
                    ->label('Loại biến động')
                    ->options(['increase' => 'Cộng điểm', 'decrease' => 'Trừ điểm', 'unchanged' => 'Không đổi điểm'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'increase' => $query->where('delta', '>', 0),
                        'decrease' => $query->where('delta', '<', 0),
                        'unchanged' => $query->where('delta', 0),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([15, 30, 50]);
    }

    public function canCreate(): bool
    {
        return false;
    }

}
