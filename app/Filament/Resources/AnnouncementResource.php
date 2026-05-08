<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Core\Models\Announcement;
use Modules\Core\Models\City;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $modelLabel = 'Thông báo';

    protected static ?string $pluralModelLabel = 'Thông báo';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Tiêu đề')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('content')
                ->label('Nội dung')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Select::make('target')
                ->label('Đối tượng')
                ->options([
                    'all'      => 'Tất cả',
                    'driver'   => 'Tài xế',
                    'customer' => 'Khách hàng',
                    'shop'     => 'Cửa hàng',
                ])
                ->default('driver')
                ->required(),

            Forms\Components\Select::make('city_id')
                ->label('Khu vực')
                ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                ->placeholder('Tất cả khu vực')
                ->nullable(),

            Forms\Components\Select::make('type')
                ->label('Loại')
                ->options([
                    'info'    => 'Thông tin',
                    'warning' => 'Cảnh báo',
                    'success' => 'Tích cực',
                ])
                ->default('info')
                ->required(),

            Forms\Components\Toggle::make('is_active')
                ->label('Hiển thị')
                ->default(true),

            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Hết hạn lúc')
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->default('Tất cả'),

                Tables\Columns\BadgeColumn::make('target')
                    ->label('Đối tượng')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'all'      => 'Tất cả',
                        'driver'   => 'Tài xế',
                        'customer' => 'Khách hàng',
                        'shop'     => 'Cửa hàng',
                        default    => $state,
                    })
                    ->colors([
                        'gray'    => 'all',
                        'primary' => 'driver',
                        'success' => 'customer',
                        'info'    => 'shop',
                    ]),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Loại')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'info'    => 'Thông tin',
                        'warning' => 'Cảnh báo',
                        'success' => 'Tích cực',
                        default   => $state,
                    })
                    ->colors([
                        'primary' => 'info',
                        'warning' => 'warning',
                        'success' => 'success',
                    ]),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Hiển thị')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit'   => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
