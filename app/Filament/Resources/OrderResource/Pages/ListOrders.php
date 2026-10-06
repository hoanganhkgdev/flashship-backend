<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Tables\Table;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OrderResource::class;

    public function getHeading(): string
    {
        return 'Quản lý đơn hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi, xử lý và truy vết mọi thay đổi của đơn hàng trong khu vực. Đơn chỉ được hủy, không xóa.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Xuất CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->user_type === 'admin')
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
            \App\Filament\Resources\OrderResource\Widgets\OrderOpsWidget::class,
            \App\Filament\Resources\OrderResource\Widgets\OrderListSummaryWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /** Chế độ trực: chỉ các tab đang chờ/đang chạy tự làm mới (15 giây); tab lịch sử thì không. */
    public function table(Table $table): Table
    {
        return parent::table($table)->poll(in_array($this->activeTab, ['new', 'attention', 'processing'], true) ? '15s' : null);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'new';
    }

    /** Số đơn theo từng tab, tính bằng đúng một truy vấn cho mỗi lần hiển thị. */
    private function counts(): array
    {
        static $memo = [];
        $key = \Filament\Facades\Filament::getTenant()?->getKey() ?? 0;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $cut = now()->subMinutes(self::attentionMinutes());
        $r = OrderResource::getEloquentQuery()->toBase()->reorder()->selectRaw(
            "COUNT(*) total, SUM(status = 'pending') pending, SUM(status IN ('assigned','processing')) active,
             SUM(status = 'completed') completed, SUM(status = 'cancelled') cancelled,
             SUM(status = 'pending' AND (cancel_reason = 'no_driver' OR created_at <= ?)) attention", [$cut]
        )->first();

        return $memo[$key] = collect((array) $r)->map(fn ($v) => (int) $v)->all();
    }

    public function getTabs(): array
    {
        $c = fn (string $k) => fn () => $this->counts()[$k] ?: null;
        $attention = fn (Builder $query) => $query->where('status', 'pending')
            ->where(fn ($q) => $q->where('cancel_reason', 'no_driver')->orWhere('created_at', '<=', now()->subMinutes(self::attentionMinutes())));

        return [
            'all' => Tab::make('Tất cả')->icon('heroicon-m-queue-list')->badge($c('total')),
            // Chế độ trực: chờ lâu nhất lên đầu.
            'new' => Tab::make('Đơn mới')->icon('heroicon-m-bell-alert')->badge($c('pending'))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')->orderBy('created_at')),
            'attention' => Tab::make('Cần xử lý')->icon('heroicon-m-exclamation-triangle')->badge($c('attention'))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $attention($query)->orderBy('created_at')),
            'processing' => Tab::make('Đang xử lý')->icon('heroicon-m-clock')->badge($c('active'))->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['assigned', 'processing'])->orderByDesc('created_at')),
            'completed' => Tab::make('Hoàn thành')->icon('heroicon-m-check-circle')->badge($c('completed'))->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),
            'cancelled' => Tab::make('Đã hủy')->icon('heroicon-m-x-circle')->badge($c('cancelled'))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }

    /** Đơn chờ quá thời gian tìm tài xế (cấu hình khu vực) thì cần xử lý. */
    public static function attentionMinutes(): int
    {
        return \Modules\Core\Services\OperationalSettings::dispatchTimeoutMinutes(\Filament\Facades\Filament::getTenant()?->id);
    }

    private const EXPORT_LIMIT = 20000;

    /** Xuất đúng kết quả đang lọc/tìm/tab (tối đa 20.000 đơn). Chỉ quản trị viên đầy đủ. */
    public function exportCsv()
    {
        abort_unless(auth()->user()?->user_type === 'admin', 403);

        $query = $this->getFilteredSortedTableQuery()->with(['city:id,name', 'driver:id,name,phone', 'sender:id,name,phone', 'creator:id,name'])->limit(self::EXPORT_LIMIT);
        $statuses = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy'];

        return response()->streamDownload(function () use ($query, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
            fputcsv($out, ['Mã đơn', 'Tạo lúc', 'Dịch vụ', 'Nguồn', 'Người tạo', 'Khách', 'SĐT khách', 'Điểm lấy', 'Điểm giao', 'Phí ship', 'Thu hộ', 'Thanh toán', 'Trạng thái', 'Lý do hủy', 'Tài xế', 'SĐT tài xế', 'Hoàn thành lúc']);
            foreach ($query->cursor() as $o) {
                fputcsv($out, [
                    $o->code, $o->created_at?->format('Y-m-d H:i:s'), $o->service_type, $o->platform, $o->creator?->name,
                    \App\Support\OrderListPresenter::customerName($o), \App\Support\OrderListPresenter::contactPhone($o),
                    $o->pickup_address, $o->delivery_address, (int) $o->shipping_fee, (int) $o->cod_amount, $o->payment_method,
                    $statuses[$o->status] ?? $o->status, $o->status === 'cancelled' ? \Modules\Order\Services\OrderTimeline::cancelReasonLabel($o->cancel_reason) : '',
                    $o->driver?->name, $o->driver?->phone, $o->completed_at?->format('Y-m-d H:i:s'),
                ]);
            }
            fclose($out);
        }, 'don-hang_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
