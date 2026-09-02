<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverDebtResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Services\DriverWalletService;

class DriverDebtResource extends Resource
{
    use RestrictToFullAdmin;

    // DriverDebt không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model = DriverDebt::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-minus';

    protected static ?string $navigationGroup = 'Công nợ';

    protected static ?string $modelLabel = 'Công nợ';

    protected static ?string $pluralModelLabel = 'Công nợ';

    protected static ?int $navigationSort = 3;

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin công nợ')
                ->description('Thiết lập số tiền, kỳ đối soát và trạng thái thanh toán')
                ->icon('heroicon-o-document-text')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('driver_id')
                        ->label('Tài xế')
                        ->options(fn () => User::where('user_type', 'driver')
                            ->where('status', 1)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->phone]))
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('amount_due')
                        ->label('Số tiền nợ')
                        ->numeric()
                        ->minValue(1000)
                        ->required()
                        ->live()
                        ->suffix('₫'),

                    Forms\Components\TextInput::make('amount_paid')
                        ->label('Đã thanh toán')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->suffix('₫'),

                    Forms\Components\DatePicker::make('week_start')
                        ->label('Từ ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Forms\Components\DatePicker::make('week_end')
                        ->label('Đến ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Forms\Components\TextInput::make('ref_id')
                        ->label('Mã tham chiếu')
                        ->placeholder('VD: weekly_2026_W22')
                        ->maxLength(100),

                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options([
                            'pending' => 'Chờ thanh toán',
                            'paid' => 'Đã thanh toán',
                            'overdue' => 'Quá hạn',
                        ])
                        ->default('pending')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (string $state, callable $set, callable $get) {
                            // Chọn "Đã thanh toán" thủ công → tự đồng bộ amount_paid
                            // = amount_due, tránh tình trạng status=paid nhưng
                            // "Còn lại" vẫn hiện nợ (giống hành vi nút Trừ ví).
                            if ($state === 'paid') {
                                $set('amount_paid', $get('amount_due'));
                            }
                        }),

                    // Sửa status trực tiếp ở đây KHÔNG đụng ví tài xế — chỉ
                    // nút "Trừ ví" ở danh sách mới thật sự trừ tiền qua
                    // DriverWalletService::adjust(). Cảnh báo rõ để admin
                    // không tưởng nhầm sửa status=paid là đã thu được tiền.
                    Forms\Components\Placeholder::make('wallet_warning')
                        ->label('')
                        ->columnSpanFull()
                        ->visible(fn (callable $get) => $get('status') === 'paid')
                        ->content('⚠️ Đổi trạng thái ở đây KHÔNG tự trừ tiền trong ví tài xế — chỉ dùng khi công nợ đã được thu ngoài hệ thống (tiền mặt, chuyển khoản tay...). Muốn trừ thẳng vào ví, dùng nút "Trừ ví" ở danh sách công nợ.'),

                    Forms\Components\Textarea::make('note')
                        ->label('Ghi chú')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tài xế')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('driver.name')
                        ->label('Tên tài xế')
                        ->weight('bold'),

                    Infolists\Components\TextEntry::make('driver.phone')
                        ->label('Số điện thoại')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('driver.city.name')
                        ->label('Khu vực')
                        ->default('—'),
                ]),

            Infolists\Components\Section::make('Chi tiết công nợ')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('amount_due')
                        ->label('Số tiền nợ')
                        ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                        ->weight('bold')
                        ->color('danger'),

                    Infolists\Components\TextEntry::make('amount_paid')
                        ->label('Đã thanh toán')
                        ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                        ->color('success'),

                    Infolists\Components\TextEntry::make('remaining')
                        ->label('Còn lại')
                        ->state(fn (DriverDebt $r) => number_format($r->amount_due - $r->amount_paid, 0, ',', '.').' ₫')
                        ->weight('bold')
                        ->color(fn (DriverDebt $r) => ($r->amount_due - $r->amount_paid) > 0 ? 'warning' : 'success'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'pending' => 'Chờ thanh toán',
                            'paid' => 'Đã thanh toán',
                            'overdue' => 'Quá hạn',
                            default => $state,
                        })
                        ->color(fn ($state) => match ($state) {
                            'pending' => 'warning',
                            'paid' => 'success',
                            'overdue' => 'danger',
                            default => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('week_start')
                        ->label('Từ ngày')
                        ->date('d/m/Y')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('week_end')
                        ->label('Đến ngày')
                        ->date('d/m/Y')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('ref_id')
                        ->label('Mã tham chiếu')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('note')
                        ->label('Ghi chú')
                        ->default('—')
                        ->columnSpanFull(),
                ]),
        ]);
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
                    ->weight('bold')
                    ->description(fn (DriverDebt $r) => $r->driver?->phone ?? ''),

                Tables\Columns\TextColumn::make('driver.city.name')
                    ->label('Khu vực')
                    ->alignCenter()
                    ->default('—'),

                Tables\Columns\TextColumn::make('period')
                    ->label('Kỳ')
                    ->alignCenter()
                    ->state(fn (DriverDebt $r) => $r->week_start && $r->week_end
                            ? Carbon::parse($r->week_start)->format('d/m').' – '.Carbon::parse($r->week_end)->format('d/m/Y')
                            : '—'
                    ),

                Tables\Columns\TextColumn::make('amount_due')
                    ->label('Số tiền nợ')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                    ->weight('bold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Đã trả')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' ₫')
                    ->color('success'),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Còn lại')
                    ->alignCenter()
                    ->state(fn (DriverDebt $r) => number_format($r->amount_due - $r->amount_paid, 0, ',', '.').' ₫')
                    ->weight('bold')
                    ->color(fn (DriverDebt $r) => ($r->amount_due - $r->amount_paid) > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Chờ thanh toán',
                        'paid' => 'Đã thanh toán',
                        'overdue' => 'Quá hạn',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        'overdue' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->alignCenter()
                    ->dateTime('d/m/Y'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'pending' => 'Chờ thanh toán',
                        'paid' => 'Đã thanh toán',
                        'overdue' => 'Quá hạn',
                    ])
                    ->default('pending'),

                SelectFilter::make('city')
                    ->label('Khu vực')
                    ->relationship('driver.city', 'name')
                    ->options(fn () => City::where('is_active', true)->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(''),

                Tables\Actions\Action::make('pay_wallet')
                    ->label('')
                    ->icon('heroicon-o-credit-card')
                    ->color('success')
                    ->tooltip('Trừ ví & đánh dấu đã trả')
                    ->visible(fn (DriverDebt $r) => $r->status !== 'paid')
                    ->requiresConfirmation()
                    ->modalHeading('Thanh toán qua ví')
                    ->modalDescription(fn (DriverDebt $r) => 'Trừ '.number_format($r->amount_due - $r->amount_paid, 0, ',', '.').' ₫ từ ví tài xế '.$r->driver?->name.'?'
                    )
                    ->action(function (DriverDebt $record) {
                        $remaining = $record->amount_due - $record->amount_paid;
                        try {
                            DriverWalletService::adjust(
                                $record->driver_id, $remaining, 'debit',
                                'Thanh toán công nợ #'.$record->id.' (admin)',
                                'debt_admin_'.$record->id
                            );
                            $record->update(['amount_paid' => $record->amount_due, 'status' => 'paid']);
                            Notification::make()->success()->title('Đã thanh toán công nợ.')->send();
                        } catch (\Exception $e) {
                            Notification::make()->danger()->title('Lỗi: '.$e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('mark_overdue')
                    ->label('')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->tooltip('Đánh dấu quá hạn')
                    ->visible(fn (DriverDebt $r) => $r->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Đánh dấu quá hạn')
                    ->action(fn (DriverDebt $r) => $r->update(['status' => 'overdue'])),

                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
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
            'edit' => Pages\EditDriverDebt::route('/{record}/edit'),
        ];
    }
}
