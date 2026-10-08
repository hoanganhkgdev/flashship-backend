<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawRequestResource\Pages;
use App\Filament\Traits\RestrictToFinance;
use App\Services\WithdrawService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\Driver\Models\WithdrawRequest;

class WithdrawRequestResource extends Resource
{
    use RestrictToFinance;

    // WithdrawRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = WithdrawRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Yêu cầu rút tiền';

    protected static ?string $modelLabel = 'Yêu cầu rút tiền';

    protected static ?string $pluralModelLabel = 'Yêu cầu rút tiền';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), Filament::getTenant())
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.city', 'driver.wallet', 'processor']);
    }

    private static function money($v): string
    {
        return number_format((float) $v, 0, ',', '.').'₫';
    }

    /** Đã chờ bao lâu (chỉ với yêu cầu chưa xử lý). */
    public static function waitLabel(WithdrawRequest $r): string
    {
        $h = (int) $r->created_at->diffInHours(now());

        return 'Đã chờ '.($h >= 48 ? intdiv($h, 24).' ngày' : ($h >= 1 ? $h.' giờ' : 'dưới 1 giờ'));
    }

    public static function isStale(WithdrawRequest $r): bool
    {
        return $r->status === 'pending' && $r->created_at->diffInHours(now()) >= WithdrawService::STALE_HOURS;
    }

    public static function nameMismatch(WithdrawRequest $r): bool
    {
        return $r->account_name && ! WithdrawService::nameMatches($r->account_name, $r->driver?->name);
    }

    // ─── Thao tác dùng chung cho bảng và trang chi tiết ──────────────────────────

    public static function approveAction($action)
    {
        return $action->label('Duyệt')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (WithdrawRequest $r) => $r->status === 'pending')
            ->modalHeading('Duyệt yêu cầu rút tiền')
            ->modalDescription(function (WithdrawRequest $r) {
                $bank = $r->bank_name ? $r->bank_name.' · '.$r->account_number.' · '.$r->account_name : 'chưa có bản lưu ngân hàng';

                return 'Chuyển '.self::money($r->amount).' cho '.$r->driver?->name.' → '.$bank.'. Ví hiện tại '.self::money($r->driver?->wallet?->balance ?? 0).'.'
                    .(self::nameMismatch($r) ? ' ⚠ Tên chủ tài khoản KHÔNG khớp tên tài xế, hãy kiểm tra kỹ.' : '');
            })
            ->form(function (WithdrawRequest $r) {
                $canPayos = WithdrawService::payosConfigured() && $r->bank_code && $r->account_number && (int) $r->driver?->status === 1;
                $options = ['manual' => 'Tôi đã tự chuyển khoản (nhập mã giao dịch)'] + ($canPayos ? ['payos' => 'Chuyển tự động qua PayOS'] : []);

                return [
                    Forms\Components\Radio::make('method')->label('Cách chuyển tiền')->options($options)->default($canPayos ? 'payos' : 'manual')->required()->live(),
                    Forms\Components\TextInput::make('tx_ref')->label('Mã giao dịch ngân hàng')->maxLength(100)
                        ->visible(fn (Forms\Get $get) => $get('method') === 'manual')
                        ->required(fn (Forms\Get $get) => $get('method') === 'manual'),
                    Forms\Components\Textarea::make('note')->label('Ghi chú (tuỳ chọn)')->rows(2)->maxLength(300),
                ];
            })
            ->action(function (WithdrawRequest $record, array $data): void {
                $res = $data['method'] === 'payos'
                    ? WithdrawService::approvePayos($record, $data['note'] ?? null, Auth::id())
                    : WithdrawService::approveManual($record, (string) ($data['tx_ref'] ?? ''), $data['note'] ?? null, Auth::id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    public static function rejectAction($action)
    {
        return $action->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (WithdrawRequest $r) => $r->status === 'pending')
            ->modalHeading('Từ chối yêu cầu rút tiền')
            ->modalDescription(fn (WithdrawRequest $r) => self::money($r->amount).' đang giữ sẽ được hoàn vào ví '.$r->driver?->name.'. Lý do được gửi đến tài xế. Nếu bạn ĐÃ chuyển khoản, hãy chọn "Duyệt", đừng từ chối.')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do')->options(WithdrawService::REJECT_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Giải thích thêm')->rows(2)->maxLength(300)
                    ->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(5),
            ])
            ->action(function (WithdrawRequest $record, array $data): void {
                $res = WithdrawService::reject($record, $data['reason'], $data['note'] ?? null, Auth::id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (WithdrawRequest $r) => $r->driver?->phone),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => self::money($state))
                    ->color(fn (WithdrawRequest $r) => $r->status === 'pending' ? 'warning' : null)
                    ->description(fn (WithdrawRequest $r) => 'Ví '.self::money($r->driver?->wallet?->balance ?? 0))
                    ->sortable(),

                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Tài khoản nhận')
                    ->placeholder('Chưa có ngân hàng')
                    ->searchable(['bank_name', 'account_number', 'account_name'])
                    ->description(fn (WithdrawRequest $r) => collect([$r->account_number, $r->account_name ? ($r->account_name.(self::nameMismatch($r) ? ' ⚠ tên lệch' : '')) : null])->filter()->join(' · '))
                    ->color(fn (WithdrawRequest $r) => self::nameMismatch($r) ? 'warning' : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối'][$state] ?? $state)
                    ->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->description(fn (WithdrawRequest $r) => $r->status === 'pending'
                        ? self::waitLabel($r)
                        : collect([$r->processor?->name, $r->processed_at?->format('d/m H:i')])->filter()->join(' · '))
                    ->color(fn (WithdrawRequest $r) => self::isStale($r) ? 'danger' : null),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Ghi chú / lý do')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (WithdrawRequest $r) => $r->admin_note)
                    ->description(fn (WithdrawRequest $r) => $r->payout_reference ? 'Mã GD '.$r->payout_reference : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối']),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
                    self::approveAction(Tables\Actions\Action::make('approve')),
                    self::rejectAction(Tables\Actions\Action::make('reject')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordUrl(fn (WithdrawRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
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
            'index' => Pages\ListWithdrawRequests::route('/'),
            'view' => Pages\ViewWithdrawRequest::route('/{record}'),
        ];
    }
}
