<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Driver\Models\DriverLicense;

class DriverLicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'driverLicenses';
    protected static ?string $title = 'Bằng lái xe';
    protected static ?string $modelLabel = 'bằng lái';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Hình')
                    ->disk('public')
                    ->height(60)
                    ->width(80)
                    ->extraImgAttributes(['style' => 'object-fit:cover;border-radius:6px']),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã xác minh',
                        'rejected' => 'Bị từ chối',
                        'pending'  => 'Chờ xét duyệt',
                        default    => 'Không rõ',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending'  => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tải lên')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (DriverLicense $record) => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(function (DriverLicense $record) {
                        $record->update(['status' => 'approved']);
                        Notification::make()->title('Đã duyệt bằng lái xe')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (DriverLicense $record) => $record->status !== 'rejected')
                    ->requiresConfirmation()
                    ->action(function (DriverLicense $record) {
                        $record->update(['status' => 'rejected']);
                        Notification::make()->title('Đã từ chối bằng lái xe')->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
