<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverWalletResource\Pages;
use App\Filament\Resources\DriverWalletResource\RelationManagers;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Services\DriverWalletService;

class DriverWalletResource extends Resource
{
    use RestrictToFullAdmin;

    // DriverWallet không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverWallet::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Tài chính tài xế';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['driver.city', 'latestTransaction'])
            ->withSum([
                'transactions as credit_today' => fn (Builder $query) => $query
                    ->where('type', 'credit')
                    ->whereDate('created_at', now()->toDateString()),
            ], 'amount')
            ->withSum([
                'transactions as debit_today' => fn (Builder $query) => $query
                    ->where('type', 'debit')
                    ->whereDate('created_at', now()->toDateString()),
            ], 'amount')
            ->addSelect([
                'pending_withdraw_amount' => \Illuminate\Support\Facades\DB::table('withdraw_requests')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('driver_id', 'driver_wallets.driver_id')
                    ->where('status', 'pending'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin tài xế')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('driver.name')
                        ->label('Tên tài xế')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('driver.phone')
                        ->label('Số điện thoại')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('driver.city.name')
                        ->label('Khu vực')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('driver.is_online')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Đang online' : 'Offline')
                        ->color(fn ($state) => $state ? 'success' : 'gray'),

                    Infolists\Components\TextEntry::make('balance')
                        ->label('Số dư hiện tại')
                        ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                        ->size('lg')
                        ->color(fn ($state) => $state < 100_000 ? 'danger' : 'success'),

                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Cập nhật lần cuối')
                        ->dateTime('d/m/Y H:i'),
                ]),

            Infolists\Components\Section::make('Tổng quan hôm nay')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('credit_today')
                        ->label('Tiền vào')
                        ->formatStateUsing(fn ($state) => '+'.number_format((float) $state, 0, ',', '.').' ₫')
                        ->color('success'),
                    Infolists\Components\TextEntry::make('debit_today')
                        ->label('Tiền ra')
                        ->formatStateUsing(fn ($state) => '-'.number_format((float) $state, 0, ',', '.').' ₫')
                        ->color('danger'),
                    Infolists\Components\TextEntry::make('pending_withdraw_amount')
                        ->label('Đang chờ rút')
                        ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' ₫')
                        ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()
                    ->label('#')
                    ->alignCenter()
                    ->width(40),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->description(fn (DriverWallet $record) => collect([
                        $record->driver?->phone,
                        $record->driver?->city?->name,
                        $record->driver?->is_online ? 'Đang online' : 'Offline',
                    ])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Số dư')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                    ->color(fn ($state) => $state < 100_000 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('credit_today')
                    ->label('Hôm nay')
                    ->formatStateUsing(fn ($state) => '+'.number_format((float) $state, 0, ',', '.').' ₫')
                    ->color('success')
                    ->description(fn (DriverWallet $record) => '-'.number_format((float) $record->debit_today, 0, ',', '.').' ₫ tiền ra'),

                Tables\Columns\TextColumn::make('latestTransaction.description')
                    ->label('Giao dịch gần nhất')
                    ->placeholder('Chưa có giao dịch')
                    ->wrap()
                    ->description(function (DriverWallet $record) {
                        $transaction = $record->latestTransaction;
                        if (! $transaction) {
                            return null;
                        }

                        $sign = $transaction->type === 'credit' ? '+' : '-';

                        return $sign.number_format($transaction->amount, 0, ',', '.').' ₫ · '.$transaction->created_at?->format('d/m H:i');
                    })
                    ->color(fn (DriverWallet $record) => $record->latestTransaction?->type === 'credit' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('pending_withdraw_amount')
                    ->label('Chờ rút')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' ₫')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('balance_range')
                    ->label('Số dư')
                    ->options([
                        'low' => 'Dưới 100.000₫',
                        'normal' => 'Từ 100.000₫',
                        'high' => 'Từ 1.000.000₫',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'low' => $query->where('balance', '<', 100_000),
                        'normal' => $query->whereBetween('balance', [100_000, 999_999]),
                        'high' => $query->where('balance', '>=', 1_000_000),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('online_status')
                    ->label('Trạng thái tài xế')
                    ->options(['online' => 'Đang online', 'offline' => 'Offline'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'online' => $query->whereHas('driver', fn ($q) => $q->where('is_online', true)),
                        'offline' => $query->whereHas('driver', fn ($q) => $q->where('is_online', false)),
                        default => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Điều chỉnh')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (DriverWallet $record) => 'Điều chỉnh ví: '.$record->driver?->name)
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Loại')
                            ->options(['credit' => 'Cộng tiền', 'debit' => 'Trừ tiền'])
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Số tiền')
                            ->numeric()
                            ->minValue(1000)
                            ->required()
                            ->suffix('₫'),

                        Forms\Components\Textarea::make('description')
                            ->label('Lý do')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DriverWallet $record, array $data) {
                        try {
                            DriverWalletService::adjust(
                                $record->driver_id,
                                (float) $data['amount'],
                                $data['type'],
                                $data['description'].' (admin)',
                                'admin_adj_'.$record->driver_id.'_'.now()->timestamp
                            );
                            $record->refresh();
                            Notification::make()->success()
                                ->title('Đã '.($data['type'] === 'credit' ? 'cộng' : 'trừ').' '.number_format($data['amount'], 0, ',', '.').' ₫')
                                ->body('Số dư mới: '.number_format($record->balance, 0, ',', '.').' ₫')
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()->danger()->title('Lỗi: '.$e->getMessage())->send();
                        }
                    }),

                Tables\Actions\ViewAction::make()->label('')->tooltip('Xem giao dịch')->icon('heroicon-o-list-bullet'),
            ])
            ->recordUrl(fn (DriverWallet $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('balance', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
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
            'view' => Pages\ViewDriverWallet::route('/{record}'),
        ];
    }
}
