<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverWalletResource\Pages;
use App\Filament\Resources\DriverWalletResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Services\DriverWalletService;

class DriverWalletResource extends Resource
{
    protected static ?string $model = DriverWallet::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Quản lý ví';

    protected static ?string $modelLabel = 'Ví tài xế';

    protected static ?string $pluralModelLabel = 'Ví tài xế';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('driver.city.name')
                    ->label('Khu vực')
                    ->default('—'),

                Tables\Columns\IconColumn::make('driver.is_online')
                    ->label('Online')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Số dư')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.') . ' ₫')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn ($state) => $state < 100000 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật lần cuối')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Điều chỉnh')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->modalHeading(fn (DriverWallet $record) => 'Điều chỉnh ví: ' . $record->driver?->name)
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Loại')
                            ->options([
                                'credit' => 'Cộng tiền',
                                'debit'  => 'Trừ tiền',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Số tiền (₫)')
                            ->numeric()
                            ->minValue(1000)
                            ->required()
                            ->suffix('₫'),

                        Forms\Components\Textarea::make('description')
                            ->label('Lý do / Mô tả')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DriverWallet $record, array $data) {
                        try {
                            $ref = 'admin_adj_' . $record->driver_id . '_' . now()->timestamp;
                            DriverWalletService::adjust(
                                $record->driver_id,
                                (float) $data['amount'],
                                $data['type'],
                                $data['description'] . ' (admin)',
                                $ref
                            );
                            $record->refresh();
                            Notification::make()
                                ->success()
                                ->title('Đã ' . ($data['type'] === 'credit' ? 'cộng' : 'trừ') . ' ' . number_format($data['amount'], 0, ',', '.') . ' ₫')
                                ->body('Số dư mới: ' . number_format($record->balance, 0, ',', '.') . ' ₫')
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                        }
                    }),

                Tables\Actions\ViewAction::make()
                    ->label('Giao dịch'),
            ])
            ->defaultSort('balance', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverWallets::route('/'),
            'view'  => Pages\ViewDriverWallet::route('/{record}'),
        ];
    }
}
