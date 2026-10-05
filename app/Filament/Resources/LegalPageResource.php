<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LegalPageResource\Pages;
use App\Filament\Resources\LegalPageResource\RelationManagers;
use App\Filament\Traits\HideFromCityManager;
use App\Services\LegalPageService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Admin\Models\Page;

class LegalPageResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    // Trang pháp lý dùng chung toàn hệ thống, không theo khu vực.
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Trang pháp lý';

    protected static ?string $modelLabel = 'Trang pháp lý';

    protected static ?string $pluralModelLabel = 'Trang pháp lý';

    protected static ?string $slug = 'legal-pages';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nội dung trang')
                ->description('Nội dung pháp lý dùng chung cho toàn bộ khu vực và ứng dụng')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required(),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->helperText('Không đổi được: các app và trang web truy cập nội dung theo slug này.')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Hiển thị')
                        ->default(true),

                    Forms\Components\RichEditor::make('content')
                        ->label('Nội dung')
                        ->columnSpanFull()
                        ->required(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Trang')->searchable()->weight('semibold')
                    ->description(fn (Page $p) => 'Slug: '.$p->slug),

                Tables\Columns\TextColumn::make('where')
                    ->label('Hiển thị ở')
                    ->state(fn (Page $p) => 'App khách, tài xế, cửa hàng'.(LegalPageService::webPathFor($p->slug) ? ' · web '.url(LegalPageService::webPathFor($p->slug)) : '')),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (Page $p) => LegalPageService::isStale($p) ? 'Đã hơn '.LegalPageService::STALE_DAYS.' ngày chưa rà soát' : null)
                    ->color(fn (Page $p) => LegalPageService::isStale($p) ? 'warning' : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Page $p) => $p->is_active ? 'Đang hiển thị' : 'Đã ẩn')
                    ->color(fn (Page $p) => $p->is_active ? 'success' : 'gray'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa & lịch sử'),
                    Tables\Actions\Action::make('web')->label('Xem trên web')->icon('heroicon-o-arrow-top-right-on-square')
                        ->visible(fn (Page $p) => (bool) LegalPageService::webPathFor($p->slug))
                        ->url(fn (Page $p) => url(LegalPageService::webPathFor($p->slug)))->openUrlInNewTab(),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordAction('edit')
            ->defaultSort('slug');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalPages::route('/'),
            'edit' => Pages\EditLegalPage::route('/{record}/edit'),
        ];
    }
}
