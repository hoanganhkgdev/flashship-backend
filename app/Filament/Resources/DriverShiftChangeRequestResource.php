<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverShiftChangeRequestResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverShiftChangeRequest;

class DriverShiftChangeRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']) && static::canViewAny();
    }

    // DriverShiftChangeRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(\Illuminate\Database\Eloquent\Builder $query, ?\Illuminate\Database\Eloquent\Model $tenant): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model            = DriverShiftChangeRequest::class;
    protected static ?string $navigationIcon   = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationGroup  = 'Cấu hình';
    protected static ?string $modelLabel       = 'Yêu cầu đổi ca';
    protected static ?string $pluralModelLabel = 'Yêu cầu đổi ca';
    protected static ?int    $navigationSort   = 3;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();
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

    private static function shiftNames(array $ids): string
    {
        return Shift::whereIn('id', $ids)->orderBy('start_time')->pluck('name')->implode(', ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('driver.phone')
                    ->label('Số điện thoại')
                    ->searchable(),

                Tables\Columns\TextColumn::make('shift_ids')
                    ->label('Ca yêu cầu')
                    ->formatStateUsing(fn (array $state) => static::shiftNames($state))
                    ->wrap(),

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
            ->defaultSort('created_at', 'desc')
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
                    ->visible(fn (DriverShiftChangeRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Duyệt yêu cầu đổi ca')
                    ->modalDescription(fn (DriverShiftChangeRequest $record) =>
                        'Đổi ca của tài xế ' . $record->driver?->name . ' sang: ' . static::shiftNames($record->shift_ids) . '?'
                    )
                    ->action(function (DriverShiftChangeRequest $record) {
                        $record->driver?->registeredShifts()->sync($record->shift_ids);

                        $record->update([
                            'status'       => 'approved',
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);
                        Notification::make()->success()->title('Đã duyệt đổi ca.')->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DriverShiftChangeRequest $record) => $record->status === 'pending')
                    ->modalHeading('Từ chối yêu cầu đổi ca')
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('Lý do từ chối')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DriverShiftChangeRequest $record, array $data) {
                        $record->update([
                            'status'       => 'rejected',
                            'admin_note'   => $data['admin_note'],
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);
                        Notification::make()->success()->title('Đã từ chối yêu cầu đổi ca.')->send();
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
            'index' => Pages\ListDriverShiftChangeRequests::route('/'),
        ];
    }
}
