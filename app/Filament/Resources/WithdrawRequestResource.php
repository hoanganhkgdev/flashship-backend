<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawRequestResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;
use App\Services\PayOSPayoutService;
use App\Filament\Traits\RestrictToFullAdmin;

class WithdrawRequestResource extends Resource
{
    use RestrictToFullAdmin;

    // WithdrawRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(\Illuminate\Database\Eloquent\Builder $query, ?\Illuminate\Database\Eloquent\Model $tenant): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model            = WithdrawRequest::class;
    protected static ?string $navigationIcon   = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationGroup  = 'Quản lý ví';
    protected static ?string $modelLabel       = 'Yêu cầu rút tiền';
    protected static ?string $pluralModelLabel = 'Yêu cầu rút tiền';
    protected static ?int    $navigationSort   = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = WithdrawRequest::where('status', 'pending')->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function canCreate(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
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
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Số tiền')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.') . ' ₫')
                    ->weight('bold')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'  => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        default    => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Ngân hàng')
                    ->default('—'),

                Tables\Columns\TextColumn::make('account_number')
                    ->label('STK')
                    ->default('—'),

                Tables\Columns\TextColumn::make('admin_note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->default('—')
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('processor.name')
                    ->label('Người duyệt')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày yêu cầu')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),

                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Ngày xử lý')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending'  => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawRequest $record) => $record->status === 'pending')
                    ->modalHeading('Duyệt yêu cầu rút tiền')
                    ->modalDescription(fn (WithdrawRequest $record) =>
                        'Xác nhận rút ' . number_format($record->amount, 0, ',', '.') . ' ₫ cho tài xế ' . $record->driver?->name . '?'
                    )
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Ghi chú (tuỳ chọn)')
                            ->rows(2),
                    ])
                    ->action(function (WithdrawRequest $record, array $data) {
                        $lock = Cache::lock("withdraw:payout:{$record->id}", 60);
                        if (!$lock->get()) {
                            Notification::make()->warning()->title('Yêu cầu đang được xử lý ở một phiên khác.')->send();
                            return;
                        }

                        try {
                            $fresh = WithdrawRequest::find($record->id);
                            if (!$fresh || $fresh->status !== 'pending') {
                                Notification::make()->warning()->title('Yêu cầu này đã được xử lý.')->send();
                                return;
                            }
                            if (!$fresh->bank_code || !$fresh->account_number) {
                                Notification::make()->danger()->title('Yêu cầu cũ chưa có bản lưu tài khoản ngân hàng.')->send();
                                return;
                            }

                            // Không ghi approved trước khi gọi ra ngoài. Nếu
                            // process chết sau khi PayOS nhận lệnh, lần thử lại
                            // dùng cùng referenceId và PayOS trả cùng giao dịch.
                            $refId = 'WD' . $fresh->id;
                            $result = PayOSPayoutService::createPayout(
                                referenceId: $refId,
                                amount: (int) $fresh->amount,
                                description: 'Rut tien TX ' . ($fresh->driver?->name ?? $fresh->driver_id),
                                bankCode: $fresh->bank_code,
                                accountNumber: $fresh->account_number,
                            );

                            if (!$result['success']) {
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
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Lý do từ chối')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (WithdrawRequest $record, array $data) {
                        try {
                            $rejected = DB::transaction(function () use ($record, $data) {
                                $locked = WithdrawRequest::where('id', $record->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();
                                if ($locked->status !== 'pending') return false;

                                // Refund the held balance
                                DriverWalletService::adjust(
                                    $locked->driver_id,
                                    $locked->amount,
                                    'credit',
                                    'Hoàn tiền yêu cầu rút #' . $locked->id,
                                    'withdraw_refund_' . $locked->id
                                );
                                $locked->update([
                                    'status'       => 'rejected',
                                    'admin_note'   => $data['admin_note'],
                                    'processed_by' => Auth::id(),
                                    'processed_at' => now(),
                                ]);
                                return true;
                            });
                            if (!$rejected) {
                                Notification::make()->warning()
                                    ->title('Yêu cầu đã được xử lý, không hoàn tiền lại.')
                                    ->send();
                                return;
                            }
                            Notification::make()->success()->title('Đã từ chối và hoàn tiền cho tài xế.')->send();
                        } catch (\Exception $e) {
                            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                        }
                    }),
            ]);
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
