<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Services\DispatchMonitorReport;
use App\Services\DriverSupplyService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Pages\Page;
use Livewire\Attributes\On;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderTimeline;

class DispatchMonitorPage extends Page
{
    public static function canAccess(): bool
    {
        // Tổng đài cần theo dõi tiến trình phát để xử lý đơn chờ lâu.
        // Dữ liệu trên trang luôn bị khóa theo tenant/khu vực hiện tại.
        return true;
    }

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Vận hành đơn hàng';

    protected static ?string $navigationLabel = 'Theo dõi phát đơn';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'theo-doi-phat-don';

    protected static ?string $title = 'Theo dõi phát đơn';

    protected static string $view = 'filament.pages.dispatch-monitor';

    public function getHeading(): string
    {
        return '';
    }

    #[On('echo:dispatch-monitor,.state.changed')]
    public function refresh(): void {}

    /** Màu + nhãn cho từng kết quả offer — khớp đúng 4 giá trị thật của enum order_dispatch_logs.result. */
    public static function offerResultConfig(string $result): array
    {
        return match ($result) {
            'accepted' => ['color' => '#22c55e', 'label' => 'Nhận'],
            'declined' => ['color' => '#ef4444', 'label' => 'Từ chối'],
            'expired' => ['color' => '#9ca3af', 'label' => 'Hết hạn'],
            default => ['color' => '#f59e0b', 'label' => 'Đang chờ'], // 'pending'
        };
    }

    /** Luôn khoá theo đúng khu vực (tenant) đang đứng — đổi khu vực thì dùng bộ chuyển tenant trên topbar. */
    private function effectiveCityId(): ?int
    {
        return Filament::getTenant()?->id;
    }

    /** Nhóm tài xế đang mở rộng ở khối "Lực lượng tài xế" (ready|busy1|busy2|holding|dead). */
    public ?string $segment = null;

    /** Bộ lọc kết quả của "Offer gần đây". */
    public string $offerFilter = 'all';

    public const OFFER_FILTERS = ['all' => 'Tất cả', 'accepted' => 'Nhận', 'declined' => 'Từ chối', 'expired' => 'Hết hạn', 'pending' => 'Đang chờ'];

    public function toggleSegment(string $key): void
    {
        $this->segment = $this->segment === $key ? null : $key;
    }

    public function setOfferFilter(string $key): void
    {
        $this->offerFilter = array_key_exists($key, self::OFFER_FILTERS) ? $key : 'all';
    }

    private function report(): DispatchMonitorReport
    {
        return app(DispatchMonitorReport::class);
    }

    /** @return array{attention: array, active: array} */
    public function getOrders(): array
    {
        return $this->report()->orders($this->effectiveCityId(), $this->getTimeoutSeconds());
    }

    /** Lực lượng tài xế lúc này, kèm danh sách từng nhóm để bấm vào xem. */
    public function getDriverSupply(): array
    {
        return app(DriverSupplyService::class)->snapshotWithDrivers($this->effectiveCityId());
    }

    public function getRecentOffers(): array
    {
        return $this->report()->recentOffers($this->effectiveCityId(), $this->offerFilter);
    }

    /** @return array{today: array, yesterday: array} cùng khoảng giờ để so sánh công bằng */
    public function getStats(): array
    {
        return $this->report()->todayVsYesterday($this->effectiveCityId());
    }

    public function getTopDecliners(): array
    {
        return $this->report()->topDecliners($this->effectiveCityId());
    }

    public function getHourly(): array
    {
        return $this->report()->hourly($this->effectiveCityId());
    }

    /** Giới hạn thời gian tìm tài xế của khu vực (giây): căn cứ tô màu cảnh báo đơn đang phát. */
    public function getTimeoutSeconds(): int
    {
        return max(60, OperationalSettings::dispatchTimeoutMinutes($this->effectiveCityId()) * 60);
    }

    // ─── Thao tác ngay trên đơn đang phát ────────────────────────────────────

    private function pendingOrder(array $arguments): ?Order
    {
        return Order::where('city_id', $this->effectiveCityId())->find($arguments['order'] ?? 0);
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Gán tài xế')
            ->icon('heroicon-o-user-plus')
            ->color('info')
            ->modalHeading(fn (array $arguments) => ($o = $this->pendingOrder($arguments)) ? 'Gán tài xế cho đơn #'.$o->code : 'Gán tài xế')
            ->modalDescription('Đơn sẽ ngừng tìm tự động và chuyển thẳng vào danh sách đã nhận của tài xế.')
            ->form(fn (array $arguments) => ($o = $this->pendingOrder($arguments)) ? OrderResource::manualAssignmentForm($o) : [])
            ->action(fn (array $arguments, array $data) => OrderResource::assignDriverManually($this->pendingOrder($arguments) ?? new Order, $data));
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Hủy đơn')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(fn (array $arguments) => ($o = $this->pendingOrder($arguments)) ? 'Hủy đơn #'.$o->code : 'Hủy đơn')
            ->form([
                Forms\Components\Select::make('reason')->label('Lý do hủy')->options(OrderTimeline::CANCEL_REASONS)->required()->live(),
                Forms\Components\Textarea::make('note')->label('Ghi chú')->rows(2)->maxLength(250)->required(fn (Forms\Get $get) => $get('reason') === 'other')->minLength(3),
            ])
            ->action(fn (array $arguments, array $data) => OrderResource::cancelOrder($this->pendingOrder($arguments), $data['reason'], $data['note'] ?? null));
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
