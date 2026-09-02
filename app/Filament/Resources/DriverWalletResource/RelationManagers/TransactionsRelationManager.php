<?php

namespace App\Filament\Resources\DriverWalletResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Lịch sử giao dịch';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state === 'credit' ? 'Cộng tiền' : 'Trừ tiền')
                    ->color(fn ($state) => $state === 'credit' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state, $record) => ($record->type === 'credit' ? '+' : '-').number_format($state, 0, ',', '.').' ₫'
                    )
                    ->color(fn ($record) => $record->type === 'credit' ? 'success' : 'danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(60)
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Mã tham chiếu')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i:s'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại giao dịch')
                    ->options(['credit' => 'Cộng tiền', 'debit' => 'Trừ tiền']),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([15, 30, 50]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
