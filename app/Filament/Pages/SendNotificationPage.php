<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;

class SendNotificationPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Gửi thông báo';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.send-notification';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Nội dung thông báo')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Tiêu đề')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('VD: Ưu đãi đặc biệt hôm nay!'),

                        Forms\Components\Textarea::make('body')
                            ->label('Nội dung')
                            ->required()
                            ->rows(3)
                            ->maxLength(500),
                    ]),

                Forms\Components\Section::make('Đối tượng nhận')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('target')
                            ->label('Gửi đến')
                            ->options([
                                'all_drivers'    => 'Tất cả tài xế',
                                'all_customers'  => 'Tất cả khách hàng',
                                'drivers_city'   => 'Tài xế theo khu vực',
                                'customers_city' => 'Khách hàng theo khu vực',
                            ])
                            ->required()
                            ->live()
                            ->default('all_drivers'),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực')
                            ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (Forms\Get $get) => str_contains($get('target') ?? '', 'city'))
                            ->required(fn (Forms\Get $get) => str_contains($get('target') ?? '', 'city')),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();

        $query = User::whereNotNull('fcm_token')->where('fcm_token', '!=', '');

        match ($data['target']) {
            'all_drivers'    => $query->where('user_type', 'driver'),
            'all_customers'  => $query->where('user_type', 'customer'),
            'drivers_city'   => $query->where('user_type', 'driver')->where('city_id', $data['city_id']),
            'customers_city' => $query->where('user_type', 'customer')->where('city_id', $data['city_id']),
            default          => $query->whereRaw('1=0'),
        };

        $tokens = $query->pluck('fcm_token')->filter()->values()->toArray();

        if (empty($tokens)) {
            Notification::make()->warning()->title('Không có người nhận nào có FCM token.')->send();
            return;
        }

        try {
            $result = FCMService::getInstance()->broadcast($tokens, $data['title'], $data['body']);
            Notification::make()->success()
                ->title('Đã gửi thông báo')
                ->body("Thành công: {$result['sent']} · Thất bại: {$result['failed']} / " . count($tokens) . ' người nhận')
                ->send();
            $this->form->fill();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
