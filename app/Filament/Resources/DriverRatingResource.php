<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRatingResource\Pages;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

class DriverRatingResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $modelLabel = 'Đánh giá tài xế';

    protected static ?string $pluralModelLabel = 'Đánh giá tài xế';

    protected static ?string $slug = 'driver-ratings';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        // getEloquentQuery() (không phải static::getModel()::) để tự động
        // lọc đúng theo khu vực (tenant) đang đứng — trước đây dùng thẳng
        // model nên city_manager thấy số đơn bị đánh giá thấp CỦA TOÀN BỘ
        // các khu vực khác, không riêng khu vực mình.
        $count = static::getEloquentQuery()->where('driver_rating', '<=', 2)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

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
                            'topup' => 'Nạp tiền',
                            'bike' => 'Xe ôm',
                            'motor' => 'Lái hộ xe máy',
                            'car' => 'Lái hộ ô tô',
                            default => $state,
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
                        ->formatStateUsing(fn ($state) => str_repeat('⭐', (int) $state).' ('.$state.'/5)')
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
            default => '#22c55e',
        };
    }

    private static function ratingStars(int $rating): string
    {
        $color = self::ratingColor($rating);
        $stars = str_repeat('<span style="color:'.$color.'">★</span>', $rating)
               .str_repeat('<span style="color:#d1d5db">★</span>', 5 - $rating);

        return '<span style="font-size:1rem;letter-spacing:1px">'.$stars.'</span>';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver_rating')
                    ->label('Đánh giá')
                    ->formatStateUsing(fn ($state) => self::ratingStars((int) $state))
                    ->html()
                    ->sortable(),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->description(fn (Order $record): string => $record->driver?->phone ?: '—')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('driver_rating_note')
                    ->label('Nhận xét của khách')
                    ->placeholder('Không có nhận xét')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Đơn hàng')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->description(fn (Order $record): string => self::serviceLabels()[$record->service_type] ?? $record->service_type)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('sender.name')
                    ->label('Khách hàng')
                    ->description(fn (Order $record): string => $record->sender?->phone ?: '—')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Hoàn thành')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('driver_rating')
                    ->label('Số sao')
                    ->options([5 => '5 sao', 4 => '4 sao', 3 => '3 sao', 2 => '2 sao', 1 => '1 sao']),
                SelectFilter::make('delivery_man_id')
                    ->label('Tài xế')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('service_type')
                    ->label('Dịch vụ')
                    ->options(fn (): array => self::serviceLabels()),
                Filter::make('low_rating')
                    ->label('Cần xử lý (1–2 sao)')
                    ->query(fn (Builder $query): Builder => $query->where('driver_rating', '<=', 2)),
                Filter::make('completed_at')
                    ->label('Ngày hoàn thành')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('completed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('completed_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('')->tooltip('Xem chi tiết'),
                Tables\Actions\Action::make('delete_rating')
                    ->label('')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->tooltip('Xóa đánh giá')
                    ->requiresConfirmation()
                    ->modalHeading('Xóa đánh giá')
                    ->modalDescription('Bạn có chắc muốn xóa đánh giá này?')
                    ->action(fn (Order $record) => $record->update([
                        'driver_rating' => null,
                        'driver_rating_note' => null,
                    ])),
            ])
            ->bulkActions([])
            ->actionsAlignment('end')
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRatings::route('/'),
            'view' => Pages\ViewDriverRating::route('/{record}'),
        ];
    }
}
