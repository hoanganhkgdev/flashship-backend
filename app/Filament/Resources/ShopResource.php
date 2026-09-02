<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopResource\Pages;
use App\Filament\Resources\ShopResource\RelationManagers;
use App\Filament\Traits\HideFromCityManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class ShopResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Người dùng & đối tác';

    protected static ?string $modelLabel = 'Cửa hàng';

    protected static ?string $pluralModelLabel = 'Cửa hàng';

    protected static ?string $slug = 'shops';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_type', 'shop');
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

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('user_type', 'shop')),

                    Forms\Components\Select::make('city_id')
                        ->label('Thành phố')
                        ->relationship('city', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->label('#')
                    ->rowIndex()
                    ->width(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tên shop')
                    ->searchable(['name', 'phone', 'email', 'address'])
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (User $record) => $record->address ?? '—'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Thành phố')
                    ->badge()
                    ->color('info')
                    ->default('—'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Tổng đơn')
                    ->counts('shopOrders')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

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
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([1 => 'Hoạt động', 2 => 'Bị khóa']),

                SelectFilter::make('city_id')
                    ->label('Thành phố')
                    ->relationship('city', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\Action::make('toggle_status')
                    ->label('')
                    ->icon(fn (User $record) => $record->status == 1 ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->status == 1 ? 'danger' : 'success')
                    ->tooltip(fn (User $record) => $record->status == 1 ? 'Khoá shop' : 'Mở khoá shop')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update([
                        'status' => $record->status == 1 ? 2 : 1,
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // users không dùng SoftDeletes — xoá thật, kèm cascade xoá
                    // lịch sử thông báo + lượt dùng voucher, và làm mất gán
                    // chủ đơn ở các đơn hàng cũ (orders.sender_platform_id bị
                    // set NULL). Cảnh báo rõ thay vì modal chung chung mặc định.
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Xoá hẳn các tài khoản shop này sẽ xoá luôn lịch sử thông báo, lượt dùng voucher, và làm mất gán chủ đơn ở các đơn hàng cũ. Không thể hoàn tác.'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
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
            'create' => Pages\CreateShop::route('/create'),
            'view' => Pages\ViewShop::route('/{record}'),
            'edit' => Pages\EditShop::route('/{record}/edit'),
        ];
    }
}
