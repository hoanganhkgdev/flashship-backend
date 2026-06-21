<?php

namespace App\Filament\Pages;

use App\Services\ZaloTokenService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ZaloTokenSettings extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Zalo ZNS Token';
    protected static ?string $title           = 'Quản lý Zalo ZNS Token';
    protected static ?int    $navigationSort  = 99;

    protected static string $view = 'filament.pages.zalo-token-settings';

    public static function canAccess(): bool
    {
        return !in_array(auth()->user()?->user_type, ['city_manager', 'call_center']);
    }

    public static function getNavigationBadge(): ?string
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();
        if ($row && isset($row->last_error_at) && $row->last_error_at) return 'LỖI';
        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public ?string $access_token  = null;
    public ?string $refresh_token = null;
    public int     $expires_in    = 86400;

    public function mount(): void
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();
        if ($row) {
            $this->access_token  = $row->access_token;
            $this->refresh_token = $row->refresh_token;
        }
    }

    public function form(Form $form): Form
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        return $form
            ->schema([
                Section::make('Trạng thái')
                    ->schema([
                        Placeholder::make('status_info')
                            ->label('')
                            ->content(fn () => new HtmlString($this->buildStatusHtml($row))),
                    ]),

                Section::make('Nhập Token mới')
                    ->description('Chỉ cần nhập 1 lần đầu. Sau đó hệ thống tự refresh mỗi 24h.')
                    ->schema([
                        TextInput::make('access_token')
                            ->label('Access Token')
                            ->required()
                            ->password()
                            ->revealable()
                            ->maxLength(2048)
                            ->placeholder('Dán access token từ Zalo Developer Console'),

                        TextInput::make('refresh_token')
                            ->label('Refresh Token')
                            ->required()
                            ->password()
                            ->revealable()
                            ->maxLength(2048)
                            ->placeholder('Dán refresh token từ Zalo Developer Console'),

                        TextInput::make('expires_in')
                            ->label('Thời hạn (giây)')
                            ->numeric()
                            ->default(86400)
                            ->helperText('Zalo access token = 86400s (24h). Không cần đổi giá trị này.'),
                    ]),
            ])
            ->statePath('');
    }

    public function save(): void
    {
        $this->validate([
            'access_token'  => 'required|string|min:10',
            'refresh_token' => 'required|string|min:10',
            'expires_in'    => 'required|integer|min:60',
        ]);

        $payload = [
            'access_token'      => $this->access_token,
            'refresh_token'     => $this->refresh_token,
            'expires_at'        => now()->addSeconds($this->expires_in),
            'last_error'        => null,
            'last_error_at'     => null,
            'last_refreshed_at' => now(),
            'updated_at'        => now(),
        ];

        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();
        if ($row) {
            DB::table('zalo_tokens')->where('id', $row->id)->update($payload);
        } else {
            DB::table('zalo_tokens')->insert($payload + ['created_at' => now()]);
        }

        Notification::make()
            ->title('Đã lưu token thành công')
            ->body('Hệ thống sẽ tự động refresh mỗi 24h.')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('force_refresh')
                ->label('Thử Refresh Ngay')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Refresh Access Token')
                ->modalDescription('Dùng refresh token để lấy access token mới từ Zalo. Tiếp tục?')
                ->action(function () {
                    $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

                    if (!$row) {
                        Notification::make()->title('Chưa có token trong DB')->danger()->send();
                        return;
                    }

                    if (ZaloTokenService::refresh($row)) {
                        Notification::make()
                            ->title('Refresh thành công')
                            ->body('Access token + refresh token mới đã được lưu.')
                            ->success()
                            ->send();
                        $this->redirect(static::getUrl());
                    } else {
                        Notification::make()
                            ->title('Refresh thất bại')
                            ->body('Refresh token có thể đã hết hạn. Cần nhập token mới từ developers.zalo.me.')
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    private function buildStatusHtml(?object $row): string
    {
        // Chưa có token
        if (!$row) {
            return $this->alertHtml('warning',
                '⚠ Chưa có token',
                'Nhập access token + refresh token lần đầu ở bên dưới. Sau đó hệ thống hoàn toàn tự động.'
            );
        }

        $html = '';

        // Cảnh báo lỗi nếu refresh thất bại gần đây
        if (isset($row->last_error_at) && $row->last_error_at) {
            $errorTime = \Carbon\Carbon::parse($row->last_error_at)->format('d/m/Y H:i');
            $html .= $this->alertHtml('danger',
                "🚨 Tự động refresh thất bại lúc {$errorTime}",
                "Lý do: <code>" . e($row->last_error) . "</code><br><br>" .
                "Refresh token có thể đã hết hạn (~3 tháng). Cần:<br>" .
                "1. Vào <a href='https://developers.zalo.me' target='_blank' style='color:inherit;text-decoration:underline'>developers.zalo.me</a> → lấy token mới<br>" .
                "2. Dán vào form bên dưới → Lưu<br>" .
                "<em style='color:#dc2626'>Trong thời gian này OTP sẽ KHÔNG gửi được tới người dùng!</em>"
            );
        }

        // Trạng thái access token
        $minutesLeft = $row->expires_at ? now()->diffInMinutes($row->expires_at, false) : null;
        $expiresAt   = $row->expires_at ? \Carbon\Carbon::parse($row->expires_at)->format('d/m/Y H:i') : '—';
        $lastRefresh = isset($row->last_refreshed_at) && $row->last_refreshed_at
            ? \Carbon\Carbon::parse($row->last_refreshed_at)->format('d/m/Y H:i')
            : '—';

        if ($minutesLeft === null || $minutesLeft <= 0) {
            $html .= $this->alertHtml('danger', '🔴 Access token đã hết hạn',
                "Hệ thống đang thử refresh tự động. Nếu tiếp tục lỗi, nhấn <strong>Thử Refresh Ngay</strong>."
            );
        } elseif ($minutesLeft <= 60) {
            $html .= $this->alertHtml('warning', "🟡 Access token còn {$minutesLeft} phút",
                'Scheduler sẽ tự refresh trong vài phút tới.'
            );
        } else {
            $hours = round($minutesLeft / 60, 1);
            $html .= $this->alertHtml('success', "🟢 Access token hợp lệ — còn {$hours} giờ",
                "Hết hạn lúc: <strong>{$expiresAt}</strong> · Refresh lần cuối: <strong>{$lastRefresh}</strong>"
            );
        }

        // Giải thích cơ chế
        $html .= "<div style='margin-top:12px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#475569'>
            <strong>Cơ chế tự động:</strong><br>
            • Scheduler kiểm tra mỗi <strong>1 giờ</strong><br>
            • Khi access token còn &lt; 30 phút → tự gọi Zalo API lấy token mới (dùng refresh token)<br>
            • Zalo trả về access token MỚI (24h) + refresh token MỚI (3 tháng) → lưu cả 2 vào DB<br>
            • Chu kỳ lặp lại → <strong>không bao giờ hết hạn</strong> miễn scheduler đang chạy<br>
            • Chỉ cần nhập lại thủ công khi cả 2 token đều bị vô hiệu hóa (hiếm gặp)
        </div>";

        return $html;
    }

    private function alertHtml(string $type, string $title, string $body): string
    {
        $styles = [
            'success' => ['bg' => '#f0fdf4', 'border' => '#86efac', 'text' => '#166534'],
            'warning' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'text' => '#92400e'],
            'danger'  => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'text' => '#b91c1c'],
        ];
        $s = $styles[$type] ?? $styles['warning'];

        return "<div style='margin-bottom:10px;padding:14px;background:{$s['bg']};border:1px solid {$s['border']};border-radius:8px;color:{$s['text']}'>
            <div style='font-weight:600;margin-bottom:4px'>{$title}</div>
            <div style='font-size:13px'>{$body}</div>
        </div>";
    }
}
