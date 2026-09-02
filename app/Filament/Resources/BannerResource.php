<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BannerResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Banner;

class BannerResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    // city_id = null nghĩa là hiển thị ở mọi khu vực — vẫn phải hiện ở mọi tenant.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->where(fn ($q) => $q->where('city_id', $tenant?->id)->orWhereNull('city_id'));
    }

    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Quản lý Banner';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Hình ảnh')
                    ->description('Tải ảnh ngang tối ưu cho khu vực banner trên ứng dụng')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Ảnh banner')
                            ->image()
                            ->disk('public')
                            ->directory('banners')
                            ->imagePreviewHeight('200')
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('JPG, PNG hoặc WebP. Tối đa 5MB. Khuyến nghị tỷ lệ 16:5 (ví dụ 800×250px).')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Thông tin')
                    ->description('Nội dung, phạm vi và thứ tự hiển thị của banner')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Tiêu đề')
                            ->maxLength(255)
                            ->placeholder('Ví dụ: Khuyến mãi tháng 6')
                            ->required(),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực áp dụng')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Tất cả khu vực'),

                        Forms\Components\TextInput::make('link_url')
                            ->label('Đường dẫn khi nhấn (tuỳ chọn)')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://...')
                            ->helperText('Để trống nếu banner không dẫn đến trang nào'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Thứ tự hiển thị')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Số nhỏ hơn hiển thị trước. Mặc định 0.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Hiển thị trên app')
                            ->default(true)
                            ->onColor('success'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Ảnh')
                    ->disk('public')
                    ->height(56)
                    ->width(180)
                    ->extraImgAttributes(['style' => 'object-fit:cover;border-radius:8px']),

                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->weight('semibold')
                    ->wrap(),

                Tables\Columns\TextColumn::make('link_url')
                    ->label('Link')
                    ->placeholder('—')
                    ->limit(40)
                    ->url(fn (Banner $record) => $record->link_url, true)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Khu vực')
                    ->placeholder('Tất cả khu vực')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hiển thị')
                    ->falseLabel('Đã ẩn'),
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
            ->recordAction('edit')
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
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
