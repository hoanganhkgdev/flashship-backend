<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopDestinationPriceResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Shop\Models\ShopDestinationPrice;

class ShopDestinationPriceResource extends Resource
{
    use RestrictToFullAdmin;

    protected static ?string $model = ShopDestinationPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Cửa hàng';

    protected static ?string $navigationLabel = 'Giá theo điểm đến';

    protected static ?string $modelLabel = 'Giá theo điểm đến';

    protected static ?string $pluralModelLabel = 'Giá theo điểm đến';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Tên điểm đến')
                ->required()
                ->maxLength(120)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('lat')
                ->label('Vĩ độ (lat)')
                ->numeric()->required()->minValue(-90)->maxValue(90),
            Forms\Components\TextInput::make('lng')
                ->label('Kinh độ (lng)')
                ->numeric()->required()->minValue(-180)->maxValue(180)
                ->helperText('Mở Google Maps, bấm giữ vào điểm đến để lấy toạ độ.'),

            Forms\Components\TextInput::make('radius_km')
                ->label('Bán kính áp dụng')
                ->numeric()->required()->default(1.5)->minValue(0.2)->maxValue(20)->step(0.1)
                ->suffix('km')
                ->helperText('Điểm giao nằm trong bán kính này thì dùng giá bên dưới. Trùng nhiều điểm thì lấy điểm gần tâm nhất.'),

            Forms\Components\TextInput::make('fee')
                ->label('Giá áp dụng')
                ->numeric()->required()->minValue(0)->suffix('đ'),
            Forms\Components\TextInput::make('fee_max')
                ->label('Giá cao nhất (tham khảo)')
                ->numeric()->minValue(0)->suffix('đ')
                ->helperText('Chỉ để ghi nhớ khoảng giá, hệ thống tính theo "Giá áp dụng".'),

            Forms\Components\Toggle::make('is_origin')
                ->label('Điểm xuất phát')
                ->helperText('Nếu khu vực có điểm xuất phát, chỉ đơn lấy hàng trong vùng này mới dùng giá cố định; ngoài vùng tính theo km.'),
            Forms\Components\Toggle::make('is_active')
                ->label('Đang hoạt động')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Điểm đến')->searchable()->sortable()
                    ->description(fn (ShopDestinationPrice $r) => $r->is_origin ? 'Điểm xuất phát' : null),
                Tables\Columns\TextColumn::make('fee')->label('Giá')->sortable()
                    ->formatStateUsing(fn (ShopDestinationPrice $r) => number_format($r->fee).' đ'
                        .($r->fee_max ? ' – '.number_format($r->fee_max).' đ' : '')),
                Tables\Columns\TextColumn::make('radius_km')->label('Bán kính')->suffix(' km'),
                Tables\Columns\TextColumn::make('coords')->label('Toạ độ')
                    ->state(fn (ShopDestinationPrice $r) => round($r->lat, 5).', '.round($r->lng, 5))
                    ->color('gray'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Hoạt động')->alignCenter(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Xoá'),
            ])
            ->defaultSort('fee')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageShopDestinationPrices::route('/'),
        ];
    }
}
