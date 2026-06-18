<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRatingResource\Pages;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

class DriverRatingResource extends Resource
{
    protected static ?string $model            = Order::class;
    protected static ?string $navigationIcon   = 'heroicon-o-star';
    protected static ?string $navigationGroup  = 'Đơn hàng';
    protected static ?string $modelLabel       = 'Đánh giá tài xế';
    protected static ?string $pluralModelLabel = 'Đánh giá tài xế';
    protected static ?string $slug             = 'driver-ratings';
    protected static ?int    $navigationSort   = 3;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereNotNull('driver_rating')->where('driver_rating', '<=', 2)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('driver_rating')
            ->with(['driver', 'sender'])
            ->latest('completed_at');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin đơn')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('code')
                        ->label('Mã đơn')
                        ->weight('bold')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('service_type')
                        ->label('Dịch vụ')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'delivery' => 'Lấy hộ',
                            'shopping' => 'Mua hộ',
                            'topup'    => 'Nạp tiền',
                            'bike'     => 'Xe ôm',
                            'motor'    => 'Lái hộ xe máy',
                            'car'      => 'Lái hộ ô tô',
                            default    => $state,
                        })
                        ->color('info'),

                    Infolists\Components\TextEntry::make('completed_at')
                        ->label('Ngày hoàn thành')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Tài xế & Khách hàng')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('driver.name')
                        ->label('Tài xế')
                        ->weight('bold')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('driver.phone')
                        ->label('SĐT tài xế')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('sender.name')
                        ->label('Khách hàng')
                        ->weight('bold')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('sender.phone')
                        ->label('SĐT khách')
                        ->default('—'),
                ]),

            Infolists\Components\Section::make('Đánh giá')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('driver_rating')
                        ->label('Số sao')
                        ->formatStateUsing(fn ($state) => str_repeat('⭐', (int) $state) . ' (' . $state . '/5)')
                        ->color(fn ($state) => $state <= 2 ? 'danger' : ($state >= 4 ? 'success' : 'warning')),

                    Infolists\Components\TextEntry::make('driver_rating_note')
                        ->label('Nhận xét')
                        ->default('Không có nhận xét')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function serviceLabels(): array
    {
        static $cache = null;
        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    private static function ratingColor(int $rating): string
    {
        return match (true) {
            $rating <= 2 => '#ef4444',
            $rating === 3 => '#f59e0b',
            default       => '#22c55e',
        };
    }

    private static function ratingStars(int $rating): string
    {
        $color = self::ratingColor($rating);
        $stars = str_repeat('<span style="color:' . $color . '">★</span>', $rating)
               . str_repeat('<span style="color:#d1d5db">★</span>', 5 - $rating);
        return '<span style="font-size:1rem;letter-spacing:1px">' . $stars . '</span>';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    // Sao + tên tài xế (trái)
                    Stack::make([
                        Tables\Columns\TextColumn::make('driver_rating')
                            ->formatStateUsing(fn ($state) => self::ratingStars((int) $state))
                            ->html()
                            ->grow(false),

                        Tables\Columns\TextColumn::make('driver.name')
                                                        ->weight('bold')
                            ->size('sm')
                            ->placeholder('—'),

                        Tables\Columns\TextColumn::make('driver.phone')
                            ->color('gray')
                            ->size('xs'),
                    ])->grow(true),

                    // Ngày + dịch vụ (phải)
                    Stack::make([
                        Tables\Columns\TextColumn::make('completed_at')
                            ->dateTime('d/m H:i')
                            ->color('gray')
                            ->size('xs')
                            ->alignEnd(),

                        Tables\Columns\TextColumn::make('service_type')
                            ->formatStateUsing(fn ($state) => self::serviceLabels()[$state] ?? $state)
                            ->size('xs')
                            ->color('primary')
                            ->alignEnd(),
                    ])->grow(false),
                ]),

                // Nhận xét
                Stack::make([
                    Tables\Columns\TextColumn::make('driver_rating_note')
                        ->size('sm')
                        ->color('gray')
                        ->placeholder('Không có nhận xét')
                        ->wrap(),
                ]),

                // Khách hàng
                Split::make([
                    Tables\Columns\TextColumn::make('sender.name')
                                                ->icon('heroicon-m-user')
                        ->iconColor('gray')
                        ->size('xs')
                        ->color('gray')
                        ->placeholder('—'),

                    Tables\Columns\TextColumn::make('code')
                                                ->copyable()
                        ->size('xs')
                        ->color('gray')
                        ->formatStateUsing(fn ($state) => '#' . $state)
                        ->alignEnd(),
                ]),
            ])
            ->contentGrid(['default' => 1, 'sm' => 2, 'xl' => 3])
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('delete_rating')
                    ->label('')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->tooltip('Xóa đánh giá')
                    ->requiresConfirmation()
                    ->modalHeading('Xóa đánh giá')
                    ->modalDescription('Bạn có chắc muốn xóa đánh giá này?')
                    ->action(fn (Order $record) => $record->update([
                        'driver_rating'      => null,
                        'driver_rating_note' => null,
                    ])),
            ])
            ->bulkActions([])
            ->actionsAlignment('end')
            ->defaultPaginationPageOption(12)
            ->paginationPageOptions([12, 24, 48])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRatings::route('/'),
        ];
    }
}
