<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopResource\Pages;
use App\Filament\Resources\ShopResource\RelationManagers;
use App\Support\AdminAccess;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;

class ShopResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Cửa hàng';

    protected static ?string $navigationLabel = 'Cửa hàng';

    protected static ?string $modelLabel = 'Cửa hàng';

    protected static ?string $pluralModelLabel = 'Cửa hàng';

    protected static ?string $slug = 'shops';

    protected static ?int $navigationSort = 1;

    /** Khóa so khớp số điện thoại: 9 chữ số cuối, bỏ khoảng trắng, dấu chấm và dấu +. */
    private const PHONE_KEY = "RIGHT(REPLACE(REPLACE(REPLACE(o.pickup_phone, ' ', ''), '.', ''), '+', ''), 9)";

    /**
     * Lần hoạt động gần nhất: đơn từ app cửa hàng hoặc đơn tổng đài khớp số điện thoại (cần gọi
     * joinCallCenter() trước). '1970-01-01' nghĩa là chưa có đơn nào.
     */
    public const LAST_ACTIVITY = "GREATEST(COALESCE((SELECT MAX(a.created_at) FROM orders a WHERE a.sender_platform_id = users.id AND a.platform = 'shop_app'), '1970-01-01'), COALESCE(cc.last_at, '1970-01-01'))";

    /**
     * Ghép số đơn tổng đài và lần gọi gần nhất theo số điện thoại lấy hàng vào truy vấn shop.
     * Tính MỘT lần cho mọi shop (bảng tạm cc) thay vì chạy một truy vấn phụ riêng cho từng shop, vốn mất cả giây
     * mỗi lần mở trang vì phải quét hàng chục nghìn đơn tổng đài.
     */
    public static function joinCallCenter($query)
    {
        $sub = DB::table('orders as o')
            ->where('o.platform', 'call_center')
            ->whereNotNull('o.pickup_phone')->where('o.pickup_phone', '<>', '')
            ->groupByRaw(self::PHONE_KEY.', o.city_id')
            ->selectRaw(self::PHONE_KEY.' as k, o.city_id as cc_city, COUNT(*) as n, MAX(o.created_at) as last_at');

        return $query->leftJoinSub($sub, 'cc', fn ($join) => $join->whereRaw('cc.k = RIGHT(users.phone, 9) AND cc.cc_city = users.city_id'));
    }

    /** Shop có đơn trong bấy nhiêu ngày gần nhất được coi là "đang hoạt động". */
    public const ACTIVE_DAYS = 30;

    /**
     * Shop do chính chủ đăng ký trên app (số điện thoại + OTP Zalo + mật khẩu). Admin không tạo tài khoản
     * thay shop: tài khoản tạo ở đây bỏ qua OTP nên không xác thực được số điện thoại.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return self::joinCallCenter(parent::getEloquentQuery())
            ->where('user_type', 'shop')
            ->select('users.*')
            // Trùng SĐT với tài xế => bị chặn dùng mã giảm giá (xem User::sharesPhoneWithDriver).
            ->selectRaw("EXISTS (SELECT 1 FROM users d WHERE d.user_type = 'driver' AND CHAR_LENGTH(users.phone) >= 9 AND d.phone IN (CONCAT('0', RIGHT(users.phone, 9)), CONCAT('84', RIGHT(users.phone, 9)), CONCAT('+84', RIGHT(users.phone, 9)), RIGHT(users.phone, 9))) as shares_driver_phone")
            ->selectRaw('('.self::LAST_ACTIVITY.') as last_activity_at')
            ->selectRaw('COALESCE(cc.n, 0) as call_center_orders')
            ->withSum(['shopOrders as total_spent' => fn (Builder $query) => $query->where('status', 'completed')], 'shipping_fee')
            ->with(['latestShopOrder' => fn ($query) => $query->select([
                'orders.id',
                'orders.sender_platform_id',
                'orders.code',
                'orders.status',
                'orders.created_at',
            ])])
            ->withCount([
                'shopOrders',
                'shopOrders as completed_orders_count' => fn (Builder $query) => $query->where('status', 'completed'),
                'shopOrders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'assigned', 'processing']),
                'shopOrders as cancelled_orders_count' => fn (Builder $query) => $query->where('status', 'cancelled'),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cửa hàng')
                ->description('Thông tin liên hệ và địa chỉ lấy hàng mặc định')
                ->icon('heroicon-o-building-storefront')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên shop')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->required()
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('user_type', 'shop')),

                    Forms\Components\Textarea::make('address')
                        ->label('Địa chỉ lấy hàng mặc định')
                        ->rows(2)
                        ->columnSpanFull(),

                ])->columns(2),

            Forms\Components\Section::make('Tài khoản')
                ->description('Thiết lập thông tin đăng nhập và trạng thái sử dụng')
                ->icon('heroicon-o-key')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Mật khẩu')
                        ->password()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->minLength(6)
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Để trống nếu không muốn đổi mật khẩu'),

                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options([1 => 'Hoạt động', 2 => 'Bị khóa'])
                        ->default(1)
                        ->required(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
        $cityId = Filament::getTenant()?->id;
        // Tổng đơn từ app cửa hàng của khu vực, để tính tỷ trọng đóng góp của từng shop.
        $regionOrders = (int) DB::table('orders')->where('platform', 'shop_app')->where('city_id', $cityId)->count();
        $lastActivity = fn (User $record): ?\Carbon\Carbon => $record->last_activity_at && ! str_starts_with((string) $record->last_activity_at, '1970')
            ? Carbon::parse($record->last_activity_at) : null;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Cửa hàng')
                    ->searchable(['name', 'phone', 'address'])
                    ->sortable()
                    ->limit(32)
                    ->color(fn (User $record): ?string => $record->status == 2 ? 'danger' : null)
                    ->description(fn (User $record) => ($record->status == 2 ? '🔒 Bị khóa · ' : '').$record->phone.' · '.Str::limit($record->address ?: '—', 16).($record->shares_driver_phone ? ' · ⚠ Trùng SĐT tài xế' : '')),

                Tables\Columns\TextColumn::make('completed_orders_count')
                    ->label('Đơn hoàn thành')
                    ->numeric()
                    ->sortable()
                    ->description(function (User $record): string {
                        $parts = [];
                        if ($record->shop_orders_count) {
                            $parts[] = number_format($record->shop_orders_count).' đơn'
                                .($record->cancelled_orders_count ? ' · '.round($record->cancelled_orders_count / $record->shop_orders_count * 100).'% hủy' : '');
                        }
                        if ($record->call_center_orders) {
                            $parts[] = '+'.number_format($record->call_center_orders).' tổng đài';
                        }

                        return $parts ? implode(' · ', $parts) : 'Chưa đặt đơn';
                    }),

                Tables\Columns\TextColumn::make('total_spent')
                    ->label('Phí đã chi')
                    ->formatStateUsing(fn ($state): string => $state ? $money($state) : '—')
                    ->sortable()
                    ->description(fn (User $record): ?string => $regionOrders && $record->shop_orders_count
                        ? round($record->shop_orders_count / $regionOrders * 100, 1).'% đơn của khu vực' : null),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Hoạt động gần nhất')
                    ->state(fn (User $record): string => $lastActivity($record)?->format('d/m/Y') ?? '—')
                    ->description(fn (User $record): string => $lastActivity($record) ? $lastActivity($record)->diffForHumans(now(), ['parts' => 1]) : 'Chưa có đơn')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([1 => 'Hoạt động', 2 => 'Bị khóa']),

                Filter::make('has_orders')
                    ->label('Đã từng tạo đơn')
                    ->query(fn (Builder $query): Builder => $query->whereHas('shopOrders')),

                Filter::make('shares_driver_phone')
                    ->label('Trùng SĐT tài xế')
                    ->query(fn (Builder $query): Builder => $query->whereRaw("EXISTS (SELECT 1 FROM users d WHERE d.user_type = 'driver' AND CHAR_LENGTH(users.phone) >= 9 AND d.phone IN (CONCAT('0', RIGHT(users.phone, 9)), CONCAT('84', RIGHT(users.phone, 9)), CONCAT('+84', RIGHT(users.phone, 9)), RIGHT(users.phone, 9)))")),

                Filter::make('created_at')
                    ->label('Ngày đăng ký')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('users.created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('users.created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    Tables\Actions\Action::make('toggle_status')
                        ->label(fn (User $record) => $record->status == 1 ? 'Khóa shop' : 'Mở khóa shop')
                        ->icon(fn (User $record) => $record->status == 1 ? 'heroicon-m-lock-closed' : 'heroicon-m-lock-open')
                        ->color(fn (User $record) => $record->status == 1 ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => $record->status == 1 ? 'Khóa tài khoản shop?' : 'Mở khóa tài khoản shop?')
                        ->action(fn (User $record) => $record->update(['status' => $record->status == 1 ? 2 : 1])),
                    // users không dùng SoftDeletes — xóa thật, kèm cascade xóa lịch sử thông báo + lượt dùng voucher,
                    // và làm mất gán chủ đơn ở các đơn cũ. Shop đã từng có đơn thì không được xóa: hãy khóa.
                    Tables\Actions\DeleteAction::make()
                        ->label('Xóa')
                        ->visible(fn (User $record): bool => ! $record->shop_orders_count && ! $record->call_center_orders)
                        ->modalDescription('Shop chưa có đơn nào nên xóa được. Xóa hẳn sẽ xóa luôn lịch sử thông báo và lượt dùng voucher. Không thể hoàn tác.'),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Thao tác khác'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('lock')
                        ->label('Khóa các shop đã chọn')
                        ->icon('heroicon-m-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 2]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ShopOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShops::route('/'),
            'view' => Pages\ViewShop::route('/{record}'),
            'edit' => Pages\EditShop::route('/{record}/edit'),
        ];
    }
}
