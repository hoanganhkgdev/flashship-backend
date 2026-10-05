<?php

namespace App\Filament\Resources\DriverWalletResource\RelationManagers;

use App\Services\DriverWalletReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Core\Models\User;

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
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state === 'credit' ? 'Cộng tiền' : 'Trừ tiền')
                    ->color(fn ($state) => $state === 'credit' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state, $record) => ($record->type === 'credit' ? '+' : '-').number_format($state, 0, ',', '.').' ₫'
                    )
                    ->color(fn ($record) => $record->type === 'credit' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('source')
                    ->label('Nguồn')
                    ->badge()
                    ->color('gray')
                    ->state(fn ($record) => DriverWalletReport::categoryLabels()[DriverWalletReport::categoryOf($record->reference)]),

                Tables\Columns\TextColumn::make('description')
                    ->label('Mô tả')
                    ->wrap()
                    ->description(function ($record) {
                        if (! str_starts_with((string) $record->reference, 'admin_adj_')) {
                            return null;
                        }

                        return 'Bởi '.($record->performed_by ? (User::find($record->performed_by)?->name ?? 'Quản trị viên') : 'Quản trị viên (không rõ ai)');
                    }),

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
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(fn () => DriverWalletReport::categoryLabels())
                    ->query(fn ($query, array $data) => $data['value'] ?? null
                        ? $query->whereRaw(DriverWalletReport::categorySql('reference').' = ?', [$data['value']])
                        : $query),
                SelectFilter::make('type')
                    ->label('Loại giao dịch')
                    ->options(['credit' => 'Cộng tiền', 'debit' => 'Trừ tiền']),
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
