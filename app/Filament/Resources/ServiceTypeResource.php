<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceTypeResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;

class ServiceTypeResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    // Danh mục loại dịch vụ dùng chung toàn hệ thống, không theo khu vực.
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = ServiceType::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Giá & khu vực';

    protected static ?int $navigationSort = 2;

    protected static ?string $label = 'Dịch vụ';

    protected static ?string $pluralLabel = 'Dịch vụ';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'pricingConfigs',
            'pricingConfigs as active_pricing_configs_count' => fn (Builder $query) => $query->where('is_active', true),
            'orders',
            'orders as orders_today_count' => fn (Builder $query) => $query->whereDate('created_at', today()),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin')
                ->description('Tên hiển thị và mã kỹ thuật dùng trong hệ thống')
                ->icon('heroicon-o-information-circle')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label('Tên hiển thị')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('key')
                        ->label('Key')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->alphaNum()
                        ->disabledOn('edit')
                        ->helperText('VD: delivery, shopping, bike — không dấu, không khoảng trắng'),
                ]),

            Forms\Components\Section::make('Hiển thị')
                ->description('Hình ảnh, thứ tự và trạng thái trên ứng dụng')
                ->icon('heroicon-o-photo')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('icon_url')
                        ->label('Hình ảnh')
                        ->image()
                        ->disk('public')
                        ->directory('service-types')
                        ->imagePreviewHeight('80')
                        ->nullable()
                        ->helperText('PNG/SVG nền trong suốt, tối thiểu 100×100px')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Thứ tự hiển thị')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Hiển thị')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->alignCenter()
                    ->width(50),

                Tables\Columns\ImageColumn::make('icon_url')
                    ->label('Icon')
                    ->disk('public')
                    ->alignCenter()
                    ->square()
                    ->size(40),

                Tables\Columns\TextColumn::make('label')
                    ->label('Tên dịch vụ')
                    ->searchable()
                    ->description(fn (ServiceType $record) => 'Mã: '.$record->key),

                Tables\Columns\TextColumn::make('pricing_configs_count')
                    ->label('Bảng giá')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' cấu hình')
                    ->description(fn (ServiceType $record) => $record->active_pricing_configs_count.' đang hoạt động')
                    ->color(fn (ServiceType $record) => $record->active_pricing_configs_count > 0 ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Đơn hàng')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' đơn')
                    ->description(fn (ServiceType $record) => number_format((int) $record->orders_today_count).' đơn hôm nay'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị')
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái hiển thị'),
                Tables\Filters\Filter::make('missing_pricing')
                    ->label('Chưa có bảng giá hoạt động')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('pricingConfigs', fn (Builder $pricing) => $pricing->where('is_active', true))),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa dịch vụ'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Xóa dịch vụ')
                    ->before(function (ServiceType $record, Tables\Actions\DeleteAction $action) {
                        if ($record->orders_count > 0 || $record->pricing_configs_count > 0) {
                            Notification::make()->danger()
                                ->title('Không thể xóa dịch vụ này')
                                ->body('Dịch vụ đang có '.$record->orders_count.' đơn hàng và '.$record->pricing_configs_count.' cấu hình giá. Hãy tắt hiển thị nếu không còn sử dụng.')
                                ->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordAction('edit')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceTypes::route('/'),
            'create' => Pages\CreateServiceType::route('/create'),
            'edit' => Pages\EditServiceType::route('/{record}/edit'),
        ];
    }
}
