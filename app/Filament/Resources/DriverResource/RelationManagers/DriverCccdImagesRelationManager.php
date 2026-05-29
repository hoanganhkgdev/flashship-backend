<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\Driver\Models\DriverCccdImage;

class DriverCccdImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'driverCccdImages';
    protected static ?string $title       = 'Hình CCCD / CMND';
    protected static ?string $modelLabel  = 'ảnh CCCD';

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
                    ->height(56)
                    ->width(80)
                    ->extraImgAttributes(['style' => 'object-fit:cover;border-radius:6px;cursor:pointer']),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Đã xác minh',
                        'rejected' => 'Từ chối',
                        'pending'  => 'Chờ duyệt',
                        default    => 'Không rõ',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending'  => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tải lên lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Xem & Duyệt')
                    ->icon('heroicon-o-magnifying-glass-plus')
                    ->color('primary')
                    ->modalHeading('Xét duyệt CCCD / CMND')
                    ->modalWidth('2xl')
                    ->modalContent(fn (DriverCccdImage $record): HtmlString => new HtmlString(
                        '<div style="text-align:center;padding:8px 0 16px">'
                        . '<img src="' . ($record->image_path ? asset('storage/' . $record->image_path) : '') . '"'
                        . ' style="max-width:100%;max-height:480px;border-radius:10px;object-fit:contain;border:1px solid #e5e7eb">'
                        . '</div>'
                    ))
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Quyết định')
                            ->options([
                                'approved' => '✓  Duyệt — CCCD hợp lệ',
                                'rejected' => '✗  Từ chối — CCCD không hợp lệ',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->fillForm(fn (DriverCccdImage $record) => ['status' => $record->status])
                    ->modalSubmitActionLabel('Lưu quyết định')
                    ->action(function (DriverCccdImage $record, array $data) {
                        $record->update(['status' => $data['status']]);
                        Notification::make()
                            ->title($data['status'] === 'approved' ? 'Đã duyệt CCCD' : 'Đã từ chối CCCD')
                            ->color($data['status'] === 'approved' ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
