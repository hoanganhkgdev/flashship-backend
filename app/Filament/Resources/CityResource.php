<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\City;

class CityResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    // City là chính model tenant — quản lý danh sách khu vực phải xem xuyên
    // suốt tất cả khu vực, không tự lọc theo khu vực đang đứng.
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Giá & khu vực';

    protected static ?string $modelLabel = 'Khu vực';

    protected static ?string $pluralModelLabel = 'Khu vực';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'users as drivers_count',
                'users as online_drivers_count' => fn (Builder $query) => $query->where('is_online', true),
                'customers',
                'shops',
                'orders',
                'orders as orders_today_count' => fn (Builder $query) => $query->whereDate('created_at', today()),
                'shifts',
                'shifts as active_shifts_count' => fn (Builder $query) => $query->where('is_active', true),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin khu vực')
                ->description('Tên, mã nội bộ và trạng thái phục vụ của khu vực')
                ->icon('heroicon-o-map-pin')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên khu vực')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('VD: Rạch Giá'),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->placeholder('VD: rach-gia')
                        ->helperText('Dùng để phân biệt nội bộ'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Đang hoạt động')
                        ->default(true)
                        ->hidden(fn (?City $record): bool => $record !== null && Filament::getTenant()?->is($record))
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Cấu hình')
                ->description('Các thiết lập tài chính và vận hành theo khu vực')
                ->icon('heroicon-o-cog-6-tooth')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('weekly_fee')
                        ->label('Phí duy trì tài xế / tuần')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('đ')
                        ->helperText('Số tiền trừ ví tài xế mỗi tuần để duy trì hoạt động'),
                ]),

            Forms\Components\Section::make('Tọa độ trung tâm')
                ->description('Dùng để hiển thị bản đồ và tính khoảng cách mặc định')
                ->icon('heroicon-o-map')
                ->columns(2)
                ->collapsed()
                ->schema([
                    // Autocomplete địa chỉ → tự fill lat/lng
                    Forms\Components\Select::make('address_search')
                        ->label('Tìm theo địa chỉ')
                        ->placeholder('Nhập địa chỉ để tìm kiếm...')
                        ->helperText('Chọn địa chỉ từ gợi ý để tự động điền tọa độ')
                        ->columnSpanFull()
                        ->searchable()
                        ->live()
                        ->noSearchResultsMessage('Không tìm thấy địa chỉ')
                        ->searchPrompt('Nhập ít nhất 3 ký tự...')
                        ->loadingMessage('Đang tìm kiếm...')
                        ->getSearchResultsUsing(function (string $search): array {
                            if (mb_strlen($search) < 3) {
                                return [];
                            }

                            $apiKey = config('services.google_maps.api_key');
                            $res = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                                'input' => $search,
                                'key' => $apiKey,
                                'language' => 'vi',
                                'components' => 'country:vn',
                            ]);

                            $predictions = $res->json()['predictions'] ?? [];
                            $results = [];
                            foreach ($predictions as $p) {
                                // value = place_id|description để dùng trong afterStateUpdated
                                $results[$p['place_id'].'|'.$p['description']] = $p['description'];
                            }

                            return $results;
                        })
                        ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                            if (empty($state) || ! str_contains($state, '|')) {
                                return;
                            }

                            $placeId = explode('|', $state)[0];
                            $apiKey = config('services.google_maps.api_key');

                            $res = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                                'place_id' => $placeId,
                                'fields' => 'geometry,formatted_address',
                                'key' => $apiKey,
                                'language' => 'vi',
                            ]);

                            $result = $res->json()['result'] ?? null;
                            if (! $result) {
                                return;
                            }

                            $loc = $result['geometry']['location'];
                            $set('lat', round($loc['lat'], 6));
                            $set('lng', round($loc['lng'], 6));

                            Notification::make()
                                ->title('Đã điền tọa độ')
                                ->body($result['formatted_address'])
                                ->success()
                                ->send();
                        }),

                    Forms\Components\TextInput::make('lat')
                        ->label('Vĩ độ (Latitude)')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->placeholder('10.0000'),

                    Forms\Components\TextInput::make('lng')
                        ->label('Kinh độ (Longitude)')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->placeholder('105.0000'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin khu vực')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Tên khu vực')
                        ->size('lg'),

                    Infolists\Components\TextEntry::make('slug')
                        ->label('Slug')
                        ->default('—'),

                    Infolists\Components\IconEntry::make('is_active')
                        ->label('Trạng thái')
                        ->boolean(),

                    Infolists\Components\TextEntry::make('weekly_fee')
                        ->label('Phí duy trì / tuần')
                        ->formatStateUsing(fn ($state) => number_format((int) $state).'đ'),

                    Infolists\Components\TextEntry::make('lat')
                        ->label('Vĩ độ')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('lng')
                        ->label('Kinh độ')
                        ->default('—'),
                ]),

            Infolists\Components\Section::make('Thống kê')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('users_count')
                        ->label('Tài xế')
                        ->state(fn (City $record) => $record->drivers_count)
                        ->color('info')
                        ->suffix(' tài xế')
                        ->helperText(fn (City $record) => $record->online_drivers_count.' đang online'),

                    Infolists\Components\TextEntry::make('customers_count')
                        ->label('Khách hàng')
                        ->state(fn (City $record) => $record->customers_count)
                        ->color('success')
                        ->suffix(' khách'),

                    Infolists\Components\TextEntry::make('shops_count')
                        ->label('Cửa hàng')
                        ->suffix(' cửa hàng'),

                    Infolists\Components\TextEntry::make('orders_count')
                        ->label('Tổng đơn hàng')
                        ->state(fn (City $record) => $record->orders_count)
                        ->color('warning')
                        ->suffix(' đơn')
                        ->helperText(fn (City $record) => $record->orders_today_count.' đơn hôm nay'),

                    Infolists\Components\TextEntry::make('shifts_count')
                        ->label('Ca làm việc')
                        ->suffix(' ca')
                        ->helperText(fn (City $record) => $record->active_shifts_count.' ca đang bật'),
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Khu vực')
                    ->searchable()
                    ->description(fn (City $record) => Filament::getTenant()?->is($record)
                        ? 'Khu vực đang chọn'
                        : ($record->slug ?: 'Chưa có slug')),

                Tables\Columns\TextColumn::make('weekly_fee')
                    ->label('Cấu hình')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' ₫/tuần')
                    ->description(fn (City $record) => $record->lat !== null && $record->lng !== null
                        ? 'Tâm: '.$record->lat.', '.$record->lng
                        : 'Chưa có tọa độ trung tâm'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Người dùng')
                    ->state(fn (City $record) => $record->drivers_count.' tài xế · '.$record->customers_count.' khách')
                    ->description(fn (City $record) => $record->online_drivers_count.' tài xế online · '.$record->shops_count.' cửa hàng'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Vận hành')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' đơn')
                    ->description(fn (City $record) => $record->orders_today_count.' đơn hôm nay · '.$record->active_shifts_count.'/'.$record->shifts_count.' ca đang bật'),

                Tables\Columns\TextColumn::make('is_rain_mode')
                    ->label('Chế độ mưa')
                    ->formatStateUsing(fn ($state) => $state ? 'Đang bật' : 'Đang tắt')
                    ->color(fn ($state) => $state ? 'info' : 'gray')
                    ->description(fn (City $record) => $record->is_rain_mode && $record->rain_mode_started_at
                        ? 'Từ '.$record->rain_mode_started_at->format('d/m H:i')
                        : null),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hoạt động')
                    ->hidden(fn (?City $record): bool => $record !== null && (Filament::getTenant()?->is($record) ?? false))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái hoạt động'),
                Tables\Filters\TernaryFilter::make('is_rain_mode')->label('Chế độ mưa'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label('')
                    // users.city_id/orders.city_id đều là "set null" khi xoá
                    // City — không lỗi, nhưng xoá xong sẽ làm mồ côi âm thầm
                    // tài xế/đơn thuộc khu vực đó (mất luôn phạm vi lọc theo
                    // tenant của city_manager/call_center, sai lệch báo cáo
                    // lịch sử). Chặn hẳn nếu còn phụ thuộc, không cảnh báo
                    // suông rồi vẫn cho xoá.
                    ->before(function (City $record, Tables\Actions\DeleteAction $action) {
                        if (Filament::getTenant()?->is($record)) {
                            Notification::make()->danger()
                                ->title('Không thể xoá khu vực đang chọn')
                                ->body('Hãy chuyển sang khu vực khác trước khi thao tác.')
                                ->send();
                            $action->halt();
                        }
                        $userCount = DB::table('users')->where('city_id', $record->id)->count();
                        $orderCount = DB::table('orders')->where('city_id', $record->id)->count();

                        if ($userCount > 0 || $orderCount > 0) {
                            Notification::make()->danger()
                                ->title('Không thể xoá khu vực này')
                                ->body("Còn {$userCount} tài khoản và {$orderCount} đơn hàng thuộc khu vực này — chuyển/xoá hết trước khi xoá khu vực, tránh làm mồ côi dữ liệu.")
                                ->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordUrl(fn (City $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
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
            'index' => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'view' => Pages\ViewCity::route('/{record}'),
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
