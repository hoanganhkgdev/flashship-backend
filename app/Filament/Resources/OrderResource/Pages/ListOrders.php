<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Widgets\OrderListSummaryWidget;
use App\Filament\Resources\OrderResource\Widgets\OrderOpsWidget;
use App\Support\AdminAccess;
use App\Support\OrderListPresenter;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Modules\Order\Services\OrderTimeline;

class ListOrders extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.resources.order-resource.pages.list-orders';

    /**
     * Danh sách tự làm mới 15 giây. Nếu đơn đang mở ở cửa sổ xem nhanh/gán/hủy vừa được gán, hủy hoặc chuyển tab,
     * bản ghi không còn trong bảng và mọi closure nhận Order sẽ nhận null → lỗi 500 cho cả trang.
     * Gặp tình huống đó thì đóng cửa sổ và báo, thay vì để trang vỡ. Làm ở bước vẽ lại vì lúc này bảng đã khởi tạo xong.
     */
    public function rendering(): void
    {
        if ($this->mountedTableActions && $this->getMountedTableActionRecordKey() !== null && ! $this->getMountedTableActionRecord()) {
            $this->resetMountedTableActionProperties();
            $this->mountedTableActionRecord = null;
            $this->closeTableActionModal();

            Notification::make()->title('Đơn vừa đổi trạng thái nên đã rời khỏi danh sách — cửa sổ đang mở được đóng lại.')->warning()->send();
        }
    }

    public function getHeading(): string
    {
        return 'Đơn hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi và xử lý đơn theo thời gian thực trong khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Xuất CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => AdminAccess::allows(auth()->user(), AdminAccess::EXPORT_DATA))
                ->action(fn () => $this->exportCsv()),
            Actions\Action::make('createFromCallCenter')
                ->label('Tạo đơn mới')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => CallCenterPage::canAccess())
                ->url(CallCenterPage::getUrl()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderOpsWidget::class,
            OrderListSummaryWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /** Chế độ trực: chỉ các tab đang chờ/đang chạy tự làm mới (15 giây); tab lịch sử thì không. */
    public function table(Table $table): Table
    {
        return parent::table($table)->poll(in_array($this->activeTab, ['new', 'processing'], true) ? '15s' : null);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'new';
    }

    protected function configureViewAction(ViewAction $action): void
    {
        parent::configureViewAction($action);

        // Filament gắn sẵn link sang trang chi tiết khi resource có trang View; "Xem nhanh" phải mở popup
        // (trang đầy đủ đã có mục "Mở trang đầy đủ" riêng trong menu).
        $action
            ->url(null)
            ->modalWidth('4xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->infolist(fn (Infolist $infolist): Infolist => OrderResource::infolist($infolist));
    }

    /**
     * Số đơn theo từng tab. Bảng tự làm mới 15s và mỗi admin đang mở trang đều gọi: đếm toàn bộ lịch sử đơn của khu vực
     * nên dùng chung kết quả 10 giây cho cả khu vực thay vì quét lại mỗi lần.
     */
    private function counts(): array
    {
        static $memo = [];
        $key = Filament::getTenant()?->getKey() ?? 0;

        return $memo[$key] ??= Cache::remember("orders:tab-counts:{$key}", 10, function () {
            $r = OrderResource::getEloquentQuery()->toBase()->reorder()->selectRaw(
                "COUNT(*) total,
                 SUM(status = 'pending') pending,
                 SUM(status IN ('assigned','processing')) active,
                 SUM(status = 'completed') completed, SUM(status = 'cancelled') cancelled"
            )->first();

            return collect((array) $r)->map(fn ($v) => (int) $v)->all();
        });
    }

    public function getTabs(): array
    {
        $c = fn (string $k) => fn () => $this->counts()[$k] ?: null;

        return [
            'all' => Tab::make('Tất cả')->icon('heroicon-m-queue-list')->badge($c('total')),
            // Chế độ trực: chờ lâu nhất lên đầu.
            'new' => Tab::make('Chờ nhận')->icon('heroicon-m-bell-alert')->badge($c('pending'))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')->orderBy('created_at')),
            'processing' => Tab::make('Đang xử lý')->icon('heroicon-m-clock')->badge($c('active'))->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing'])->orderByDesc('created_at')),
            'completed' => Tab::make('Hoàn thành')->icon('heroicon-m-check-circle')->badge($c('completed'))->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),
            'cancelled' => Tab::make('Đã hủy')->icon('heroicon-m-x-circle')->badge($c('cancelled'))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }

    private const EXPORT_LIMIT = 20000;

    /**
     * Chặn công thức Excel trong dữ liệu khách nhập (tên, địa chỉ...): ô bắt đầu bằng = + - @ hoặc tab/xuống dòng
     * được thêm dấu ' để Excel coi là chữ. Số giữ nguyên để vẫn cộng được.
     */
    private static function csvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return "'".$value;
    }

    /** Xuất đúng kết quả đang lọc/tìm/tab (tối đa 20.000 đơn). Chỉ quản trị viên đầy đủ. */
    public function exportCsv()
    {
        abort_unless(AdminAccess::allows(auth()->user(), AdminAccess::EXPORT_DATA), 403);

        // lazy() nạp quan hệ theo từng lô (cursor() thì không → ~3 truy vấn mỗi dòng). lazy() tự phân trang nên
        // giới hạn số dòng bằng take() thay vì limit().
        $query = $this->getFilteredSortedTableQuery()->with(['city:id,name', 'driver:id,name,phone', 'sender:id,name,phone', 'creator:id,name']);
        $statuses = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy'];

        return response()->streamDownload(function () use ($query, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
            fputcsv($out, ['Mã đơn', 'Tạo lúc', 'Dịch vụ', 'Nguồn', 'Người tạo', 'Khách', 'SĐT khách', 'Điểm lấy', 'Điểm giao', 'Phí ship', 'Thu hộ', 'Thanh toán', 'Trạng thái', 'Lý do hủy', 'Tài xế', 'SĐT tài xế', 'Hoàn thành lúc']);
            foreach ($query->lazy(500)->take(self::EXPORT_LIMIT) as $o) {
                fputcsv($out, array_map(self::csvCell(...), [
                    $o->code, $o->created_at?->format('Y-m-d H:i:s'), $o->service_type, $o->platform, $o->creator?->name,
                    OrderListPresenter::customerName($o), OrderListPresenter::contactPhone($o),
                    $o->pickup_address, $o->delivery_address, (int) $o->shipping_fee, (int) $o->cod_amount, $o->payment_method,
                    $statuses[$o->status] ?? $o->status, $o->status === 'cancelled' ? OrderTimeline::cancelReasonLabel($o->cancel_reason) : '',
                    $o->driver?->name, $o->driver?->phone, $o->completed_at?->format('Y-m-d H:i:s'),
                ]));
            }
            fclose($out);
        }, 'don-hang_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
