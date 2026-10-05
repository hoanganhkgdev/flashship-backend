<?php

namespace App\Filament\Resources;

use Filament\Facades\Filament;
use App\Filament\Resources\DriverWalletResource\Pages;
use App\Filament\Resources\DriverWalletResource\RelationManagers;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\DriverWalletReport;
use Modules\Core\Services\OperationalSettings;
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

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Ví tài xế';

    protected static ?string $modelLabel = 'Ví tài xế';

    protected static ?string $pluralModelLabel = 'Ví tài xế';

    protected static ?int $navigationSort = 4;

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

    /** Điều chỉnh ví tay: bắt buộc lý do, lưu người làm; từ mức lớn phải nhập lại số tiền. */
    public static function adjustAction($action)
    {
        $above = DriverWalletService::ADJUST_CONFIRM_ABOVE;

        return $action
            ->label('Điều chỉnh ví')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->modalHeading(fn (DriverWallet $record) => 'Điều chỉnh ví: '.$record->driver?->name)
            ->modalDescription(fn (DriverWallet $record) => 'Số dư hiện tại '.number_format($record->balance, 0, ',', '.').'₫. Thao tác được ghi lại cùng tên bạn.')
            ->form([
                Forms\Components\Select::make('type')->label('Loại')->options(['credit' => 'Cộng tiền', 'debit' => 'Trừ tiền'])->required(),
                Forms\Components\TextInput::make('amount')->label('Số tiền')->numeric()->minValue(1000)->required()->suffix('₫')->live(onBlur: true),
                Forms\Components\TextInput::make('confirm_amount')
                    ->label('Nhập lại số tiền để xác nhận')
                    ->helperText('Số tiền từ '.number_format($above, 0, ',', '.').'₫ trở lên cần xác nhận lại.')
                    ->numeric()->suffix('₫')
                    ->visible(fn (Forms\Get $get) => (float) $get('amount') >= $above)
                    ->required(fn (Forms\Get $get) => (float) $get('amount') >= $above)
                    ->same('amount'),
                Forms\Components\Textarea::make('description')->label('Lý do')->required()->minLength(5)->maxLength(200)->rows(2),
            ])
            ->action(function (DriverWallet $record, array $data) {
                try {
                    DriverWalletService::adminAdjust($record->driver_id, (float) $data['amount'], $data['type'], $data['description'], auth()->id());
                    $record->refresh();
                    Notification::make()->success()
                        ->title('Đã '.($data['type'] === 'credit' ? 'cộng' : 'trừ').' '.number_format($data['amount'], 0, ',', '.').'₫')
                        ->body('Số dư mới: '.number_format($record->balance, 0, ',', '.').'₫')
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()->danger()->title('Không điều chỉnh được')->body($e->getMessage())->send();
                }
            });
    }

    public static function lowThreshold(): int
    {
        return OperationalSettings::lowWalletBalanceThreshold(Filament::getTenant()?->getKey());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->description(fn (DriverWallet $r) => collect([$r->driver?->phone, match ((int) $r->driver?->status) { 2 => 'Bị khóa', 0 => 'Chờ duyệt', default => null }])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Số dư')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').'₫')
                    ->color(fn ($state, DriverWallet $r) => $state < 0 ? 'danger' : ((int) $r->driver?->status === 1 && $state < self::lowThreshold() ? 'warning' : null))
                    ->sortable(),

                Tables\Columns\TextColumn::make('credit_today')
                    ->label('Vào hôm nay')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state ? '+'.number_format((float) $state, 0, ',', '.').'₫' : '—')
                    ->color('success'),

                Tables\Columns\TextColumn::make('debit_today')
                    ->label('Ra hôm nay')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state ? '−'.number_format((float) $state, 0, ',', '.').'₫' : '—')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('pending_withdraw_amount')
                    ->label('Chờ rút')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format((float) $state, 0, ',', '.').'₫' : '—')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('latestTransaction.description')
                    ->label('Giao dịch gần nhất')
                    ->placeholder('Chưa có giao dịch')
                    ->limit(36)
                    ->description(function (DriverWallet $r) {
                        $t = $r->latestTransaction;

                        return $t ? DriverWalletReport::categoryLabels()[DriverWalletReport::categoryOf($t->reference)].' · '.($t->type === 'credit' ? '+' : '−').number_format($t->amount, 0, ',', '.').'₫ · '.$t->created_at?->format('d/m H:i') : null;
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem giao dịch')->icon('heroicon-o-list-bullet'),
                    self::adjustAction(Tables\Actions\Action::make('adjust')),
                    Tables\Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')
                        ->url(fn (DriverWallet $r) => DriverResource::getUrl('view', ['record' => $r->driver_id])),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
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
