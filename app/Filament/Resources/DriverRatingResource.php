<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRatingResource\Pages;
use App\Services\DriverRatingService;
use App\Support\AdminAccess;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

class DriverRatingResource extends Resource
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Đánh giá tài xế';

    protected static ?string $modelLabel = 'Đánh giá tài xế';

    protected static ?string $pluralModelLabel = 'Đánh giá tài xế';

    protected static ?string $slug = 'driver-ratings';

    protected static ?int $navigationSort = 3;

    /** Badge chỉ đếm đánh giá 1–2 sao CHƯA xử lý của khu vực đang chọn. */
    public static function getNavigationBadge(): ?string
    {
        $count = DriverRatingService::pendingCount(Filament::getTenant()?->id);

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
            ->with(['driver', 'sender']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function serviceLabels(): array
    {
        static $cache = null;

        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    public static function stars(int $rating): string
    {
        $color = match (true) {
            $rating <= 2 => '#ef4444',
            $rating === 3 => '#f59e0b',
            default => '#22c55e',
        };

        return '<span style="font-size:1rem;letter-spacing:1px">'
            .str_repeat('<span style="color:'.$color.'">★</span>', $rating)
            .str_repeat('<span style="color:#94a3b8;opacity:.45">★</span>', 5 - $rating).'</span>';
    }

    /** Nguồn đánh giá: tài khoản gửi đơn là shop hay khách. */
    public static function sourceLabel(Order $o): string
    {
        return match ($o->sender?->user_type) {
            'shop' => 'Shop',
            'customer' => 'Khách',
            default => 'Không rõ',
        };
    }

    public static function statusLabel(Order $o): array
    {
        return match (true) {
            (bool) $o->rating_hidden => ['Đã ẩn', 'gray'],
            (bool) $o->rating_handled_at => ['Đã xử lý', 'success'],
            DriverRatingService::needsAction($o) => ['Cần xử lý', 'danger'],
            default => ['—', 'gray'],
        };
    }

    // ─── Thao tác dùng chung cho bảng và trang chi tiết ──────────────────────────

    public static function handleAction($action)
    {
        return $action->label('Đã xử lý')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (Order $r) => DriverRatingService::needsAction($r))
            ->modalHeading('Đánh dấu đã xử lý')
            ->modalDescription('Ghi lại bạn đã làm gì (gọi tài xế, nhắc nhở, khiếu nại không đúng...).')
            ->form([Forms\Components\Textarea::make('note')->label('Ghi chú xử lý')->required()->minLength(3)->maxLength(500)->rows(2)])
            ->action(function (Order $record, array $data): void {
                DriverRatingService::handle($record, $data['note'], auth()->id())
                    ? Notification::make()->success()->title('Đã đánh dấu xử lý')->send()
                    : Notification::make()->warning()->title('Đánh giá này đã được xử lý hoặc đã bị ẩn')->send();
            });
    }

    public static function hideAction($action)
    {
        return $action->label('Ẩn khỏi thống kê')->icon('heroicon-o-eye-slash')->color('gray')
            ->visible(fn (Order $r) => ! $r->rating_hidden)
            ->modalHeading('Ẩn đánh giá khỏi thống kê')
            ->modalDescription('Đánh giá không tính vào điểm trung bình (trang này và app tài xế) nhưng nội dung và phần xử lý vẫn được giữ lại. Ai ẩn và vì sao được ghi vào nhật ký.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do ẩn')->required()->minLength(3)->maxLength(500)->rows(2)])
            ->action(function (Order $record, array $data): void {
                DriverRatingService::hide($record, $data['reason'], auth()->id())
                    ? Notification::make()->success()->title('Đã ẩn đánh giá khỏi thống kê')->send()
                    : Notification::make()->warning()->title('Đánh giá này đã bị ẩn trước đó')->send();
            });
    }

    public static function unhideAction($action)
    {
        return $action->label('Hiện lại')->icon('heroicon-o-eye')->color('info')
            ->visible(fn (Order $r) => (bool) $r->rating_hidden)
            ->requiresConfirmation()
            ->modalDescription('Đánh giá được tính lại vào điểm trung bình của tài xế. Việc hiện lại được ghi vào nhật ký.')
            ->action(function (Order $record): void {
                DriverRatingService::unhide($record, auth()->id());
                Notification::make()->success()->title('Đã tính lại đánh giá vào thống kê')->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver_rating')
                    ->label('Đánh giá')
                    ->formatStateUsing(fn ($state) => self::stars((int) $state))
                    ->html()
                    ->sortable(),

                Tables\Columns\TextColumn::make('driver_rating_note')
                    ->label('Nhận xét')
                    ->placeholder('Không có nhận xét')
                    ->limit(32)->wrap(false)
                    ->tooltip(fn (Order $r) => $r->driver_rating_note)
                    ->searchable(),

                // Tài xế đã bị xóa: hiện tên đã lưu lúc xóa (orders.driver_name), xóa trước khi có bản lưu thì ghi rõ.
                Tables\Columns\TextColumn::make('driver_label')
                    ->label('Tài xế')
                    ->state(fn (Order $r) => DriverRatingService::driverLabel($r)['name'])
                    ->color(fn (Order $r) => DriverRatingService::driverLabel($r)['deleted'] ? 'gray' : null)
                    ->description(fn (Order $r): ?string => ($l = DriverRatingService::driverLabel($r))['deleted']
                        ? trim(($l['phone'] ?: '').' · đã xóa tài khoản', ' ·')
                        : $l['phone'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->whereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->orWhere('driver_name', 'like', "%{$search}%")->orWhere('driver_phone', 'like', "%{$search}%"))),

                Tables\Columns\TextColumn::make('code')
                    ->label('Đơn')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->description(fn (Order $r): string => Str::limit(self::serviceLabels()[$r->service_type] ?? (string) $r->service_type, 14))
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Nguồn')
                    ->state(fn (Order $r) => self::sourceLabel($r))
                    ->description(fn (Order $r): ?string => Str::limit($r->sender?->name ?: $r->sender_name, 16)),

                Tables\Columns\TextColumn::make('rated_at')
                    ->label('Đánh giá lúc')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('handling')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Order $r) => self::statusLabel($r)[0])
                    ->color(fn (Order $r) => self::statusLabel($r)[1]),
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
                Filter::make('rated_at')
                    ->label('Ngày đánh giá')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('rated_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('rated_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
                    self::handleAction(Tables\Actions\Action::make('handle')),
                    self::hideAction(Tables\Actions\Action::make('hide')),
                    self::unhideAction(Tables\Actions\Action::make('unhide')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->bulkActions([])
            ->recordClasses(fn (Order $r) => match (true) {
                DriverRatingService::needsAction($r) => 'fs-rate-row--pending',
                (bool) $r->rating_hidden => 'fs-rate-row--hidden',
                default => null,
            })
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('rated_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRatings::route('/'),
            'view' => Pages\ViewDriverRating::route('/{record}'),
        ];
    }
}
