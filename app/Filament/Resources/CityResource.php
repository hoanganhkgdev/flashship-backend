<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use App\Services\CatalogService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
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

    protected static ?string $navigationLabel = 'Khu vực';

    protected static ?string $modelLabel = 'Khu vực';

    protected static ?string $pluralModelLabel = 'Khu vực';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
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
                        ->helperText('Dùng để phân biệt nội bộ. Tự chuyển về chữ thường, không dấu, nối bằng gạch ngang khi lưu.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Đang hoạt động')
                        ->default(true)
                        ->hidden(fn (?City $record): bool => $record !== null && Filament::getTenant()?->is($record))
                        ->helperText('Tắt thì khách và cửa hàng không còn thấy khu vực này trong app.'),

                    Forms\Components\Toggle::make('is_test')
                        ->label('Khu vực thử')
                        ->helperText('Vẫn dùng bình thường trong admin nhưng ẩn khỏi danh sách khu vực trong app của người dùng thật.'),
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
                        ->helperText('Mỗi sáng thứ Hai hệ thống tạo một khoản công nợ phí tuần cho từng tài xế đang hoạt động (không trừ thẳng vào ví). Đổi phí chỉ áp dụng từ tuần sau, các khoản đã tạo không đổi.'),
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

    private static function health(City $c): array
    {
        static $all = null;
        $all ??= CatalogService::health();

        return $all[$c->id];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Khu vực')
                    ->searchable()
                    ->description(fn (City $c) => Filament::getTenant()?->is($c) ? 'Khu vực đang chọn' : ($c->slug ?: 'Chưa có slug')),

                Tables\Columns\TextColumn::make('orders30')
                    ->label('Đơn 30 ngày')
                    ->alignEnd()
                    ->state(fn (City $c) => number_format(self::health($c)['orders30'], 0, ',', '.'))
                    ->description(fn (City $c) => number_format(self::health($c)['ordersToday'], 0, ',', '.').' đơn hôm nay'),

                Tables\Columns\TextColumn::make('people')
                    ->label('Con người')
                    ->state(fn (City $c) => self::health($c)['drivers'].' tài xế · '.self::health($c)['online'].' online')
                    ->description(fn (City $c) => number_format(self::health($c)['customers'], 0, ',', '.').' khách · '.number_format(self::health($c)['shops'], 0, ',', '.').' cửa hàng'),

                Tables\Columns\TextColumn::make('setup')
                    ->label('Thiết lập')
                    ->state(fn (City $c) => self::health($c)['priced'].'/'.self::health($c)['services'].' dịch vụ có giá')
                    ->description(fn (City $c) => self::health($c)['activeShifts'].'/'.self::health($c)['shifts'].' ca bật · phí tuần '.number_format((int) $c->weekly_fee, 0, ',', '.').'₫')
                    ->color(fn (City $c) => self::health($c)['missing']->isNotEmpty() && $c->is_active && ! $c->is_test ? 'danger' : null),

                Tables\Columns\TextColumn::make('flags')
                    ->label('Lưu ý')
                    ->html()
                    ->wrap()
                    ->extraAttributes(['style' => 'white-space:normal;max-width:16rem'])
                    ->state(fn (City $c) => collect(self::health($c)['flags'])
                        ->map(fn ($f) => '<span class="fs-order-pill fs-order-pill--'.$f['level'].'">'.e($f['label']).'</span>')->implode(' ') ?: '—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (City $c) => ! $c->is_active ? 'Đã tắt' : ($c->is_rain_mode ? 'Đang mưa' : 'Hoạt động'))
                    ->color(fn (City $c) => ! $c->is_active ? 'gray' : ($c->is_rain_mode ? 'info' : 'success')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái hoạt động'),
                Tables\Filters\TernaryFilter::make('is_rain_mode')->label('Chế độ mưa'),
                Tables\Filters\TernaryFilter::make('is_test')->label('Khu vực thử'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    self::toggleAction(Tables\Actions\Action::make('toggle')),
                    self::deleteAction(Tables\Actions\DeleteAction::make()),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (City $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    /** Bật/tắt khu vực: có xác nhận, nêu số người bị ảnh hưởng và ghi nhật ký. */
    public static function toggleAction($action)
    {
        return $action
            ->label(fn (City $c) => $c->is_active ? 'Tắt khu vực' : 'Bật khu vực')
            ->icon(fn (City $c) => $c->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (City $c) => $c->is_active ? 'danger' : 'success')
            ->visible(fn (City $c) => ! (Filament::getTenant()?->is($c) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (City $c) => ($c->is_active ? 'Tắt ' : 'Bật ').$c->name)
            ->modalDescription(function (City $c) {
                $i = CatalogService::cityImpact($c);
                $who = $i['customers'].' khách, '.$i['shops'].' cửa hàng, '.$i['drivers'].' tài xế hoạt động';

                return $c->is_active
                    ? 'Khu vực sẽ biến mất khỏi danh sách trong app (đăng ký, chọn khu vực). Ảnh hưởng tới: '.$who.'.'
                    : 'Khu vực hiện lại trong app'.($c->is_test ? ' (nhưng là khu vực thử nên vẫn bị ẩn khỏi app)' : '').'. Liên quan: '.$who.'.';
            })
            ->action(function (City $record): void {
                CatalogService::setCityActive($record, ! $record->is_active, auth()->id());
                Notification::make()->success()->title($record->fresh()->is_active ? 'Đã bật khu vực' : 'Đã tắt khu vực')->send();
            });
    }

    /** users.city_id/orders.city_id đều là "set null" khi xoá City nên chặn hẳn nếu còn phụ thuộc, tránh mồ côi dữ liệu. */
    public static function deleteAction($action)
    {
        return $action->label('Xoá khu vực')
            ->before(function (City $record, $action) {
                if (Filament::getTenant()?->is($record)) {
                    Notification::make()->danger()->title('Không thể xoá khu vực đang chọn')->body('Hãy chuyển sang khu vực khác trước khi thao tác.')->send();
                    $action->halt();
                }
                $userCount = DB::table('users')->where('city_id', $record->id)->count();
                $orderCount = DB::table('orders')->where('city_id', $record->id)->count();
                if ($userCount > 0 || $orderCount > 0) {
                    Notification::make()->danger()->title('Không thể xoá khu vực này')
                        ->body("Còn {$userCount} tài khoản và {$orderCount} đơn hàng thuộc khu vực này — chuyển/xoá hết trước, tránh làm mồ côi dữ liệu.")->send();
                    $action->halt();
                }
            })
            ->after(fn (City $record) => CatalogService::log('city', $record->id, 'deleted', auth()->id(), $record->name));
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
