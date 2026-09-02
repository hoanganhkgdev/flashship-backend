<?php

namespace App\Filament\Resources\PricingResource\Pages;

use App\Filament\Resources\PricingResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Pricing\Services\PricingService;

class EditPricingConfig extends EditRecord
{
    protected static string $resource = PricingResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->label;
    }

    public function getSubheading(): ?string
    {
        return 'Thay đổi bảng giá sẽ ảnh hưởng đến các đơn được báo giá sau khi lưu.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Sync summary fields từ config_json sau khi lưu
    protected function afterSave(): void
    {
        $record = $this->record;
        $cfg = $record->config_json;

        if (! $cfg) {
            return;
        }

        $updates = match ($cfg['type'] ?? '') {
            'slab' => (function () use ($cfg) {
                $first = $cfg['slabs'][0] ?? null;

                return [
                    'base_km' => $first ? (float) $first['max_km'] : 0,
                    'base_fee' => $first ? (int) $first['fee'] : 0,
                    'per_km_fee' => (int) ($cfg['over_max_per_km'] ?? 0),
                    'min_fee' => $first ? (int) $first['fee'] : 0,
                ];
            })(),
            'linear', 'tiered_linear' => [
                'base_km' => (float) ($cfg['base_km'] ?? 0),
                'base_fee' => (int) ($cfg['base_fee'] ?? 0),
                'per_km_fee' => (int) ($cfg['per_km_fee'] ?? 0),
                'min_fee' => (int) ($cfg['base_fee'] ?? 0),
            ],
            'topup' => (function () use ($cfg) {
                $first = $cfg['tiers'][0] ?? null;

                return [
                    'base_km' => 0,
                    'base_fee' => $first ? (int) $first['fee'] : 0,
                    'per_km_fee' => 0,
                    'min_fee' => $first ? (int) $first['fee'] : 0,
                ];
            })(),
            default => [],
        };

        if ($updates) {
            $record->updateQuietly($updates);
        }
    }

    // Nút "Tính thử phí" trên header
    protected function getFormActions(): array
    {
        return array_merge(parent::getFormActions(), [
            Action::make('preview_fee')
                ->label('Tính thử phí')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->form([
                    TextInput::make('distance_km')
                        ->label('Quãng đường (km)')
                        ->numeric()
                        ->required()
                        ->visible(fn () => $this->record->service_type !== 'topup')
                        ->default(3),
                    TextInput::make('topup_amount')
                        ->label('Số tiền nạp (₫)')
                        ->numeric()
                        ->required()
                        ->visible(fn () => $this->record->service_type === 'topup')
                        ->default(1_000_000),
                ])
                ->action(function (array $data) {
                    $record = $this->record;

                    if ($record->service_type === 'topup') {
                        $fee = PricingService::topupFee((int) $data['topup_amount']);
                        $body = 'Số tiền: '.number_format((int) $data['topup_amount']).'₫ → Phí: '.number_format($fee).'₫';
                    } else {
                        $result = PricingService::estimate($record->service_type, (float) $data['distance_km']);
                        $fee = $result['fee'];
                        $body = round($data['distance_km'], 1).' km → Phí: '.number_format($fee).'₫';
                    }

                    Notification::make()
                        ->title('Kết quả tính phí')
                        ->body($body)
                        ->success()
                        ->send();
                }),
        ]);
    }
}
