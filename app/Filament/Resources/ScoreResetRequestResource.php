<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScoreResetRequestResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\Driver\Models\DriverScoreResetRequest;
use Modules\Driver\Services\DriverScoreService;

class ScoreResetRequestResource extends Resource
{
    protected static ?string $model = DriverScoreResetRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Tài xế';

    protected static ?string $modelLabel = 'Yêu cầu reset điểm';

    protected static ?string $pluralModelLabel = 'Yêu cầu reset điểm';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $count = DriverScoreResetRequest::where('status', 'pending')->count();
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('current_score')
                    ->label('Điểm hiện tại')
                    ->badge()
                    ->color(fn ($state) => $state < 30 ? 'danger' : ($state < 60 ? 'warning' : 'success')),

                Tables\Columns\TextColumn::make('points_to_restore')
                    ->label('Điểm cần phục hồi')
                    ->formatStateUsing(fn ($state) => "+{$state} → 80"),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Phí phạt')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.') . ' ₫')
                    ->weight('bold')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
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
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Ngày xử lý')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DriverScoreResetRequest $record) => $record->status === 'pending')
                    ->modalHeading('Duyệt yêu cầu reset điểm')
                    ->modalDescription(fn (DriverScoreResetRequest $record) =>
                        "Xác nhận đã nhận " . number_format($record->amount, 0, ',', '.') . " ₫ từ tài xế {$record->driver?->name}? Điểm sẽ được đặt lại về 80."
                    )
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Ghi chú (tuỳ chọn)')
                            ->rows(2),
                    ])
                    ->action(function (DriverScoreResetRequest $record, array $data) {
                        DriverScoreService::resetToDefault($record->driver_id);

                        $record->update([
                            'status'       => 'approved',
                            'admin_note'   => $data['admin_note'] ?? null,
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);

                        Notification::make()->success()->title('Đã duyệt — điểm tài xế đặt lại về 80.')->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DriverScoreResetRequest $record) => $record->status === 'pending')
                    ->modalHeading('Từ chối yêu cầu reset điểm')
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Lý do từ chối')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DriverScoreResetRequest $record, array $data) {
                        $record->update([
                            'status'       => 'rejected',
                            'admin_note'   => $data['admin_note'],
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);

                        Notification::make()->success()->title('Đã từ chối yêu cầu.')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScoreResetRequests::route('/'),
        ];
    }
}
