<?php

namespace App\Filament\Resources\DriverWalletResource\Pages;

use App\Filament\Resources\DriverWalletResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Driver\Services\DriverWalletService;

class ViewDriverWallet extends ViewRecord
{
    protected static string $resource = DriverWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('adjust')
                ->label('Điều chỉnh ví')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->form([
                    Forms\Components\Select::make('type')
                        ->label('Loại')
                        ->options([
                            'credit' => 'Cộng tiền',
                            'debit'  => 'Trừ tiền',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('amount')
                        ->label('Số tiền (₫)')
                        ->numeric()
                        ->minValue(1000)
                        ->required()
                        ->suffix('₫'),

                    Forms\Components\Textarea::make('description')
                        ->label('Lý do / Mô tả')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $record = $this->getRecord();
                    try {
                        $ref = 'admin_adj_' . $record->driver_id . '_' . now()->timestamp;
                        DriverWalletService::adjust(
                            $record->driver_id,
                            (float) $data['amount'],
                            $data['type'],
                            $data['description'] . ' (admin)',
                            $ref
                        );
                        $record->refresh();
                        Notification::make()
                            ->success()
                            ->title('Đã ' . ($data['type'] === 'credit' ? 'cộng' : 'trừ') . ' ' . number_format($data['amount'], 0, ',', '.') . ' ₫')
                            ->body('Số dư mới: ' . number_format($record->balance, 0, ',', '.') . ' ₫')
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                    }
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();
        return 'Ví: ' . ($record->driver?->name ?? 'Tài xế #' . $record->driver_id);
    }
}
