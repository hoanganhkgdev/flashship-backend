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

                Tables\Columns\TextColumn::make('driverBank.bank_name')
                    ->label('Ngân hàng')
                    ->default('—'),

                Tables\Columns\TextColumn::make('driverBank.account_number')
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
                        $bank = DB::table('banks')->where('user_id', $record->driver_id)->first();
                        if (!$bank) {
                            Notification::make()->danger()->title('Tài xế chưa có thông tin ngân hàng.')->send();
                            return;
                        }

                        // "Chiếm" yêu cầu bằng UPDATE có điều kiện status='pending'
                        // — atomic ở tầng DB, chỉ 1 trong nhiều lần bấm "Duyệt"
                        // gần như đồng thời (mạng chậm, 2 tab) thắng được. Làm
                        // TRƯỚC khi gọi PayOS (không phải sau) — tránh chuyển
                        // khoản thật 2 lần cho cùng 1 yêu cầu.
                        $claimed = WithdrawRequest::where('id', $record->id)
                            ->where('status', 'pending')
                            ->update([
                                'status'       => 'approved',
                                'processed_by' => Auth::id(),
                                'processed_at' => now(),
                            ]);

                        if ($claimed === 0) {
                            Notification::make()->warning()
                                ->title('Yêu cầu này vừa được xử lý (có thể do bấm trùng) — không duyệt lại.')
                                ->send();
                            return;
                        }

                        // referenceId CỐ ĐỊNH theo id đơn (không có time()) —
                        // nếu vẫn lỡ gọi PayOS 2 lần (không nên xảy ra sau khi
                        // đã chiếm ở trên), PayOS tự dedupe theo đúng
                        // referenceId thay vì tạo 2 giao dịch riêng.
                        $refId  = 'WD' . $record->id;
                        $result = PayOSPayoutService::createPayout(
                            referenceId:   $refId,
                            amount:        (int) $record->amount,
                            description:   'Rut tien TX ' . ($record->driver?->name ?? $record->driver_id),
                            bankCode:      $bank->bank_code,
                            accountNumber: $bank->account_number,
                        );

                        if (!$result['success']) {
                            // Chuyển khoản thất bại — trả về pending để admin
                            // duyệt lại được, không để đơn kẹt ở "approved" mà
                            // tiền thực ra chưa hề đi.
                            WithdrawRequest::where('id', $record->id)->update([
                                'status'       => 'pending',
                                'processed_by' => null,
                                'processed_at' => null,
                            ]);
                            Notification::make()->danger()
                                ->title('Chuyển khoản thất bại')
                                ->body($result['message'])
                                ->send();
                            return;
                        }

                        $record->update([
                            'admin_note'       => $data['admin_note'] ?? null,
                            'payout_reference' => $refId,
                        ]);
                        Notification::make()->success()->title('Đã duyệt và chuyển khoản thành công.')->send();
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
                            DB::transaction(function () use ($record, $data) {
                                // Refund the held balance
                                DriverWalletService::adjust(
                                    $record->driver_id,
                                    $record->amount,
                                    'credit',
                                    'Hoàn tiền yêu cầu rút #' . $record->id,
                                    'withdraw_refund_' . $record->id
                                );
                                $record->update([
                                    'status'       => 'rejected',
                                    'admin_note'   => $data['admin_note'],
                                    'processed_by' => Auth::id(),
                                    'processed_at' => now(),
                                ]);
                            });
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
