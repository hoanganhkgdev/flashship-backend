<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopReferralResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Shop\Models\ShopReferral;
use Modules\Shop\Services\ShopReferralService;

class ShopReferralResource extends Resource
{
    use RestrictToFullAdmin;

    // ShopReferral không có city_id — khu vực xác định qua shop được giới thiệu (users.city_id).
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('referred', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = ShopReferral::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Cửa hàng';

    protected static ?string $navigationLabel = 'Shop giới thiệu shop';

    protected static ?string $modelLabel = 'Shop giới thiệu shop';

    protected static ?string $pluralModelLabel = 'Shop giới thiệu shop';

    protected static ?int $navigationSort = 5;

    private const STATUS_LABELS = [
        ShopReferral::PENDING => 'Chờ đủ đơn',
        ShopReferral::REWARDED => 'Đã cộng điểm',
        ShopReferral::REJECTED => 'Từ chối',
    ];

    private const STATUS_COLORS = [
        ShopReferral::PENDING => 'warning',
        ShopReferral::REWARDED => 'success',
        ShopReferral::REJECTED => 'danger',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), \Filament\Facades\Filament::getTenant())
            ->where('status', ShopReferral::PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['referrer:id,name,phone,referral_code', 'referred:id,name,phone,city_id']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('referrer.name')
                    ->label('Shop giới thiệu')
                    ->description(fn (ShopReferral $r): string => trim(($r->referrer?->phone ?? '').' · '.($r->referrer?->referral_code ?? '')), position: 'below')
                    ->searchable(['name', 'phone', 'referral_code'], isIndividual: false),

                Tables\Columns\TextColumn::make('referred.name')
                    ->label('Shop được giới thiệu')
                    ->description(fn (ShopReferral $r): ?string => $r->referred?->phone)
                    ->searchable(['name', 'phone']),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (ShopReferral $r): ?string => $r->status === ShopReferral::REJECTED ? $r->reject_reason : null),

                Tables\Columns\TextColumn::make('required_orders')
                    ->label('Cần đơn')
                    ->suffix(' đơn')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('points')
                    ->label('Điểm thưởng')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((int) $state, 0, ',', '.').' điểm')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Đăng ký lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rewarded_at')
                    ->label('Cộng điểm lúc')
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('referrer_shop_id')
                    ->label('Shop giới thiệu')
                    ->relationship('referrer', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('grant')
                    ->label('Cộng điểm ngay')
                    ->icon('heroicon-o-star')
                    ->color('success')
                    ->visible(fn (ShopReferral $r) => $r->status === ShopReferral::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('Cộng điểm ngay?')
                    ->modalDescription('Cộng điểm cho shop giới thiệu và tặng voucher chào mừng cho shop mới mà không cần đủ số đơn. Chỉ dùng khi đã xác minh shop là thật.')
                    ->action(function (ShopReferral $record): void {
                        $ok = ShopReferralService::grantReward($record, $record->referred?->city_id);
                        Notification::make()
                            ->title($ok ? 'Đã cộng điểm cho shop giới thiệu' : 'Lượt giới thiệu này đã được xử lý trước đó')
                            ->color($ok ? 'success' : 'warning')
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ShopReferral $r) => $r->status === ShopReferral::PENDING)
                    ->modalHeading('Từ chối lượt giới thiệu')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Lý do')
                            ->required()
                            ->maxLength(200)
                            ->placeholder('VD: Shop ảo, tự đặt đơn để lấy điểm'),
                    ])
                    ->action(function (ShopReferral $record, array $data): void {
                        $ok = ShopReferralService::reject($record, $data['reason']);
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
            'index' => Pages\ListShopReferrals::route('/'),
        ];
    }
}
