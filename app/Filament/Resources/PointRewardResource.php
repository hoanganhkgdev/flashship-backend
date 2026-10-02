<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointRewardResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Shop\Models\PointReward;

class PointRewardResource extends Resource
{
    use RestrictToFullAdmin;

    // Danh mục dùng chung mọi khu vực (không có city_id).
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = PointReward::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Marketing & CSKH';

    protected static ?string $modelLabel = 'Quà đổi điểm';

    protected static ?string $pluralModelLabel = 'Danh mục đổi điểm';

    protected static ?int $navigationSort = 7;

    private const TYPE_LABELS = [
        'fixed' => 'Giảm số tiền cố định',
        'percent' => 'Giảm theo %',
        'freeship' => 'Miễn phí ship',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Phần quà')
                ->description('Shop dùng điểm tích luỹ từ giới thiệu để đổi voucher này. Mỗi lần đổi tạo một voucher riêng, dùng một lần.')
                ->icon('heroicon-o-gift')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên hiển thị')
                        ->required()
                        ->maxLength(80)
                        ->placeholder('VD: Giảm 25.000đ phí ship')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('points_cost')
                        ->label('Số điểm cần đổi')
                        ->numeric()->required()->minValue(1)->suffix('điểm'),
                    Forms\Components\TextInput::make('valid_days')
                        ->label('Hạn dùng sau khi đổi')
                        ->numeric()->required()->minValue(1)->default(30)->suffix('ngày'),
                ]),
            Forms\Components\Section::make('Giá trị voucher')
                ->icon('heroicon-o-ticket')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Loại giảm')
                        ->options(self::TYPE_LABELS)
                        ->required()->default('fixed')->live(),
                    Forms\Components\TextInput::make('value')
                        ->label(fn (Forms\Get $get) => $get('type') === 'percent' ? 'Phần trăm giảm' : 'Số tiền giảm')
                        ->numeric()->required()->minValue(0)->default(0)
                        ->suffix(fn (Forms\Get $get) => $get('type') === 'percent' ? '%' : 'đ')
                        ->visible(fn (Forms\Get $get) => $get('type') !== 'freeship'),
                    Forms\Components\TextInput::make('min_order_value')
                        ->label('Phí ship tối thiểu (tuỳ chọn)')->numeric()->minValue(0)->suffix('đ'),
                    Forms\Components\TextInput::make('max_discount')
                        ->label('Giảm tối đa (tuỳ chọn)')->numeric()->minValue(0)->suffix('đ')
                        ->helperText('Với "Miễn phí ship" nên đặt trần để tránh giảm quá lớn'),
                ]),
            Forms\Components\Section::make('Hiển thị')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Thứ tự')->numeric()->default(0)->minValue(0),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Cho phép đổi')->default(true)->onColor('success'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Phần quà')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('points_cost')->label('Điểm đổi')->suffix(' điểm')->sortable()->alignCenter(),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('value')->label('Giá trị')
                    ->formatStateUsing(fn ($state, PointReward $r) => match ($r->type) {
                        'percent' => "{$state}%",
                        'freeship' => 'Miễn phí ship',
                        default => number_format((int) $state, 0, ',', '.').'đ',
                    })->alignEnd(),
                Tables\Columns\TextColumn::make('valid_days')->label('Hạn dùng')->suffix(' ngày')->alignCenter(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Cho phép đổi'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->recordAction('edit');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointRewards::route('/'),
            'create' => Pages\CreatePointReward::route('/create'),
            'edit' => Pages\EditPointReward::route('/{record}/edit'),
        ];
    }
}
