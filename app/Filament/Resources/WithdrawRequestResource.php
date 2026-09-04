<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawRequestResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use App\Services\PayOSPayoutService;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;

class WithdrawRequestResource extends Resource
{
    use RestrictToFullAdmin;

    // WithdrawRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = WithdrawRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationGroup = 'Tài chính tài xế';

    protected static ?string $modelLabel = 'Yêu cầu rút tiền';

    protected static ?string $pluralModelLabel = 'Yêu cầu rút tiền';

    protected static ?int $navigationSort = 2;

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()
                    ->label('#')
                    ->alignCenter()
                    ->width(40),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('driver', fn (Builder $driverQuery) => $driverQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (WithdrawRequest $record) => collect([
                        $record->driver?->phone,
                        $record->driver?->city?->name,
                    ])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                    ->color(fn (WithdrawRequest $record) => $record->status === 'pending' ? 'warning' : 'gray')
                    ->description(fn (WithdrawRequest $record) => 'Ví hiện tại: '.number_format((float) ($record->driver?->wallet?->balance ?? 0), 0, ',', '.').' ₫'),

                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Tài khoản nhận')
                    ->searchable(['bank_name', 'account_number', 'account_name'])
                    ->default('Chưa có ngân hàng')
                    ->description(fn (WithdrawRequest $record) => collect([
                        $record->account_number,
                        $record->account_name,
                    ])->filter()->join(' · '))
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (WithdrawRequest $record) => match ($record->status) {
                        'pending' => 'Đang chờ xử lý',
                        'approved' => collect([$record->processor?->name, $record->processed_at?->format('d/m H:i')])->filter()->join(' · '),
                        'rejected' => collect([$record->processor?->name, $record->processed_at?->format('d/m H:i')])->filter()->join(' · '),
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Ghi chú')
                    ->default('Không có ghi chú')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (WithdrawRequest $record) => $record->processed_at
                        ? 'Xử lý '.$record->processed_at->format('d/m/Y H:i')
                        : 'Chưa xử lý'),

                Tables\Columns\TextColumn::make('payout_reference')
                    ->label('Mã chuyển khoản')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                    ]),
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
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawRequest $record) => $record->status === 'pending')
                    ->modalHeading('Duyệt yêu cầu rút tiền')
                    ->modalDescription(fn (WithdrawRequest $record) => 'Chuyển '.number_format($record->amount, 0, ',', '.').' ₫ cho '.$record->driver?->name.' vào '.($record->bank_name ?: 'ngân hàng chưa xác định').' · '.($record->account_number ?: 'chưa có STK').' · '.($record->account_name ?: 'chưa có chủ tài khoản').'.')
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Ghi chú (tuỳ chọn)')
                            ->rows(2),
                    ])
                    ->action(function (WithdrawRequest $record, array $data) {
                        $lock = Cache::lock("withdraw:payout:{$record->id}", 300);
                        if (! $lock->get()) {
                            Notification::make()->warning()->title('Yêu cầu đang được xử lý ở một phiên khác.')->send();

                            return;
                        }

                        try {
                            $fresh = WithdrawRequest::find($record->id);
                            if (! $fresh || $fresh->status !== 'pending') {
                                Notification::make()->warning()->title('Yêu cầu này đã được xử lý.')->send();

                                return;
                            }
                            if (! $fresh->bank_code || ! $fresh->account_number) {
                                Notification::make()->danger()->title('Yêu cầu cũ chưa có bản lưu tài khoản ngân hàng.')->send();

                                return;
                            }

                            // Không ghi approved trước khi gọi ra ngoài. Nếu
                            // process chết sau khi PayOS nhận lệnh, lần thử lại
                            // dùng cùng referenceId và PayOS trả cùng giao dịch.
                            $refId = 'WD'.$fresh->id;
                            $result = PayOSPayoutService::createPayout(
                                referenceId: $refId,
                                amount: (int) $fresh->amount,
                                description: 'Rut tien TX '.($fresh->driver?->name ?? $fresh->driver_id),
                                bankCode: $fresh->bank_code,
                                accountNumber: $fresh->account_number,
                            );

                            if (! $result['success']) {
                                Notification::make()->danger()->title('Chuyển khoản thất bại')->body($result['message'])->send();

                                return;
                            }

                            $approved = WithdrawRequest::whereKey($fresh->id)->where('status', 'pending')->update([
                                'status' => 'approved',
                                'admin_note' => $data['admin_note'] ?? null,
                                'payout_reference' => $refId,
                                'processed_by' => Auth::id(),
                                'processed_at' => now(),
                            ]);
                            Notification::make()->{$approved ? 'success' : 'warning'}()
                                ->title($approved ? 'Đã duyệt và chuyển khoản thành công.' : 'Giao dịch đã được phiên khác cập nhật.')
                                ->send();
                        } finally {
                            $lock->release();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WithdrawRequest $record) => $record->status === 'pending')
                    ->modalHeading('Từ chối yêu cầu rút tiền')
                    ->modalDescription(fn (WithdrawRequest $record) => 'Số tiền '.number_format($record->amount, 0, ',', '.').' ₫ đang giữ sẽ được hoàn lại vào ví tài xế '.$record->driver?->name.'.')
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Lý do từ chối')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (WithdrawRequest $record, array $data) {
                        // Dùng cùng khóa với luồng duyệt. Nếu không, admin A
                        // có thể đang chờ PayOS chuyển khoản trong khi admin B
                        // từ chối và hoàn hold vào ví: tài xế vừa nhận tiền
                        // ngân hàng vừa được hoàn số dư.
                        $lock = Cache::lock("withdraw:payout:{$record->id}", 300);
                        if (! $lock->get()) {
                            Notification::make()->warning()
                                ->title('Yêu cầu đang được xử lý ở một phiên khác.')
                                ->send();

                            return;
                        }

                        try {
                            $rejected = DB::transaction(function () use ($record, $data) {
                                $locked = WithdrawRequest::where('id', $record->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();
                                if ($locked->status !== 'pending') {
                                    return false;
                                }

                                // Refund the held balance
                                DriverWalletService::adjust(
                                    $locked->driver_id,
                                    $locked->amount,
                                    'credit',
                                    'Hoàn tiền yêu cầu rút #'.$locked->id,
                                    'withdraw_refund_'.$locked->id
                                );
                                $locked->update([
                                    'status' => 'rejected',
                                    'admin_note' => $data['admin_note'],
                                    'processed_by' => Auth::id(),
                                    'processed_at' => now(),
                                ]);

                                return true;
                            });
                            if (! $rejected) {
                                Notification::make()->warning()
                                    ->title('Yêu cầu đã được xử lý, không hoàn tiền lại.')
                                    ->send();

                                return;
                            }
                            Notification::make()->success()->title('Đã từ chối và hoàn tiền cho tài xế.')->send();
                        } catch (\Exception $e) {
                            Notification::make()->danger()->title('Lỗi: '.$e->getMessage())->send();
                        } finally {
                            $lock->release();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->poll('20s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawRequests::route('/'),
        ];
    }
}
