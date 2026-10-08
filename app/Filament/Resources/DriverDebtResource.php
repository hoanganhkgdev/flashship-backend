<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverDebtResource\Pages;
use App\Filament\Traits\RestrictToFinance;
use App\Services\DriverDebtService;
use Carbon\Carbon;
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
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverDebt;

class DriverDebtResource extends Resource
{
    use RestrictToFinance;

    // DriverDebt không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverDebt::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-minus';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $navigationLabel = 'Công nợ';

    protected static ?string $modelLabel = 'Công nợ';

    protected static ?string $pluralModelLabel = 'Công nợ';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopeEloquentQueryToTenant(static::getEloquentQuery(), Filament::getTenant())
            ->whereIn('status', ['pending', 'overdue'])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.city', 'driver.wallet']);
    }

    /** Form chỉ dùng để tạo khoản nợ tay. Sau khi tạo, mọi thay đổi đi qua các thao tác có nhật ký. */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Khoản nợ mới')
                ->description('Ghi nhận khoản phải thu ngoài phí tuần và phạt điểm tự động.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('driver_id')
                        ->label('Tài xế')
                        ->options(fn () => User::where('user_type', 'driver')->where('status', 1)->where('city_id', Filament::getTenant()?->id)
                            ->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->phone]))
                        ->searchable()->required(),
                    Forms\Components\Select::make('debt_type')->label('Loại')->options(DriverDebtService::TYPES)->default('commission')->required(),
                    Forms\Components\TextInput::make('amount_due')->label('Số tiền nợ')->numeric()->minValue(1000)->required()->suffix('₫'),
                    Forms\Components\Textarea::make('note')->label('Lý do')->required()->minLength(5)->maxLength(300)->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function debtTypeLabel(?string $type): string
    {
        return DriverDebtService::TYPES[$type] ?? 'Công nợ khác';
    }

    public static function statusLabel(string $status): array
    {
        return ['pending' => ['Chờ thanh toán', 'warning'], 'paid' => ['Đã thanh toán', 'success'], 'overdue' => ['Quá hạn', 'danger']][$status] ?? [$status, 'gray'];
    }

    /** Ngày đến hạn: hết kỳ với phí tuần, ngày tạo với khoản khác. */
    public static function dueDate(DriverDebt $d): Carbon
    {
        return Carbon::parse($d->week_end ?? $d->date ?? $d->created_at);
    }

    public static function ageLabel(DriverDebt $d): ?string
    {
        if ($d->status === 'paid') {
            return $d->paid_at ? 'Thu '.$d->paid_at->format('d/m/Y').($d->paid_via ? ' · '.(DriverDebtService::PAID_VIA[$d->paid_via] ?? '') : '') : null;
        }
        $days = (int) self::dueDate($d)->startOfDay()->diffInDays(now()->startOfDay(), false);

        return $d->status === 'overdue' ? 'Quá hạn '.max(0, $days).' ngày' : null;
    }

    private static function money($v): string
    {
        return number_format((float) $v, 0, ',', '.').'₫';
    }

    // ─── Thao tác dùng chung cho bảng và trang chi tiết ──────────────────────────

    private static function open(DriverDebt $d): bool
    {
        return $d->status !== 'paid' && DriverDebtService::remaining($d) > 0;
    }

    private static function notify(array $res): void
    {
        Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
    }

    public static function walletAction($action)
    {
        return $action->label('Thu từ ví')->icon('heroicon-o-credit-card')->color('success')
            ->visible(fn (DriverDebt $d) => self::open($d))
            ->modalHeading('Thu từ ví tài xế')
            ->modalDescription(fn (DriverDebt $d) => $d->driver?->name.': còn nợ '.self::money(DriverDebtService::remaining($d)).', ví có '.self::money($d->driver?->wallet?->balance ?? 0).'. Có thể thu một phần.')
            ->form(fn (DriverDebt $d) => [
                Forms\Components\TextInput::make('amount')->label('Số tiền thu')->numeric()->required()->suffix('₫')->minValue(1)
                    ->default(min(DriverDebtService::remaining($d), (float) ($d->driver?->wallet?->balance ?? 0)) ?: null)
                    ->maxValue(max(1, min(DriverDebtService::remaining($d), (float) ($d->driver?->wallet?->balance ?? 0)))),
            ])
            ->action(fn (DriverDebt $record, array $data) => self::notify(DriverDebtService::payFromWallet($record, (float) $data['amount'], auth()->id())));
    }

    public static function manualAction($action)
    {
        return $action->label('Đã thu ngoài hệ thống')->icon('heroicon-o-banknotes')->color('info')
            ->visible(fn (DriverDebt $d) => self::open($d))
            ->modalHeading('Ghi nhận khoản đã thu ngoài hệ thống')
            ->modalDescription(fn (DriverDebt $d) => 'Chỉ ghi nhận, không động tới ví. Còn nợ '.self::money(DriverDebtService::remaining($d)).'.')
            ->form(fn (DriverDebt $d) => [
                Forms\Components\TextInput::make('amount')->label('Số tiền đã thu')->numeric()->required()->suffix('₫')->minValue(1)
                    ->default(DriverDebtService::remaining($d))->maxValue(DriverDebtService::remaining($d)),
                Forms\Components\Textarea::make('note')->label('Hình thức / mã giao dịch')->required()->minLength(3)->maxLength(300)->rows(2),
            ])
            ->action(fn (DriverDebt $record, array $data) => self::notify(DriverDebtService::collectManual($record, (float) $data['amount'], $data['note'], auth()->id())));
    }

    public static function waiveAction($action)
    {
        return $action->label('Miễn nợ')->icon('heroicon-o-hand-raised')->color('gray')
            ->visible(fn (DriverDebt $d) => self::open($d))
            ->modalHeading('Miễn phần còn lại')
            ->modalDescription(fn (DriverDebt $d) => 'Miễn '.self::money(DriverDebtService::remaining($d)).' cho '.$d->driver?->name.'. Số phải thu của khoản này giảm tương ứng và được ghi vào nhật ký.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do')->required()->minLength(5)->maxLength(300)->rows(2)])
            ->action(fn (DriverDebt $record, array $data) => self::notify(DriverDebtService::waive($record, $data['reason'], auth()->id())));
    }

    public static function adjustAction($action)
    {
        return $action->label('Điều chỉnh số nợ')->icon('heroicon-o-pencil-square')->color('warning')
            ->visible(fn (DriverDebt $d) => $d->status !== 'paid')
            ->modalHeading('Điều chỉnh số tiền nợ')
            ->modalDescription(fn (DriverDebt $d) => 'Số nợ hiện tại '.self::money($d->amount_due).', đã thu '.self::money($d->amount_paid).'. Số mới không thấp hơn phần đã thu.')
            ->form(fn (DriverDebt $d) => [
                Forms\Components\TextInput::make('amount_due')->label('Số nợ mới')->numeric()->required()->suffix('₫')->minValue((float) $d->amount_paid)->default($d->amount_due),
                Forms\Components\Textarea::make('reason')->label('Lý do')->required()->minLength(5)->maxLength(300)->rows(2),
            ])
            ->action(fn (DriverDebt $record, array $data) => self::notify(DriverDebtService::adjustAmount($record, (float) $data['amount_due'], $data['reason'], auth()->id())));
    }

    public static function remindAction($action)
    {
        return $action->label('Nhắc nợ')->icon('heroicon-o-bell-alert')->color('warning')
            ->visible(fn (DriverDebt $d) => self::open($d))
            ->requiresConfirmation()
            ->modalHeading('Nhắc tài xế thanh toán')
            ->modalDescription('Gửi thông báo đẩy kèm số tiền còn nợ. Mỗi khoản chỉ nhắc 1 lần mỗi '.DriverDebtService::REMIND_COOLDOWN_HOURS.' giờ.')
            ->action(fn (DriverDebt $record) => self::notify(DriverDebtService::remind($record, auth()->id())));
    }

    public static function overdueAction($action)
    {
        return $action->label('Đánh dấu quá hạn')->icon('heroicon-o-exclamation-triangle')->color('danger')
            ->visible(fn (DriverDebt $d) => $d->status === 'pending')
            ->requiresConfirmation()
            ->modalHeading('Đánh dấu quá hạn')
            ->modalDescription('Tài xế sẽ không bật được online và không nhận được đơn cho đến khi thanh toán.')
            ->action(fn (DriverDebt $record) => self::notify(DriverDebtService::markOverdue($record, auth()->id())));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (DriverDebt $d) => collect([$d->driver?->phone, 'Ví '.self::money($d->driver?->wallet?->balance ?? 0), (int) $d->driver?->status === 2 ? 'Đã khóa' : null])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('period')
                    ->label('Kỳ / loại')
                    ->state(fn (DriverDebt $d) => $d->week_start && $d->week_end
                        ? Carbon::parse($d->week_start)->format('d/m').' – '.Carbon::parse($d->week_end)->format('d/m/Y')
                        : ($d->date ? Carbon::parse($d->date)->format('d/m/Y') : $d->created_at->format('d/m/Y')))
                    ->description(fn (DriverDebt $d) => str_starts_with((string) $d->ref_id, 'score_penalty') ? 'Phạt điểm tuần' : self::debtTypeLabel($d->debt_type)),

                Tables\Columns\TextColumn::make('amount_due')
                    ->label('Đối soát')
                    ->html()
                    ->alignEnd()
                    ->state(function (DriverDebt $d) {
                        $due = max(1, (float) $d->amount_due);
                        $pct = (int) min(100, round($d->amount_paid / $due * 100));

                        return '<b>'.self::money($d->amount_due).'</b><span class="fs-sc-meter" style="--bar:#16a34a;margin-left:auto"><i style="width:'.$pct.'%"></i></span>'
                            .'<small>Đã thu '.self::money($d->amount_paid).' · còn '.self::money(DriverDebtService::remaining($d)).'</small>';
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::statusLabel($state)[0])
                    ->color(fn ($state) => self::statusLabel($state)[1])
                    ->description(fn (DriverDebt $d) => self::ageLabel($d)),

                Tables\Columns\TextColumn::make('note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->tooltip(fn (DriverDebt $d) => $d->note)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(['pending' => 'Chờ thanh toán', 'paid' => 'Đã thanh toán', 'overdue' => 'Quá hạn']),
                SelectFilter::make('debt_type')->label('Loại')->options(DriverDebtService::TYPES),
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
                    self::walletAction(Tables\Actions\Action::make('pay_wallet')),
                    self::manualAction(Tables\Actions\Action::make('pay_manual')),
                    self::remindAction(Tables\Actions\Action::make('remind')),
                    self::waiveAction(Tables\Actions\Action::make('waive')),
                    self::adjustAction(Tables\Actions\Action::make('adjust')),
                    self::overdueAction(Tables\Actions\Action::make('mark_overdue')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->bulkActions([])
            ->recordUrl(fn (DriverDebt $record): string => static::getUrl('view', ['record' => $record]))
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
            'index' => Pages\ListDriverDebts::route('/'),
            'create' => Pages\CreateDriverDebt::route('/create'),
            'view' => Pages\ViewDriverDebt::route('/{record}'),
        ];
    }
}
