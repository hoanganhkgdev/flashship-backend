<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRatingResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()
                    ->label('#')
                    ->alignCenter()
                    ->width(40),

                Tables\Columns\TextColumn::make('code')
                    ->label('Mã đơn')
                    ->alignCenter()
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->description(fn (Order $r) => $r->driver?->phone ?? '')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sender.name')
                    ->label('Khách hàng')
                    ->searchable()
                    ->description(fn (Order $r) => $r->sender?->phone ?? '')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('service_type')
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

                Tables\Columns\TextColumn::make('driver_rating')
                    ->label('Đánh giá')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => str_repeat('⭐', (int) $state))
                    ->color(fn ($state) => $state <= 2 ? 'danger' : ($state >= 4 ? 'success' : 'warning')),

                Tables\Columns\TextColumn::make('driver_rating_note')
                    ->label('Nhận xét')
                    ->limit(60)
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Ngày đánh giá')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('driver_rating')
                    ->label('Số sao')
                    ->options([
                        '5' => '⭐⭐⭐⭐⭐ 5 sao',
                        '4' => '⭐⭐⭐⭐ 4 sao',
                        '3' => '⭐⭐⭐ 3 sao',
                        '2' => '⭐⭐ 2 sao',
                        '1' => '⭐ 1 sao',
                    ]),

                SelectFilter::make('service_type')
                    ->label('Dịch vụ')
                    ->options([
                        'delivery' => 'Lấy hộ',
                        'shopping' => 'Mua hộ',
                        'topup'    => 'Nạp tiền',
                        'bike'     => 'Xe ôm',
                        'motor'    => 'Lái hộ xe máy',
                        'car'      => 'Lái hộ ô tô',
                    ]),

                Filter::make('date_range')
                    ->label('Khoảng thời gian')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('to')->label('Đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q) => $q->whereDate('completed_at', '>=', $data['from']))
                        ->when($data['to'],   fn ($q) => $q->whereDate('completed_at', '<=', $data['to']))
                    ),

                Filter::make('low_rating')
                    ->label('Đánh giá thấp (≤ 2 sao)')
                    ->query(fn (Builder $q) => $q->where('driver_rating', '<=', 2))
                    ->toggle(),

                Filter::make('has_note')
                    ->label('Có nhận xét')
                    ->query(fn (Builder $q) => $q->whereNotNull('driver_rating_note')->where('driver_rating_note', '!=', ''))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),

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
            ->bulkActions([
                Tables\Actions\BulkAction::make('delete_ratings')
                    ->label('Xóa đánh giá đã chọn')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update([
                        'driver_rating'      => null,
                        'driver_rating_note' => null,
                    ]))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRatings::route('/'),
            'view'  => Pages\ViewDriverRating::route('/{record}'),
        ];
    }
}
