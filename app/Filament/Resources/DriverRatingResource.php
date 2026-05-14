<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRatingResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;

class DriverRatingResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon  = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'Đơn hàng';
    protected static ?string $modelLabel      = 'Đánh giá tài xế';
    protected static ?string $pluralModelLabel = 'Đánh giá tài xế';
    protected static ?string $slug            = 'driver-ratings';
    protected static ?int    $navigationSort  = 3;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereNotNull('driver_rating')
            ->where('driver_rating', '<=', 2)
            ->count() ?: null;
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
        return $form->schema([
            Forms\Components\Section::make('Thông tin đơn hàng')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Mã đơn')
                        ->disabled(),

                    Forms\Components\TextInput::make('service_type')
                        ->label('Dịch vụ')
                        ->disabled(),

                    Forms\Components\TextInput::make('driver.name')
                        ->label('Tài xế')
                        ->disabled(),

                    Forms\Components\TextInput::make('sender.name')
                        ->label('Khách hàng')
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Đánh giá')
                ->schema([
                    Forms\Components\TextInput::make('driver_rating')
                        ->label('Số sao')
                        ->disabled()
                        ->suffix('/ 5'),

                    Forms\Components\Textarea::make('driver_rating_note')
                        ->label('Nhận xét')
                        ->disabled()
                        ->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã đơn')
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
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'delivery' => 'Lấy hộ',
                        'shopping' => 'Mua hộ',
                        'topup'    => 'Nạp tiền',
                        'bike'     => 'Xe ôm (xe máy)',
                        'motor'    => 'Xe ôm (mô tô)',
                        'car'      => 'Xe hơi',
                        default    => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'delivery' => 'warning',
                        'shopping' => 'primary',
                        'topup'    => 'success',
                        'bike', 'motor' => 'info',
                        'car'      => 'danger',
                        default    => 'gray',
                    }),

                Tables\Columns\ViewColumn::make('driver_rating')
                    ->label('Đánh giá')
                    ->view('filament.tables.columns.star-rating'),

                Tables\Columns\TextColumn::make('driver_rating_note')
                    ->label('Nhận xét')
                    ->limit(60)
                    ->placeholder('Không có nhận xét')
                    ->wrap(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Ngày đánh giá')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('completed_at', 'desc')
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
                        'bike'     => 'Xe ôm (xe máy)',
                        'motor'    => 'Xe ôm (mô tô)',
                        'car'      => 'Xe hơi',
                    ]),

                Filter::make('date_range')
                    ->label('Khoảng thời gian')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('to')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('completed_at', '>=', $data['from']))
                            ->when($data['to'],   fn ($q) => $q->whereDate('completed_at', '<=', $data['to']));
                    }),

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
                Tables\Actions\ViewAction::make()->label('Xem'),
            ])
            ->bulkActions([])
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
