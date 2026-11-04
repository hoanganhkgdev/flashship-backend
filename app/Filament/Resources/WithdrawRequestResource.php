<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawRequestResource\Pages;
use App\Filament\Traits\RestrictToFinance;
use App\Services\WithdrawService;
use App\Support\AdminAccess;
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

    // WithdrawRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id (không có tenant thì không thấy gì).
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', (int) $tenant?->id));
    }

    /** Duyệt / từ chối là thao tác chuyển tiền: cần quyền quản lý tài chính, không chỉ quyền xem. */
    public static function canManage(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::FINANCE_MANAGE);
    }

    /** Nhãn trạng thái lệnh PayOS đang dở (null nếu không có). */
    public static function payoutStateLabel(WithdrawRequest $r): ?string
    {
        return $r->status === 'pending' ? match ($r->payout_state) {
            'sending' => 'Đang gửi PayOS',
            'unknown' => 'PayOS chưa rõ kết quả',
            'processing' => 'PayOS đang chuyển',
            'failed' => 'PayOS không thực hiện',
            default => null,
        } : null;
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
            // PayOS đang chuyển thì chờ kết quả (nút Kiểm tra PayOS), không duyệt thêm lần nữa.
            ->visible(fn (WithdrawRequest $r) => self::canManage() && $r->status === 'pending' && $r->payout_state !== 'processing')
            ->modalHeading('Duyệt yêu cầu rút tiền')
            ->modalDescription(function (WithdrawRequest $r) {
                $bank = $r->bank_name ? $r->bank_name.' · '.$r->account_number.' · '.$r->account_name : 'chưa có bản lưu ngân hàng';
                $balance = WithdrawService::payosBalance();

                return 'Chuyển '.self::money($r->amount).' cho '.$r->driver?->name.' → '.$bank.'. Ví hiện tại '.self::money($r->driver?->wallet?->balance ?? 0).'.'
                    .($balance !== null ? ' Tài khoản chi PayOS còn '.self::money($balance).'.' : '')
                    .(self::nameMismatch($r) ? ' ⚠ Tên chủ tài khoản KHÔNG khớp tên tài xế, hãy kiểm tra kỹ.' : '')
                    .(($at = \App\Services\DriverBankService::changedBefore($r)) ? ' ⚠ Số tài khoản vừa đổi lúc '.$at->format('H:i d/m').', hãy gọi xác nhận với tài xế.' : '')
                    .(WithdrawService::payosInFlight($r) ? ' ⚠ Lần gửi PayOS trước chưa rõ kết quả: chỉ được gửi lại qua PayOS (cùng mã lệnh, không chuyển hai lần).' : '');
            })
            ->form(function (WithdrawRequest $r) {
                $balance = WithdrawService::payosBalance();
                $enough = $balance === null || $balance >= (int) round($r->amount);
                $canPayos = WithdrawService::payosConfigured() && $r->bank_code && $r->account_number && (int) $r->driver?->status === 1 && $enough;
                // Lệnh PayOS trước còn dở: chỉ được gửi lại PayOS, không được chuyển theo cách khác.
                $options = WithdrawService::payosInFlight($r)
                    ? ['payos' => 'Gửi lại qua PayOS (cùng mã lệnh)']
                    : ($canPayos ? ['payos' => 'Chuyển tự động qua PayOS'] : []) + [
                        'manual' => 'Tôi đã tự chuyển khoản (nhập mã giao dịch)',
                        'cash' => 'Đã trả tiền mặt / trả ngoài hệ thống',
                    ];

                return [
                    Forms\Components\Radio::make('method')->label('Cách chuyển tiền')->options($options)->default(array_key_first($options))->required()->live()
                        ->helperText(! $enough ? 'Tài khoản chi PayOS không đủ '.self::money($r->amount).' nên không chuyển tự động được.' : null),
                    Forms\Components\TextInput::make('tx_ref')->label('Mã giao dịch ngân hàng')->maxLength(100)
                        ->visible(fn (Forms\Get $get) => $get('method') === 'manual')
                        ->required(fn (Forms\Get $get) => $get('method') === 'manual'),
                    Forms\Components\Textarea::make('note')->rows(2)->maxLength(300)
                        ->label(fn (Forms\Get $get) => $get('method') === 'cash' ? 'Ai trả, trả lúc nào' : 'Ghi chú (tuỳ chọn)')
                        ->placeholder(fn (Forms\Get $get) => $get('method') === 'cash' ? 'VD: Nam trả tiền mặt tại văn phòng 18/10' : null)
                        ->required(fn (Forms\Get $get) => $get('method') === 'cash')
                        ->minLength(fn (Forms\Get $get) => $get('method') === 'cash' ? 5 : null),
                ];
            })
            ->action(function (WithdrawRequest $record, array $data): void {
                $res = match ($data['method']) {
                    'payos' => WithdrawService::approvePayos($record, $data['note'] ?? null, Auth::id()),
                    'cash' => WithdrawService::approveCash($record, (string) ($data['note'] ?? ''), Auth::id()),
                    default => WithdrawService::approveManual($record, (string) ($data['tx_ref'] ?? ''), $data['note'] ?? null, Auth::id()),
                };
                Notification::make()->title($res['message'])->{$res['ok'] ? (($res['processing'] ?? false) ? 'info' : 'success') : 'danger'}()->send();
            });
    }

    public static function syncAction($action)
    {
        return $action->label('Kiểm tra PayOS')->icon('heroicon-o-arrow-path')->color('info')
            ->visible(fn (WithdrawRequest $r) => self::canManage() && WithdrawService::payosInFlight($r))
            ->action(function (WithdrawRequest $record): void {
                $res = WithdrawService::syncPayos($record, Auth::id());
                Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'warning'}()->send();
            });
    }

    public static function rejectAction($action)
    {
        return $action->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
            // Lệnh PayOS còn dở thì không hoàn tiền: PayOS có thể đã chuyển.
            ->visible(fn (WithdrawRequest $r) => self::canManage() && $r->status === 'pending' && ! WithdrawService::payosInFlight($r))
            ->modalHeading('Từ chối yêu cầu rút tiền')
            ->modalDescription(fn (WithdrawRequest $r) => self::money($r->amount).' đang giữ sẽ được hoàn vào ví '.$r->driver?->name.'. Lý do được gửi đến tài xế. Nếu tài xế ĐÃ được trả (chuyển khoản hoặc tiền mặt), hãy chọn "Duyệt", đừng từ chối.')
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
                    ->description(fn (WithdrawRequest $r) => collect([
                        $r->account_number,
                        $r->account_name ? ($r->account_name.(self::nameMismatch($r) ? ' ⚠ tên lệch' : '')) : null,
                        $r->status === 'pending' && \App\Services\DriverBankService::changedBefore($r) ? '⚠ STK mới đổi' : null,
                    ])->filter()->join(' · '))
                    ->color(fn (WithdrawRequest $r) => self::nameMismatch($r) || ($r->status === 'pending' && \App\Services\DriverBankService::changedBefore($r)) ? 'warning' : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối'][$state] ?? $state)
                    ->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray')
                    ->description(fn (WithdrawRequest $r) => self::payoutStateLabel($r)
                        ?? ($r->status === 'approved' ? ['payos' => 'PayOS', 'manual' => 'Chuyển khoản tay', 'cash' => 'Tiền mặt'][$r->payout_method] ?? null : null)),

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
                    self::syncAction(Tables\Actions\Action::make('sync_payos')),
                    self::rejectAction(Tables\Actions\Action::make('reject')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordClasses(fn (WithdrawRequest $r) => match (true) {
                WithdrawService::payosInFlight($r) => 'fs-wd-row--payos',
                self::isStale($r) => 'fs-wd-row--stale',
                $r->status !== 'pending' => 'fs-wd-row--done',
                default => null,
            })
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
