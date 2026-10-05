<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportConfigResource\Pages;
use App\Filament\Traits\HideFromCityManager;
use App\Services\CatalogService;
use App\Services\SupportChannelService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\SupportConfig;
use Modules\Core\Models\City;

class SupportConfigResource extends Resource
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter() && static::canViewAny();
    }

    use HideFromCityManager;

    // city_id = null nghĩa là áp dụng cho mọi khu vực — vẫn phải hiện ở mọi tenant.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->where(fn ($q) => $q->where('city_id', $tenant?->id)->orWhereNull('city_id'));
    }

    protected static ?string $model = SupportConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Khách hàng';

    protected static ?string $navigationLabel = 'Cấu hình hỗ trợ';

    protected static ?string $modelLabel = 'Hỗ trợ';

    protected static ?string $pluralModelLabel = 'Cấu hình hỗ trợ';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin nút hỗ trợ')
                ->description('Kênh liên hệ hiển thị trong mục Hỗ trợ của ứng dụng')
                ->icon('heroicon-o-lifebuoy')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Tiêu đề')->required()->maxLength(100)->placeholder('VD: Hotline hỗ trợ'),
                    Forms\Components\TextInput::make('subtitle')->label('Phụ đề')->maxLength(150)->placeholder('VD: 7:00 – 22:00 hằng ngày'),

                    Forms\Components\Select::make('type')->label('Loại liên kết')->options(SupportChannelService::TYPES)->required()->live(),

                    Forms\Components\TextInput::make('value')
                        ->label('Giá trị / URL')
                        ->required()
                        ->maxLength(255)
                        ->helperText(fn (Forms\Get $get) => match ($get('type')) {
                            'phone' => 'Số điện thoại, ví dụ 0901234567 hoặc +84901234567',
                            'zalo' => 'Số điện thoại Zalo hoặc đường dẫn https://zalo.me/...',
                            'facebook' => 'Tên trang (flashship) hoặc đường dẫn facebook.com/...',
                            'website' => 'Đường dẫn website, ví dụ https://flashship.vn',
                            'email' => 'Địa chỉ email, ví dụ support@flashship.vn',
                            default => 'Đường dẫn hoặc nội dung hiển thị',
                        })
                        ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            [, $error] = SupportChannelService::normalize((string) $get('type'), (string) $value);
                            if ($error) {
                                $fail($error);
                            }
                        })
                        // Lưu giá trị đã chuẩn hóa để app luôn mở được (0xxxxxxxxx, https://...).
                        ->dehydrateStateUsing(fn ($state, Forms\Get $get) => SupportChannelService::normalize((string) $get('type'), (string) $state)[0] ?? $state),

                    Forms\Components\Select::make('audience')->label('Hiển thị cho')->options(SupportChannelService::AUDIENCES)->default('all')->required()
                        ->helperText('Chọn một nhóm nếu kênh chỉ dành riêng cho nhóm đó (ví dụ nhóm Zalo tài xế).'),

                    Forms\Components\Select::make('city_id')
                        ->label('Khu vực áp dụng')
                        ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->placeholder('Tất cả khu vực'),

                    Forms\Components\Toggle::make('is_active')->label('Hiển thị trong app')->default(true)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Nút trong app')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (SupportConfig $c) => $c->subtitle ?: 'Không có phụ đề'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SupportChannelService::TYPES[$state] ?? $state)
                    ->color('info'),

                Tables\Columns\TextColumn::make('value')
                    ->label('Giá trị')
                    ->limit(36)
                    ->copyable()
                    ->description(fn (SupportConfig $c) => ($l = SupportChannelService::link($c->type, (string) $c->value)) ? 'Mở: '.\Illuminate\Support\Str::limit($l, 36) : 'Không mở được bằng 1 chạm'),

                Tables\Columns\TextColumn::make('audience')
                    ->label('Dành cho')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SupportChannelService::AUDIENCES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'all' ? 'gray' : 'primary'),

                Tables\Columns\TextColumn::make('city.name')->label('Khu vực')->default('Tất cả'),

                Tables\Columns\TextColumn::make('flags')
                    ->label('Lưu ý')
                    ->html()
                    ->wrap()
                    ->extraAttributes(['style' => 'white-space:normal;max-width:14rem'])
                    ->state(fn (SupportConfig $c) => collect(SupportChannelService::flags($c, self::all()))
                        ->map(fn ($f) => '<span class="fs-order-pill fs-order-pill--'.$f['level'].'">'.e($f['label']).'</span>')->implode(' ') ?: '—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (SupportConfig $c) => $c->is_active ? 'Đang hiển thị' : 'Đã ẩn')
                    ->color(fn (SupportConfig $c) => $c->is_active ? 'success' : 'gray'),
            ])
            ->defaultSort('priority')
            ->reorderable('priority')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    self::toggleAction(Tables\Actions\Action::make('toggle')),
                    Tables\Actions\DeleteAction::make()->label('Xóa kênh')
                        ->after(fn (SupportConfig $c) => CatalogService::log('support_config', $c->id, 'deleted', auth()->id(), $c->title.' ('.$c->value.')')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordAction('edit')
            ->paginated(false);
    }

    private static function all()
    {
        static $all = null;

        return $all ??= SupportConfig::all();
    }

    public static function toggleAction($action)
    {
        return $action
            ->label(fn (SupportConfig $c) => $c->is_active ? 'Ẩn khỏi app' : 'Hiện trong app')
            ->icon(fn (SupportConfig $c) => $c->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (SupportConfig $c) => $c->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (SupportConfig $c) => ($c->is_active ? 'Ẩn ' : 'Hiện ').$c->title)
            ->modalDescription(fn (SupportConfig $c) => $c->is_active
                ? 'Kênh sẽ biến mất khỏi mục Hỗ trợ của '.mb_strtolower(SupportChannelService::AUDIENCES[$c->audience] ?? '').'.'
                : 'Kênh sẽ hiện lại trong mục Hỗ trợ.')
            ->action(function (SupportConfig $record): void {
                $record->update(['is_active' => ! $record->is_active]);
                CatalogService::log('support_config', $record->id, $record->is_active ? 'activated' : 'deactivated', auth()->id(), $record->title);
                Notification::make()->success()->title($record->is_active ? 'Đã hiện kênh' : 'Đã ẩn kênh')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportConfigs::route('/'),
            'create' => Pages\CreateSupportConfig::route('/create'),
            'edit' => Pages\EditSupportConfig::route('/{record}/edit'),
        ];
    }
}
