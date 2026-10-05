<?php

namespace App\Filament\Pages;

use App\Jobs\SendNotificationCampaignJob;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;

class SendNotificationPage extends Page implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Khách hàng';

    protected static ?string $navigationLabel = 'Gửi thông báo';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.send-notification';

    public function getTitle(): string
    {
        return 'Gửi thông báo';
    }

    public function getSubheading(): ?string
    {
        return 'Gửi thông báo tới khách hàng trên app: push và hộp thư trong app. Thông báo đã gửi không thể thu hồi.';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    private function defaults(): array
    {
        return ['scope' => 'city', 'send_mode' => 'now', 'title' => null, 'body' => null, 'scheduled_at' => null];
    }

    /** Chỉ admin/subadmin gửi được cho khách toàn hệ thống. */
    private function canManageAllCities(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin']);
    }

    private function cityId(): ?int
    {
        return Filament::getTenant()?->id;
    }

    /** Phạm vi thực sự được dùng: người không đủ quyền luôn bị ép về khu vực đang chọn, bất kể form gửi lên gì. */
    private function effectiveScope(?string $scope): string
    {
        return ($scope === 'all' && $this->canManageAllCities()) ? 'all' : 'city';
    }

    private function service(): NotificationCampaignService
    {
        return app(NotificationCampaignService::class);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(['default' => 1, 'xl' => 3])->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Nội dung thông báo')
                            ->description('Ngắn gọn: push thường bị cắt nếu quá dài')
                            ->icon('heroicon-o-chat-bubble-bottom-center-text')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Tiêu đề')
                                    ->required()
                                    ->maxLength(65)
                                    ->live(debounce: 400)
                                    ->placeholder('VD: Ưu đãi đặc biệt hôm nay!')
                                    ->helperText(fn (Forms\Get $get): string => mb_strlen((string) $get('title')).'/65 ký tự'),

                                Forms\Components\Textarea::make('body')
                                    ->label('Nội dung')
                                    ->required()
                                    ->rows(3)
                                    ->maxLength(300)
                                    ->live(debounce: 400)
                                    ->helperText(fn (Forms\Get $get): string => mb_strlen((string) $get('body')).'/300 ký tự'),
                            ]),

                        Forms\Components\Section::make('Gửi tới ai, khi nào')
                            ->icon('heroicon-o-user-group')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Radio::make('scope')
                                    ->label('Khách hàng')
                                    ->options(fn (): array => array_filter([
                                        'city' => 'Chỉ '.(Filament::getTenant()?->name ?? 'khu vực này'),
                                        'all' => $this->canManageAllCities() ? 'Tất cả khu vực' : null,
                                    ]))
                                    ->default('city')
                                    ->required()
                                    ->live(),

                                Forms\Components\Radio::make('send_mode')
                                    ->label('Thời điểm gửi')
                                    ->options(['now' => 'Gửi ngay', 'later' => 'Hẹn giờ'])
                                    ->default('now')
                                    ->required()
                                    ->live(),

                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->label('Gửi vào lúc')
                                    ->native(false)
                                    ->seconds(false)
                                    ->displayFormat('d/m/Y H:i')
                                    ->minDate(now())
                                    ->visible(fn (Forms\Get $get): bool => $get('send_mode') === 'later')
                                    ->required(fn (Forms\Get $get): bool => $get('send_mode') === 'later')
                                    ->columnSpanFull(),
                            ]),
                    ])->columnSpan(['xl' => 2]),

                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Xem trước')
                            ->description('Cách khách thấy thông báo')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('preview')
                                    ->hiddenLabel()
                                    ->content(function (Forms\Get $get): HtmlString {
                                        $title = e($get('title') ?: 'Tiêu đề thông báo');
                                        $body = e($get('body') ?: 'Nội dung thông báo sẽ hiện ở đây.');

                                        return new HtmlString(
                                            '<p class="fs-nt-cap">Thông báo đẩy</p>'
                                            .'<div class="fs-nt-push"><span class="fs-nt-icon">F</span><div><small>FLASHSHIP · bây giờ</small><b>'.$title.'</b><span>'.$body.'</span></div></div>'
                                            .'<p class="fs-nt-cap">Hộp thư trong app</p>'
                                            .'<div class="fs-nt-inbox"><i></i><div><b>'.$title.'</b><span>'.$body.'</span><small>Vừa xong</small></div></div>'
                                        );
                                    }),
                            ]),

                        Forms\Components\Section::make('Người nhận dự kiến')
                            ->icon('heroicon-o-users')
                            ->schema([
                                Forms\Components\Placeholder::make('recipients')
                                    ->hiddenLabel()
                                    ->content(function (Forms\Get $get): HtmlString {
                                        $counts = $this->service()->counts($this->effectiveScope($get('scope')), $this->cityId());
                                        $pct = $counts['inbox'] ? round($counts['push'] / $counts['inbox'] * 100) : 0;

                                        return new HtmlString(
                                            '<div class="fs-nt-recipients"><div><strong>'.number_format($counts['inbox'], 0, ',', '.').'</strong><span>khách nhận trong hộp thư</span></div>'
                                            .'<div><strong>'.number_format($counts['push'], 0, ',', '.').'</strong><span>có bật push ('.$pct.'%)</span></div></div>'
                                            .'<p class="fs-nt-cap">Khách không bật push vẫn thấy thông báo khi mở app.</p>'
                                        );
                                    }),
                            ]),
                    ])->columnSpan(['xl' => 1]),
                ]),
            ])
            ->statePath('data');
    }

    private function formReady(): bool
    {
        return filled($this->data['title'] ?? null) && filled($this->data['body'] ?? null);
    }

    public function sendAction(): Action
    {
        return Action::make('send')
            ->label(fn (): string => ($this->data['send_mode'] ?? 'now') === 'later' ? 'Hẹn giờ gửi' : 'Gửi thông báo')
            ->icon('heroicon-o-paper-airplane')
            ->size('lg')
            ->disabled(fn (): bool => ! $this->formReady())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận gửi thông báo')
            ->modalDescription(function (): HtmlString {
                $counts = $this->service()->counts($this->effectiveScope($this->data['scope'] ?? 'city'), $this->cityId());
                $where = $this->effectiveScope($this->data['scope'] ?? 'city') === 'all' ? 'tất cả khu vực' : (Filament::getTenant()?->name ?? 'khu vực này');
                $when = ($this->data['send_mode'] ?? 'now') === 'later' && filled($this->data['scheduled_at'] ?? null)
                    ? 'hẹn lúc '.Carbon::parse($this->data['scheduled_at'])->format('H:i d/m/Y')
                    : 'gửi ngay';

                return new HtmlString(
                    '<b>'.e($this->data['title'] ?? '').'</b><br>'
                    .'Gửi tới <b>'.number_format($counts['inbox'], 0, ',', '.').' khách</b> ('.e($where).'), '.e($when).'.<br>'
                    .'<span>Thông báo đã gửi không thể thu hồi.</span>'
                );
            })
            ->modalSubmitActionLabel('Gửi')
            ->action(fn () => $this->send());
    }

    public function testAction(): Action
    {
        return Action::make('test')
            ->label('Gửi thử cho 1 khách')
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->size('lg')
            ->disabled(fn (): bool => ! $this->formReady())
            ->modalHeading('Gửi thử cho một khách')
            ->modalDescription('Nội dung đang soạn sẽ gửi tới đúng một khách (nhập số điện thoại, ví dụ tài khoản của nhân viên). Khách đó nhận thông báo thật, và lần gửi thử không được ghi vào lịch sử chiến dịch.')
            ->modalSubmitActionLabel('Gửi thử')
            ->form([
                Forms\Components\TextInput::make('phone')->label('Số điện thoại khách')->tel()->required(),
            ])
            ->action(fn (array $data) => $this->sendTest($data['phone']));
    }

    public function sendTest(string $phone): void
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last9 = substr($digits, -9);
        $customer = strlen($last9) === 9
            ? User::query()->where('user_type', 'customer')->where('status', 1)
                ->whereIn('phone', ['0'.$last9, '84'.$last9, '+84'.$last9, $last9])
                ->when(! $this->canManageAllCities(), fn ($q) => $q->where('city_id', $this->cityId()))
                ->first()
            : null;

        if (! $customer) {
            Notification::make()->warning()->title('Không tìm thấy khách')
                ->body('Không có khách đang hoạt động nào dùng số này'.($this->canManageAllCities() ? '.' : ' trong khu vực của bạn.'))->send();

            return;
        }

        $title = (string) ($this->data['title'] ?? '');
        $body = (string) ($this->data['body'] ?? '');
        CustomerNotificationController::create($customer->id, $title, $body);

        $pushed = false;
        if (filled($customer->fcm_token)) {
            try {
                $pushed = FCMService::getInstance()->broadcast([$customer->fcm_token], $title, $body)['sent'] > 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Notification::make()->success()->title('Đã gửi thử cho '.$customer->name)
            ->body($pushed ? 'Khách nhận cả push và thông báo trong hộp thư.' : 'Khách nhận thông báo trong hộp thư (không gửi được push: chưa có token hoặc lỗi gửi).')->send();
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $scope = $this->effectiveScope($data['scope'] ?? 'city');
        $later = ($data['send_mode'] ?? 'now') === 'later';
        $at = $later ? Carbon::parse($data['scheduled_at']) : null;

        if ($later && $at->lte(now())) {
            Notification::make()->warning()->title('Giờ hẹn đã qua')->body('Hãy chọn thời điểm trong tương lai.')->send();

            return;
        }

        $campaign = NotificationCampaign::create([
            'title' => $data['title'], 'body' => $data['body'],
            'scope' => $scope, 'city_id' => $scope === 'all' ? null : $this->cityId(),
            'created_by' => auth()->id(),
            'status' => $later ? 'scheduled' : 'queued',
            'scheduled_at' => $at,
        ]);

        if (! $later) {
            SendNotificationCampaignJob::dispatch($campaign->id);
        }

        Notification::make()->success()
            ->title($later ? 'Đã hẹn giờ gửi' : 'Đã đưa vào hàng đợi gửi')
            ->body($later ? 'Sẽ gửi lúc '.$at->format('H:i d/m/Y').'. Theo dõi ở bảng lịch sử bên dưới.' : 'Hệ thống đang gửi ở chế độ nền. Theo dõi kết quả ở bảng lịch sử bên dưới.')
            ->send();

        $this->form->fill($this->defaults());
    }

    /** Chỉ số tổng quan đầu trang. */
    public function getStatsProperty(): array
    {
        $cityId = $this->cityId();
        $counts = $this->service()->counts('city', $cityId);
        $recent = NotificationCampaign::query()->where('status', 'sent')->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('scope', 'all')->orWhere('city_id', $cityId));
        $ids = (clone $recent)->pluck('id');
        $inbox = (int) (clone $recent)->sum('inbox_count');
        $reads = $ids->isEmpty() ? 0 : (int) DB::table('customer_notifications')->whereIn('campaign_id', $ids)->where('is_read', true)->count();

        return [
            'customers' => $counts['inbox'], 'push' => $counts['push'],
            'pushRate' => $counts['inbox'] ? round($counts['push'] / $counts['inbox'] * 100) : 0,
            'campaigns' => $ids->count(),
            'openRate' => $inbox ? round($reads / $inbox * 100, 1) : null,
            'scheduled' => NotificationCampaign::where('status', 'scheduled')->where(fn ($q) => $q->where('scope', 'all')->orWhere('city_id', $cityId))->count(),
        ];
    }

    private function campaignQuery(): Builder
    {
        $cityId = $this->cityId();

        return NotificationCampaign::query()
            ->with(['creator:id,name', 'city:id,name'])
            ->select('notification_campaigns.*')
            ->addSelect(['reads' => DB::table('customer_notifications')->selectRaw('COALESCE(SUM(is_read), 0)')->whereColumn('campaign_id', 'notification_campaigns.id')])
            ->where(fn (Builder $q) => $this->canManageAllCities()
                ? $q->where('scope', 'all')->orWhere('city_id', $cityId)
                : $q->where('city_id', $cityId));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->campaignQuery())
            ->heading('Lịch sử chiến dịch')
            ->description('Các thông báo đã gửi và hẹn giờ cho khách hàng')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->state(fn (NotificationCampaign $r) => ($r->status === 'scheduled' ? $r->scheduled_at : $r->created_at)?->format('H:i d/m/Y'))
                    ->description(fn (NotificationCampaign $r): string => ($r->status === 'scheduled' ? 'hẹn giờ · ' : '').($r->creator?->name ?? '—'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Nội dung')
                    ->searchable()
                    ->weight('bold')
                    ->limit(48)
                    ->description(fn (NotificationCampaign $r): string => \Illuminate\Support\Str::limit($r->body, 70))
                    ->wrap(),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Gửi tới')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state, NotificationCampaign $r): string => $state === 'all' ? 'Tất cả khu vực' : ($r->city?->name ?? '—')),

                Tables\Columns\TextColumn::make('inbox_count')
                    ->label('Người nhận')
                    ->state(fn (NotificationCampaign $r): string => in_array($r->status, ['sent', 'sending', 'failed']) ? number_format($r->inbox_count, 0, ',', '.').' khách' : '—')
                    ->description(fn (NotificationCampaign $r): ?string => $r->status === 'sent' || $r->push_target > 0
                        ? 'Push: '.number_format($r->push_sent, 0, ',', '.').'/'.number_format($r->push_target, 0, ',', '.').($r->push_failed ? ' · lỗi '.$r->push_failed : '')
                        : null),

                Tables\Columns\TextColumn::make('open_rate')
                    ->label('Đã mở')
                    ->state(fn (NotificationCampaign $r): string => $r->inbox_count > 0 ? round(((int) $r->reads) / $r->inbox_count * 100, 1).'%' : '—')
                    ->description(fn (NotificationCampaign $r): ?string => $r->inbox_count > 0 ? number_format((int) $r->reads, 0, ',', '.').' khách' : null)
                    ->tooltip('Tỷ lệ khách đã mở thông báo trong hộp thư của app'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => NotificationCampaign::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => NotificationCampaign::STATUS_COLORS[$state] ?? 'gray')
                    ->tooltip(fn (NotificationCampaign $r): ?string => $r->status === 'failed' ? $r->error : null),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->label('Gửi lại')
                    ->icon('heroicon-m-arrow-path')
                    ->action(function (NotificationCampaign $record): void {
                        $this->form->fill([
                            'title' => $record->title, 'body' => $record->body,
                            'scope' => $record->scope === 'all' && $this->canManageAllCities() ? 'all' : 'city',
                            'send_mode' => 'now', 'scheduled_at' => null,
                        ]);
                        $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');
                        Notification::make()->info()->title('Đã nạp nội dung vào form phía trên')->body('Kiểm tra lại rồi bấm gửi.')->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Hủy')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (NotificationCampaign $r): bool => $r->status === 'scheduled')
                    ->requiresConfirmation()
                    ->modalHeading('Hủy chiến dịch hẹn giờ?')
                    ->modalDescription('Thông báo sẽ không được gửi.')
                    ->action(function (NotificationCampaign $record): void {
                        // Chỉ hủy khi còn đang hẹn giờ, tránh hủy nhầm chiến dịch vừa chuyển vào hàng đợi.
                        $done = NotificationCampaign::where('id', $record->id)->where('status', 'scheduled')->update(['status' => 'cancelled']);
                        Notification::make()->{$done ? 'success' : 'warning'}()
                            ->title($done ? 'Đã hủy chiến dịch' : 'Không hủy được: chiến dịch đã bắt đầu gửi')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->emptyStateHeading('Chưa gửi chiến dịch nào')
            ->emptyStateDescription('Các thông báo bạn gửi sẽ hiện ở đây cùng kết quả và tỷ lệ mở.')
            ->paginated([10, 25]);
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
