<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverReferralResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Driver\Models\DriverReferral;
use Modules\Driver\Services\ReferralService;

class DriverReferralResource extends Resource
{
    use RestrictToFullAdmin;

    // DriverReferral không có city_id — khu vực xác định qua shop đăng ký (users.city_id).
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('shop', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverReferral::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Cửa hàng';

    protected static ?string $navigationLabel = 'Giới thiệu shop';

    protected static ?string $modelLabel = 'Giới thiệu shop';

    protected static ?string $pluralModelLabel = 'Giới thiệu shop';

    protected static ?int $navigationSort = 4;

    private const STATUS_LABELS = [
        DriverReferral::PENDING => 'Chờ đủ đơn',
        DriverReferral::REWARDED => 'Đã thưởng',
        DriverReferral::REJECTED => 'Từ chối',
    ];

    private const STATUS_COLORS = [
        DriverReferral::PENDING => 'warning',
        DriverReferral::REWARDED => 'success',
        DriverReferral::REJECTED => 'danger',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), \Filament\Facades\Filament::getTenant())
            ->where('status', DriverReferral::PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver:id,name,phone,referral_code', 'shop:id,name,phone,city_id']);
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
                    ->label('Tài xế giới thiệu')
                    ->description(fn (DriverReferral $r): string => trim(($r->driver?->phone ?? '').' · '.($r->driver?->referral_code ?? '')), position: 'below')
                    ->searchable(['name', 'phone', 'referral_code'], isIndividual: false)
                    ->url(fn (DriverReferral $r): ?string => $r->driver ? DriverResource::getUrl('view', ['record' => $r->driver_id]) : null),

                Tables\Columns\TextColumn::make('shop.name')
                    ->label('Shop được giới thiệu')
                    ->description(fn (DriverReferral $r): ?string => $r->shop?->phone)
                    ->searchable(['name', 'phone']),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (DriverReferral $r): ?string => $r->status === DriverReferral::REJECTED ? $r->reject_reason : null),

                Tables\Columns\TextColumn::make('required_orders')
                    ->label('Cần đơn')
                    ->suffix(' đơn')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('reward_amount')
                    ->label('Tiền thưởng')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((int) $state, 0, ',', '.').'đ')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Đăng ký lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rewarded_at')
                    ->label('Thưởng lúc')
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('driver_id')
                    ->label('Tài xế')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('grant')
                    ->label('Trả thưởng ngay')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (DriverReferral $r) => $r->status === DriverReferral::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('Trả thưởng ngay?')
                    ->modalDescription('Cộng tiền thưởng vào ví tài xế mà không cần shop đủ số đơn. Chỉ dùng khi đã xác minh shop là thật.')
                    ->action(function (DriverReferral $record): void {
                        $ok = ReferralService::grantReward($record, $record->shop?->city_id);
                        Notification::make()
                            ->title($ok ? 'Đã cộng thưởng vào ví tài xế' : 'Lượt giới thiệu này đã được xử lý trước đó')
                            ->color($ok ? 'success' : 'warning')
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DriverReferral $r) => $r->status === DriverReferral::PENDING)
                    ->modalHeading('Từ chối lượt giới thiệu')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Lý do')
                            ->required()
                            ->maxLength(200)
                            ->placeholder('VD: Shop ảo, tài xế tự đặt đơn'),
                    ])
                    ->action(function (DriverReferral $record, array $data): void {
                        $ok = ReferralService::reject($record, $data['reason']);
                        Notification::make()
                            ->title($ok ? 'Đã từ chối lượt giới thiệu' : 'Lượt giới thiệu này đã được xử lý trước đó')
                            ->color($ok ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverReferrals::route('/'),
        ];
    }
}
