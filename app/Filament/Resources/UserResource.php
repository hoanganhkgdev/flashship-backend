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

    protected static ?string $navigationGroup = 'Người dùng & đối tác';

    protected static ?string $modelLabel = 'Khách hàng';

    protected static ?string $pluralModelLabel = 'Khách hàng';

    protected static ?string $slug = 'customers';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_type', 'customer')
            ->with(['latestCustomerOrder' => fn ($query) => $query->select([
                'orders.id',
                'orders.sender_platform_id',
                'orders.code',
                'orders.status',
                'orders.created_at',
            ])])
            ->withCount([
                'customerOrders',
                'customerOrders as completed_orders_count' => fn (Builder $query) => $query->where('status', 'completed'),
                'customerOrders as active_orders_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'assigned', 'processing']),
                'customerAddresses',
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cá nhân')
                ->description('Thông tin liên hệ và khu vực hoạt động của khách hàng')
                ->icon('heroicon-o-identification')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Họ tên')
                        ->required(),

                    Forms\Components\TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->required(),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email(),

                    Forms\Components\Select::make('city_id')
                        ->label('Thành phố')
                        ->relationship('city', 'name')
                        ->searchable()
                        ->preload(),
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
                        ->options([1 => 'Hoạt động', 0 => 'Chờ duyệt', 2 => 'Bị khóa'])
                        ->required(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->label('#')
                    ->rowIndex()
                    ->width(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('Khách hàng')
                    ->searchable(['name', 'phone', 'email'])
                    ->sortable()
                    ->description(fn (User $record): string => $record->phone ?: 'Chưa có số điện thoại'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Liên hệ')
                    ->placeholder('Chưa có email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('customer_orders_count')
                    ->label('Hoạt động đơn')
                    ->description(fn (User $record): string => number_format($record->completed_orders_count).' hoàn thành · '.number_format($record->active_orders_count).' đang xử lý')
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
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        0 => 'Chờ duyệt',
                        1 => 'Hoạt động',
                        2 => 'Bị khóa',
                        default => 'Không rõ',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        0 => 'warning',
                        1 => 'success',
                        2 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('H:i · d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([0 => 'Chờ duyệt', 1 => 'Hoạt động', 2 => 'Bị khóa']),

                Filter::make('has_orders')
                    ->label('Đã từng đặt đơn')
                    ->query(fn (Builder $query): Builder => $query->whereHas('customerOrders')),

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
                Tables\Actions\EditAction::make()->label(''),
                // users không dùng SoftDeletes — xoá thật, kèm cascade xoá
                // luôn lịch sử thông báo + lượt dùng voucher (mất dấu chống
                // dùng lại voucher 1 lần nếu đăng ký lại đúng SĐT), và
                // orders.sender_platform_id/delivery_man_id bị set NULL (mất
                // gán chủ đơn trong báo cáo lịch sử). Cảnh báo rõ ràng thay
                // vì modal xác nhận chung chung mặc định.
                Tables\Actions\DeleteAction::make()->label('')
                    ->modalDescription('Xoá hẳn tài khoản này sẽ xoá luôn lịch sử thông báo, lượt dùng voucher (có thể dùng lại voucher 1 lần nếu đăng ký lại đúng SĐT), và làm mất gán chủ đơn ở các đơn hàng cũ. Không thể hoàn tác.'),
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
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
