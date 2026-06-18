<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\City;
use App\Filament\Traits\HideFromCityManager;

class CityResource extends Resource
{
    use HideFromCityManager;

    protected static ?string $model          = City::class;
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?string $modelLabel      = 'Khu vực';
    protected static ?string $pluralModelLabel = 'Khu vực';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin khu vực')
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
                        ->placeholder('VD: rach-gia')
                        ->helperText('Dùng để phân biệt nội bộ'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Đang hoạt động')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Cấu hình')
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
                            if (mb_strlen($search) < 3) return [];

                            $apiKey = config('services.google_maps.api_key');
                            $res = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                                'input'    => $search,
                                'key'      => $apiKey,
                                'language' => 'vi',
                                'components' => 'country:vn',
                            ]);

                            $predictions = $res->json()['predictions'] ?? [];
                            $results = [];
                            foreach ($predictions as $p) {
                                // value = place_id|description để dùng trong afterStateUpdated
                                $results[$p['place_id'] . '|' . $p['description']] = $p['description'];
                            }
                            return $results;
                        })
                        ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                            if (empty($state) || !str_contains($state, '|')) return;

                            $placeId = explode('|', $state)[0];
                            $apiKey  = config('services.google_maps.api_key');

                            $res = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                                'place_id' => $placeId,
                                'fields'   => 'geometry,formatted_address',
                                'key'      => $apiKey,
                                'language' => 'vi',
                            ]);

                            $result = $res->json()['result'] ?? null;
                            if (!$result) return;

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
                        ->placeholder('10.0000'),

                    Forms\Components\TextInput::make('lng')
                        ->label('Kinh độ (Longitude)')
                        ->numeric()
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
                        ->weight('bold')
                        ->size('lg'),

                    Infolists\Components\TextEntry::make('slug')
                        ->label('Slug')
                        ->default('—'),

                    Infolists\Components\IconEntry::make('is_active')
                        ->label('Trạng thái')
                        ->boolean(),

                    Infolists\Components\TextEntry::make('weekly_fee')
                        ->label('Phí duy trì / tuần')
                        ->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ'),

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
                        ->state(fn (City $record) => $record->users()->count())
                        ->badge()
                        ->color('info')
                        ->suffix(' tài xế'),

                    Infolists\Components\TextEntry::make('customers_count')
                        ->label('Khách hàng')
                        ->state(fn (City $record) => $record->customers()->count())
                        ->badge()
                        ->color('success')
                        ->suffix(' khách'),

                    Infolists\Components\TextEntry::make('orders_count')
                        ->label('Tổng đơn hàng')
                        ->state(fn (City $record) => $record->orders()->count())
                        ->badge()
                        ->color('warning')
                        ->suffix(' đơn'),
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
                    ->weight('bold')
                    ->alignCenter()
                    ->searchable(),

                Tables\Columns\TextColumn::make('weekly_fee')
                    ->label('Phí / tuần')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) . 'đ' : '—'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Tài xế')
                    ->counts('users')
                    ->badge()
                    ->alignCenter()
                    ->color('info'),

                Tables\Columns\TextColumn::make('customers_count')
                    ->label('Khách hàng')
                    ->counts('customers')
                    ->badge()
                    ->alignCenter()
                    ->color('success'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Tổng đơn')
                    ->counts('orders')
                    ->badge()
                    ->alignCenter()
                    ->color('warning'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hoạt động')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ])
;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'view'   => Pages\ViewCity::route('/{record}'),
            'edit'   => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
