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
        $row         = DB::table('zalo_tokens')->orderByDesc('id')->first();
        $minutesLeft = $row?->expires_at ? now()->diffInMinutes($row->expires_at, false) : null;
        $expiresAt   = $row?->expires_at;
        $updatedAt   = $row?->updated_at;

        $statusHtml = $this->buildStatusHtml($minutesLeft, $expiresAt, $updatedAt);

        return $form
            ->schema([
                Section::make('Trạng thái hiện tại')
                    ->schema([
                        Placeholder::make('status_info')
                            ->label('')
                            ->content(fn () => new HtmlString($statusHtml)),
                    ]),

                Section::make('Cập nhật Token mới')
                    ->description('Lấy token mới từ Zalo Developer Console khi refresh token hết hạn (mỗi 3 tháng)')
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
                            ->helperText('Mặc định 86400 = 24 giờ. Access token Zalo hết hạn sau 24h.'),
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
            'access_token'  => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'expires_at'    => now()->addSeconds($this->expires_in),
            'updated_at'    => now(),
        ];

        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();
        if ($row) {
            DB::table('zalo_tokens')->where('id', $row->id)->update($payload);
        } else {
            DB::table('zalo_tokens')->insert($payload + ['created_at' => now()]);
        }

        Notification::make()
            ->title('Đã lưu token thành công')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('force_refresh')
                ->label('Tự động Refresh Token')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Refresh Access Token')
                ->modalDescription('Hệ thống sẽ dùng refresh token để lấy access token mới từ Zalo. Tiếp tục?')
                ->action(function () {
                    $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

                    if (!$row) {
                        Notification::make()
                            ->title('Chưa có token trong DB')
                            ->body('Vui lòng nhập token trước.')
                            ->danger()
                            ->send();
                        return;
                    }

                    if (ZaloTokenService::refresh($row)) {
                        Notification::make()
                            ->title('Refresh thành công')
                            ->body('Access token mới đã được lưu.')
                            ->success()
                            ->send();
                        $this->redirect(static::getUrl());
                    } else {
                        Notification::make()
                            ->title('Refresh thất bại')
                            ->body('Refresh token có thể đã hết hạn. Hãy nhập token mới từ Zalo Developer Console.')
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    private function buildStatusHtml(?int $minutesLeft, ?string $expiresAt, ?string $updatedAt): string
    {
        if ($minutesLeft === null) {
            return '<div style="padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c">
                        <strong>⚠ Chưa có token</strong> — Vui lòng nhập token lần đầu.
                    </div>';
        }

        if ($minutesLeft <= 0) {
            $color = ['bg' => '#fef2f2', 'border' => '#fca5a5', 'text' => '#b91c1c', 'badge' => '#ef4444'];
            $label = 'ĐÃ HẾT HẠN';
            $desc  = 'Token đã hết hạn — nhấn "Tự động Refresh Token" hoặc nhập token mới.';
        } elseif ($minutesLeft <= 30) {
            $color = ['bg' => '#fffbeb', 'border' => '#fcd34d', 'text' => '#92400e', 'badge' => '#f59e0b'];
            $label = 'SẮP HẾT HẠN';
            $hours = round($minutesLeft / 60, 1);
            $desc  = "Còn khoảng {$hours} giờ — hệ thống sẽ tự refresh.";
        } else {
            $color = ['bg' => '#f0fdf4', 'border' => '#86efac', 'text' => '#166534', 'badge' => '#22c55e'];
            $label = 'HỢP LỆ';
            $hours = round($minutesLeft / 60, 1);
            $desc  = "Còn khoảng {$hours} giờ.";
        }

        $expiresFormatted = $expiresAt ? \Carbon\Carbon::parse($expiresAt)->format('d/m/Y H:i') : '—';
        $updatedFormatted = $updatedAt ? \Carbon\Carbon::parse($updatedAt)->format('d/m/Y H:i') : '—';

        return "
        <div style='padding:14px;background:{$color['bg']};border:1px solid {$color['border']};border-radius:8px;color:{$color['text']}'>
            <div style='display:flex;align-items:center;gap:10px;margin-bottom:8px'>
                <span style='background:{$color['badge']};color:#fff;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px'>{$label}</span>
                <span style='font-size:14px'>{$desc}</span>
            </div>
            <div style='font-size:13px;opacity:0.8'>
                Hết hạn lúc: <strong>{$expiresFormatted}</strong> &nbsp;·&nbsp; Cập nhật lần cuối: <strong>{$updatedFormatted}</strong>
            </div>
        </div>
        <div style='margin-top:10px;font-size:12px;color:#6b7280'>
            📋 Lịch tự động: Hệ thống kiểm tra và refresh token mỗi <strong>1 giờ</strong> khi còn dưới 30 phút.
            Khi refresh token hết hạn (~3 tháng), cần nhập token mới từ
            <a href='https://developers.zalo.me' target='_blank' style='color:#f97316'>developers.zalo.me</a>.
        </div>";
    }
}
