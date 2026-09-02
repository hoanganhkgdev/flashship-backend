<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;

class SendNotificationPage extends Page
{
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?string $navigationLabel = 'Gửi thông báo';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.send-notification';

    public function getTitle(): string
    {
        return 'Gửi thông báo';
    }

    public function getSubheading(): ?string
    {
        return 'Gửi push notification theo nhóm người dùng và khu vực. Thông báo đã gửi không thể thu hồi.';
    }

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
                    ->description('Nội dung ngắn gọn hiển thị trên thiết bị người nhận')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
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
                    ->description('Chọn chính xác nhóm tài khoản trước khi gửi')
                    ->icon('heroicon-o-user-group')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('target')
                            ->label('Gửi đến')
                            ->options([
                                'all_drivers' => 'Tất cả tài xế',
                                'all_customers' => 'Tất cả khách hàng',
                                'drivers_city' => 'Tài xế theo khu vực',
                                'customers_city' => 'Khách hàng theo khu vực',
                            ])
                            ->required()
                            ->live()
                            ->default('all_drivers'),

                        Forms\Components\Select::make('city_id')
                            ->label('Khu vực')
                            ->options(fn () => City::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (Forms\Get $get) => $this->canManageAllCities() && str_contains($get('target') ?? '', 'city'))
                            ->required(fn (Forms\Get $get) => $this->canManageAllCities() && str_contains($get('target') ?? '', 'city')),
                    ]),
            ])
            ->statePath('data');
    }

    /** Chỉ admin/subadmin gửi được thông báo xuyên khu vực. */
    private function canManageAllCities(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin']);
    }

    private function buildQuery(array $data): Builder
    {
        $q = User::whereNotNull('fcm_token')->where('fcm_token', '!=', '');

        if ($data['target'] === 'all_drivers') {
            $q->where('user_type', 'driver');
        } elseif ($data['target'] === 'all_customers') {
            $q->where('user_type', 'customer');
        } elseif ($data['target'] === 'drivers_city') {
            $q->where('user_type', 'driver')->where('city_id', $data['city_id']);
        } elseif ($data['target'] === 'customers_city') {
            $q->where('user_type', 'customer')->where('city_id', $data['city_id']);
        } else {
            $q->whereRaw('1=0');
        }

        // city_manager/call_center chỉ được gửi trong đúng khu vực (tenant)
        // đang đứng — chặn cứng phía server, bất kể chọn target nào.
        if (! $this->canManageAllCities()) {
            $q->where('city_id', Filament::getTenant()?->id);
        }

        return $q;
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $tokens = $this->buildQuery($data)->pluck('fcm_token')->filter()->values()->toArray();

        if (empty($tokens)) {
            Notification::make()->warning()
                ->title('Không có người nhận')
                ->body('Không tìm thấy người dùng nào có FCM token cho đối tượng này.')
                ->send();

            return;
        }

        try {
            $result = FCMService::getInstance()->broadcast($tokens, $data['title'], $data['body']);

            // Lưu vào DB để app fetch được
            $cityId = $this->canManageAllCities()
                ? (isset($data['city_id']) && str_contains($data['target'], 'city') ? (int) $data['city_id'] : null)
                : Filament::getTenant()?->id;
            if (str_contains($data['target'], 'customer')) {
                CustomerNotificationController::broadcast($data['title'], $data['body'], $cityId);
            }

            Notification::make()->success()
                ->title('Đã gửi thông báo')
                ->body("Thành công: {$result['sent']} · Thất bại: {$result['failed']} / ".count($tokens).' người nhận')
                ->send();
            $this->form->fill();
        } catch (\Throwable $e) {
            Notification::make()->danger()
                ->title('Lỗi FCM')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
