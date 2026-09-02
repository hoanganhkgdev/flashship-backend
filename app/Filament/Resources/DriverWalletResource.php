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
use Modules\Core\Models\City;
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin tài xế')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('driver.name')
                        ->label('Tên tài xế')
                        ->weight('bold'),

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
                        ->weight('bold')
                        ->size('lg')
                        ->color(fn ($state) => $state < 100_000 ? 'danger' : 'success'),

                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Cập nhật lần cuối')
                        ->dateTime('d/m/Y H:i'),
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
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('driver.city.name')
                    ->label('Khu vực')
                    ->alignCenter()
                    ->default('—'),

                Tables\Columns\TextColumn::make('driver.is_online')
                    ->label('Online')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Online' : 'Offline')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Số dư')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 100_000 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật lần cuối')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('city')
                    ->label('Khu vực')
                    ->relationship('driver.city', 'name')
                    ->options(fn () => City::where('is_active', true)->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Điều chỉnh')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
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

                Tables\Actions\ViewAction::make()->label('Giao dịch')->icon('heroicon-o-list-bullet'),
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
