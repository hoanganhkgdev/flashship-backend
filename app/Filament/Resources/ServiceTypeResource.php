<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceTypeResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use App\Services\CatalogService;
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

    protected static ?string $navigationLabel = 'Dịch vụ';

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
                Tables\Columns\TextColumn::make('sort_order')->label('#')->alignCenter()->width(50),

                Tables\Columns\ImageColumn::make('icon_url')
                    ->label('Icon')
                    ->disk('public')
                    ->alignCenter()
                    ->square()
                    ->size(40)
                    ->defaultImageUrl(fn () => null),

                Tables\Columns\TextColumn::make('label')
                    ->label('Tên dịch vụ')
                    ->searchable()
                    ->description(fn (ServiceType $r) => 'Mã: '.$r->key.($r->icon_url ? '' : ' · chưa có icon'))
                    ->color(fn (ServiceType $r) => $r->icon_url ? null : 'warning'),

                Tables\Columns\TextColumn::make('orders30')
                    ->label('Đơn 30 ngày')
                    ->alignEnd()
                    ->state(function (ServiceType $r) {
                        $o = self::orders();
                        $n = $o['by'][$r->key] ?? 0;

                        return number_format($n, 0, ',', '.').($o['total'] ? ' ('.round($n / $o['total'] * 100).'%)' : '');
                    })
                    ->description(fn (ServiceType $r) => number_format((int) $r->orders_count, 0, ',', '.').' đơn tổng'),

                Tables\Columns\TextColumn::make('coverage')
                    ->label('Giá theo khu vực')
                    ->state(function (ServiceType $r) {
                        $cities = self::activeCityIds();
                        $m = self::matrix();
                        $ok = collect($cities)->filter(fn ($id) => $m[$id][$r->key] ?? false)->count();

                        return $ok.'/'.count($cities).' khu vực có giá';
                    })
                    ->color(function (ServiceType $r) {
                        $m = self::matrix();

                        return collect(self::activeCityIds())->contains(fn ($id) => ! ($m[$id][$r->key] ?? false)) ? 'danger' : 'success';
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (ServiceType $r) => $r->is_active ? 'Đang hiển thị' : 'Đã ẩn')
                    ->color(fn (ServiceType $r) => $r->is_active ? 'success' : 'gray'),
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
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    self::toggleAction(Tables\Actions\Action::make('toggle')),
                    Tables\Actions\DeleteAction::make()
                        ->label('Xóa dịch vụ')
                        ->before(function (ServiceType $record, Tables\Actions\DeleteAction $action) {
                            if ($record->orders_count > 0 || $record->pricing_configs_count > 0) {
                                Notification::make()->danger()
                                    ->title('Không thể xóa dịch vụ này')
                                    ->body('Dịch vụ đang có '.$record->orders_count.' đơn hàng và '.$record->pricing_configs_count.' cấu hình giá. Hãy ẩn dịch vụ nếu không còn sử dụng.')
                                    ->send();
                                $action->halt();
                            }
                        })
                        ->after(fn (ServiceType $record) => CatalogService::log('service_type', $record->id, 'deleted', auth()->id(), $record->label)),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordAction('edit')
            ->paginated(false);
    }

    private static function orders(): array
    {
        static $o = null;

        return $o ??= CatalogService::serviceOrders();
    }

    private static function matrix(): array
    {
        static $m = null;

        return $m ??= CatalogService::pricingMatrix();
    }

    /** Các khu vực đang phục vụ khách thật (bật, không phải khu vực thử). */
    private static function activeCityIds(): array
    {
        static $ids = null;

        return $ids ??= \Modules\Core\Models\City::public()->pluck('id')->all();
    }

    /** Ẩn/hiện dịch vụ: có xác nhận, nêu số đơn gần đây và ghi nhật ký. */
    public static function toggleAction($action)
    {
        return $action
            ->label(fn (ServiceType $r) => $r->is_active ? 'Ẩn dịch vụ' : 'Hiện dịch vụ')
            ->icon(fn (ServiceType $r) => $r->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (ServiceType $r) => $r->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (ServiceType $r) => ($r->is_active ? 'Ẩn ' : 'Hiện ').$r->label)
            ->modalDescription(function (ServiceType $r) {
                $n = (self::orders()['by'][$r->key] ?? 0);

                return $r->is_active
                    ? 'Khách và cửa hàng sẽ không còn thấy dịch vụ này trong app. 30 ngày qua dịch vụ có '.number_format($n, 0, ',', '.').' đơn. Đơn đang chạy không bị ảnh hưởng.'
                    : 'Dịch vụ hiện lại trong app cho các khu vực có bảng giá hoạt động.';
            })
            ->action(function (ServiceType $record): void {
                CatalogService::setServiceActive($record, ! $record->is_active, auth()->id());
                Notification::make()->success()->title($record->fresh()->is_active ? 'Đã hiện dịch vụ' : 'Đã ẩn dịch vụ')->send();
            });
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
