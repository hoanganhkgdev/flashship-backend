<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LegalPageResource\Pages;
use App\Filament\Traits\HideFromCityManager;
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
                        ->helperText('Ví dụ: privacy-policy, terms-of-service')
                        ->required()
                        ->unique(ignoreRecord: true),

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
                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Hiển thị')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Sửa'),
            ])
            ->recordAction('edit')
            ->defaultSort('slug');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalPages::route('/'),
            'edit' => Pages\EditLegalPage::route('/{record}/edit'),
        ];
    }
}
