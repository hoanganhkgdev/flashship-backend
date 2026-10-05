<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BannerResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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

    protected static ?string $navigationGroup = 'Khách hàng';

    protected static ?string $navigationLabel = 'Quản lý banner';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Banner';

    protected static ?int $navigationSort = 3;

    /** Khung banner trong app khách: cao 168px, rộng khoảng 352px trên điện thoại thường => khoảng 21:10. */
    public const RATIO = '21:10';

    public const TARGET_WIDTH = 1260;

    public const TARGET_HEIGHT = 600;

    /** URL ảnh để xem trước: ảnh mới chọn (chưa lưu) hoặc ảnh đã lưu. */
    private static function previewUrl(mixed $state): ?string
    {
        $first = is_array($state) ? reset($state) : $state;
        if ($first instanceof TemporaryUploadedFile) {
            try {
                return $first->temporaryUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        return is_string($first) && $first !== '' ? Storage::disk('public')->url($first) : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Hình ảnh')
                    ->description('Ảnh sẽ được cắt đúng khung banner trên app khách hàng')
                    ->icon('heroicon-o-photo')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Ảnh banner')
                            ->image()
                            ->disk('public')
                            ->directory('banners')
                            ->imageEditor()
                            ->imageEditorAspectRatios([static::RATIO])
                            ->imageCropAspectRatio(static::RATIO)
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth((string) static::TARGET_WIDTH)
                            ->imageResizeTargetHeight((string) static::TARGET_HEIGHT)
                            ->imagePreviewHeight('160')
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('JPG, PNG hoặc WebP, tối đa 5MB. Ảnh được cắt về tỷ lệ '.static::RATIO.' và thu về '.static::TARGET_WIDTH.'×'.static::TARGET_HEIGHT.'px. Nên chừa trống nửa dưới và góc dưới bên phải vì app phủ lớp tối và nút mũi tên ở đó.')
                            ->live()
                            ->required(),

                        Forms\Components\Placeholder::make('preview')
                            ->label('Xem trước trong app')
                            ->content(function (Get $get): HtmlString {
                                $url = static::previewUrl($get('image_path'));
                                $bg = $url ? 'background-image:url('.e($url).')' : '';

                                return new HtmlString(
                                    '<div class="fs-bn-card" style="'.$bg.'">'
                                    .($url ? '' : '<span class="fs-bn-empty">Chưa chọn ảnh</span>')
                                    .'<i class="fs-bn-shade"></i><i class="fs-bn-safe"></i><b class="fs-bn-arrow">→</b></div>'
                                    .'<p class="fs-bn-note">Khung giống app: cao 168px. Vùng tối phía dưới và nút mũi tên sẽ che một phần ảnh.</p>'
                                );
                            }),
                    ]),

                Forms\Components\Section::make('Thông tin')
                    ->description('Nội dung, khu vực, đường dẫn và thời gian hiển thị')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Tiêu đề (chỉ để admin nhận biết)')
                            ->maxLength(255)
                            ->placeholder('Ví dụ: Khuyến mãi tháng 6')
                            ->required(),

                        Forms\Components\Radio::make('scope')
                            ->label('Khu vực hiển thị')
                            ->options(fn (): array => [
                                'city' => 'Chỉ '.(Filament::getTenant()?->name ?? 'khu vực này'),
                                'all' => 'Tất cả khu vực',
                            ])
                            ->default('city')
                            ->required()
                            ->inline(),

                        Forms\Components\TextInput::make('link_url')
                            ->label('Đường dẫn khi nhấn (tuỳ chọn)')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://...')
                            ->helperText('Để trống: bấm banner sẽ mở màn đặt xe trong app. Nhập link web: mở trình duyệt.')
                            ->columnSpanFull(),

                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Bắt đầu hiển thị')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->placeholder('Ngay khi bật')
                            ->helperText('Để trống nếu hiển thị ngay.'),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('Kết thúc hiển thị')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->placeholder('Không hết hạn')
                            ->after('starts_at')
                            ->helperText('Để trống nếu không có hạn.'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Thứ tự hiển thị')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Số nhỏ hơn hiển thị trước. Có thể kéo thả ở danh sách.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Bật hiển thị trên app')
                            ->default(true)
                            ->inline(false)
                            ->onColor('success'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $statusLabels = ['live' => 'Đang hiển thị', 'scheduled' => 'Hẹn giờ', 'expired' => 'Hết hạn', 'inactive' => 'Đã ẩn'];
        $statusColors = ['live' => 'success', 'scheduled' => 'info', 'expired' => 'danger', 'inactive' => 'gray'];
        $placeholder = 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="420" height="200"><rect width="100%" height="100%" fill="#1e293b"/><text x="50%" y="50%" fill="#94a3b8" font-family="sans-serif" font-size="18" text-anchor="middle">Mất ảnh</text></svg>');

        return $table
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\ImageColumn::make('image_path')
                        ->disk('public')
                        ->height('auto')
                        ->width('100%')
                        ->defaultImageUrl($placeholder)
                        ->extraImgAttributes(['style' => 'width:100%;aspect-ratio:21/10;object-fit:cover;border-radius:.85rem']),

                    Tables\Columns\TextColumn::make('title')
                        ->searchable()
                        ->weight('bold')
                        ->limit(40),

                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('status_key')
                            ->badge()
                            ->state(fn (Banner $record): string => $record->statusKey())
                            ->formatStateUsing(fn (string $state): string => $statusLabels[$state])
                            ->color(fn (string $state): string => $statusColors[$state])
                            ->grow(false),
                        Tables\Columns\TextColumn::make('city.name')
                            ->placeholder('Tất cả khu vực')
                            ->badge()
                            ->color('gray')
                            ->grow(false),
                        Tables\Columns\TextColumn::make('sort_order')
                            ->prefix('#')
                            ->color('gray')
                            ->grow(false),
                    ]),

                    Tables\Columns\TextColumn::make('schedule')
                        ->state(function (Banner $record): string {
                            $from = $record->starts_at?->format('d/m/Y H:i');
                            $to = $record->ends_at?->format('d/m/Y H:i');
                            $text = match (true) {
                                $from && $to => "{$from} → {$to}",
                                (bool) $from => "Từ {$from}",
                                (bool) $to => "Đến {$to}",
                                default => 'Không hẹn giờ',
                            };

                            return $record->imageMissing() ? $text.' · ⚠ Mất file ảnh' : $text;
                        })
                        ->color('gray')
                        ->size('sm'),

                    Tables\Columns\TextColumn::make('link_url')
                        ->placeholder('Bấm mở màn đặt xe')
                        ->limit(38)
                        ->color('primary')
                        ->size('sm'),

                    Tables\Columns\ToggleColumn::make('is_active')
                        ->label('Hiển thị'),
                ])->space(2),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang bật')
                    ->falseLabel('Đã ẩn'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                Tables\Actions\DeleteAction::make()->label('Xóa'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->recordAction('edit')
            ->defaultPaginationPageOption(24)
            ->paginationPageOptions([24, 48]);
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
