<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class UserResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Khách hàng';

    protected static ?string $navigationLabel = 'Khách hàng';

    protected static ?string $modelLabel = 'Khách hàng';

    protected static ?string $pluralModelLabel = 'Khách hàng';

    protected static ?string $slug = 'customers';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_type', 'customer')
            ->select('users.*')
            // Trùng SĐT với tài xế => bị chặn dùng mã giảm giá (xem User::sharesPhoneWithDriver).
            ->selectRaw("EXISTS (SELECT 1 FROM users d WHERE d.user_type = 'driver' AND CHAR_LENGTH(users.phone) >= 9 AND d.phone IN (CONCAT('0', RIGHT(users.phone, 9)), CONCAT('84', RIGHT(users.phone, 9)), CONCAT('+84', RIGHT(users.phone, 9)), RIGHT(users.phone, 9))) as shares_driver_phone")
            ->with(['latestCustomerOrder' => fn ($query) => $query->select([
                'orders.id',
                'orders.sender_platform_id',
                'orders.code',
                'orders.status',
                'orders.created_at',
            ])])
            ->withSum(['customerOrders as total_spent' => fn (Builder $query) => $query->where('status', 'completed')], 'shipping_fee')
            ->withCount([
                'customerOrders',
                'customerOrders as completed_orders_count' => fn (Builder $query) => $query->where('status', 'completed'),
                'customerOrders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'assigned', 'processing']),
                'customerAddresses',
            ]);
    }

    /**
     * Khách hàng chỉ tự đăng ký trên app (số điện thoại + OTP Zalo + mật khẩu). Admin không tạo
     * tài khoản thay khách: tài khoản tạo ở đây bỏ qua OTP nên không xác thực được số điện thoại.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cá nhân')
                ->description('Thông tin liên hệ của khách hàng')
                ->icon('heroicon-o-identification')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Họ tên')
                        ->required(),

                    Forms\Components\TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->required(),

                ])->columns(2),

            Forms\Components\Section::make('Tài khoản')
                ->description('Mật khẩu và trạng thái truy cập ứng dụng')
                ->icon('heroicon-o-key')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Mật khẩu')
                        ->password()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->minLength(6)
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn ($state) => filled($state)),

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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Khách hàng')
                    ->searchable(['name', 'phone'])
                    ->sortable()
                    ->description(fn (User $record): string => ($record->phone ?: 'Chưa có số điện thoại').($record->shares_driver_phone ? ' · ⚠ Trùng SĐT tài xế' : '')),

                Tables\Columns\TextColumn::make('completed_orders_count')
                    ->label('Đơn hoàn thành')
                    ->numeric()
                    ->description(fn (User $record): string => $record->customer_orders_count
                        ? number_format($record->customer_orders_count).' đơn đã đặt'.($record->active_orders_count ? ' · '.number_format($record->active_orders_count).' đang xử lý' : '')
                        : 'Chưa đặt đơn')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_spent')
                    ->label('Tổng chi tiêu')
                    ->formatStateUsing(fn ($state): string => $state ? number_format((float) $state, 0, ',', '.').'₫' : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestCustomerOrder.code')
                    ->label('Đơn gần nhất')
                    ->formatStateUsing(fn ($state): string => '#'.$state)
                    ->description(fn (User $record): string => $record->latestCustomerOrder?->created_at?->format('H:i · d/m/Y') ?: 'Chưa đặt đơn')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('customer_addresses_count')
                    ->label('Địa chỉ đã lưu')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                        default => 'Không rõ',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'success',
                        2 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->date('d/m/Y')
                    ->description(fn (User $record): string => $record->created_at?->format('H:i') ?? '')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([1 => 'Hoạt động', 2 => 'Bị khóa']),

                Filter::make('has_orders')
                    ->label('Đã từng đặt đơn')
                    ->query(fn (Builder $query): Builder => $query->whereHas('customerOrders')),

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
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                Tables\Actions\Action::make('toggle_lock')
                    ->label(fn (User $record): string => (int) $record->status === 2 ? 'Mở khóa' : 'Khóa tài khoản')
                    ->icon(fn (User $record): string => (int) $record->status === 2 ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                    ->color(fn (User $record): string => (int) $record->status === 2 ? 'success' : 'danger')
                    ->tooltip(fn (User $record): string => (int) $record->status === 2 ? 'Mở khóa tài khoản' : 'Khóa tài khoản')
                    ->visible(fn (): bool => auth()->user()?->user_type === 'admin')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => (int) $record->status === 2 ? 'Mở khóa tài khoản?' : 'Khóa tài khoản?')
                    ->modalDescription(fn (User $record): string => (int) $record->status === 2
                        ? "Khách {$record->name} sẽ đăng nhập và đặt đơn lại được."
                        : "Khách {$record->name} sẽ không đăng nhập và đặt đơn được cho tới khi mở khóa.")
                    ->action(function (User $record): void {
                        $record->update(['status' => (int) $record->status === 2 ? 1 : 2]);
                    }),
                // users không dùng SoftDeletes — xoá thật, kèm cascade xoá
                // luôn lịch sử thông báo + lượt dùng voucher (mất dấu chống
                // dùng lại voucher 1 lần nếu đăng ký lại đúng SĐT), và
                // orders.sender_platform_id/delivery_man_id bị set NULL (mất
                // gán chủ đơn trong báo cáo lịch sử). Cảnh báo rõ ràng thay
                // vì modal xác nhận chung chung mặc định.
                Tables\Actions\DeleteAction::make()->label('Xóa tài khoản')
                    ->modalDescription('Xoá hẳn tài khoản này sẽ xoá luôn lịch sử thông báo, lượt dùng voucher (có thể dùng lại voucher 1 lần nếu đăng ký lại đúng SĐT), và làm mất gán chủ đơn ở các đơn hàng cũ. Không thể hoàn tác.'),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Thao tác khác'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Xoá hẳn các tài khoản này sẽ xoá luôn lịch sử thông báo, lượt dùng voucher, và làm mất gán chủ đơn ở các đơn hàng cũ. Không thể hoàn tác.'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
