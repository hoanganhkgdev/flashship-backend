<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource;
use App\Support\AdminAccess;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Order\Services\OrderTimeline;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.resources.order-resource.pages.edit-order';

    public function getTitle(): string
    {
        return 'Chỉnh sửa đơn #'.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin giao nhận, chi phí và trạng thái đơn hàng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::assignAction(Actions\Action::make('assignDriver'))->after(fn () => $this->record->refresh()),
            Actions\ViewAction::make()
                ->label('Xem chi tiết')
                ->icon('heroicon-o-eye'),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->label('Lưu thay đổi')
            ->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('Huỷ thay đổi');
    }

    private array $before = [];

    private const TRACKED = [
        'pickup_address' => 'Địa chỉ lấy', 'pickup_phone' => 'SĐT lấy', 'delivery_address' => 'Địa chỉ giao',
        'delivery_phone' => 'SĐT giao', 'shipping_fee' => 'Phí ship', 'night_surcharge' => 'Phụ phí đêm', 'order_note' => 'Ghi chú',
    ];

    /** Ô phí hiện phí CHƯA gồm phụ phí đêm — cùng cách nhập với trang Tổng đài. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['shipping_fee'] = max(0, (int) ($data['shipping_fee'] ?? 0) - (int) ($data['night_surcharge'] ?? 0));

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->before = $this->record->only(array_keys(self::TRACKED));

        // Đơn đã hoàn thành/hủy chỉ cho sửa ghi chú; bỏ mọi trường khác dù payload gửi lên.
        if (OrderResource::isFinished($this->record)) {
            $data = array_intersect_key($data, ['order_note' => true]);
        }

        // Trạng thái chỉ được đổi qua các action nghiệp vụ của OrderResource.
        // Không cho payload sửa form ghi thẳng status/cancel_reason rồi bỏ qua
        // hoàn voucher, điểm tài xế, ví, realtime và notification.
        unset($data['status'], $data['cancel_reason']);

        if (array_key_exists('shipping_fee', $data)) {
            $data = $this->applyFee($data);
        }
        foreach (['pickup', 'delivery'] as $side) {
            $data = $this->applyCoordinates($data, $side);
        }

        return $data;
    }

    /**
     * Phí sửa tay: 0 (chưa biết) hoặc từ 1.000đ; đơn tổng đài tối đa như trang Tổng đài. Lưu shipping_fee đã gồm
     * phụ phí đêm (OrderResource::nightSurchargeFor), vì tài xế thu và công ty bù freeship theo đúng số này.
     */
    private function applyFee(array $data): array
    {
        $raw = trim((string) ($data['shipping_fee'] ?? ''));
        $base = $raw === '' ? 0 : (preg_match('/^\d{1,9}$/', $raw) ? (int) $raw : null);
        $max = $this->record->platform === 'call_center' ? CallCenterPage::MAX_FEE : null;

        $error = match (true) {
            $base === null => 'Phí ship chỉ nhập số nguyên không âm, ví dụ 25000.',
            $base > 0 && $base < 1000 => "Phí ship {$base}đ có vẻ thiếu số 0 — nhập đủ, ví dụ 25000.",
            $max !== null && $base > $max => 'Phí ship đơn tổng đài tối đa '.number_format($max, 0, ',', '.').'đ.',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['data.shipping_fee' => $error]);
        }

        $night = $base > 0 ? OrderResource::nightSurchargeFor($this->record) : 0;
        $data['shipping_fee'] = $base + $night;
        $data['night_surcharge'] = $night;

        return $data;
    }

    /**
     * Địa chỉ đổi thì toạ độ phải đổi theo, nếu không tài xế vẫn được dẫn tới ghim cũ. Chọn gợi ý/bản đồ đã gửi kèm
     * toạ độ mới; gõ tay thì tự geocode, không ra thì bắt chọn lại. Địa chỉ không đổi thì giữ toạ độ đang lưu.
     */
    private function applyCoordinates(array $data, string $side): array
    {
        $addrKey = "{$side}_address";
        [$latKey, $lngKey] = ["{$side}_lat", "{$side}_lng"];
        $newAddress = trim((string) ($data[$addrKey] ?? ''));

        if (! array_key_exists($addrKey, $data) || $newAddress === trim((string) $this->record->{$addrKey})) {
            unset($data[$latKey], $data[$lngKey]);

            return $data;
        }
        if ($newAddress === '') {
            $data[$latKey] = $data[$lngKey] = null;

            return $data;
        }

        $lat = is_numeric($data[$latKey] ?? null) ? (float) $data[$latKey] : null;
        $lng = is_numeric($data[$lngKey] ?? null) ? (float) $data[$lngKey] : null;
        $unchanged = $lat !== null && abs($lat - (float) $this->record->{$latKey}) < 1e-7 && abs($lng - (float) $this->record->{$lngKey}) < 1e-7;
        if ($lat === null || $lng === null || $unchanged) {
            $geo = GoogleMapService::geocode($newAddress.($this->record->city?->name ? ', '.$this->record->city->name : ''));
            if (empty($geo['lat']) || empty($geo['lng'])) {
                throw ValidationException::withMessages(["data.{$addrKey}" => 'Không tìm được vị trí của địa chỉ này — chọn từ gợi ý hoặc bấm "Bản đồ".']);
            }
            [$lat, $lng] = [(float) $geo['lat'], (float) $geo['lng']];
        }
        $data[$latKey] = $lat;
        $data[$lngKey] = $lng;

        return $data;
    }

    protected function afterSave(): void
    {
        $after = $this->record->refresh()->only(array_keys(self::TRACKED));
        $changes = [];
        foreach (self::TRACKED as $key => $label) {
            if ((string) ($this->before[$key] ?? '') !== (string) ($after[$key] ?? '')) {
                $old = $this->before[$key] ?? '';
                $changes[] = $label.': '.($old === '' || $old === null ? '—' : $old).' → '.(($after[$key] ?? '') === '' ? '—' : $after[$key]);
            }
        }
        if ($changes) {
            OrderTimeline::record($this->record, 'edited', 'Sửa đơn — '.implode('; ', $changes), auth()->id());
        }

        $this->notifyDriverOfNewAddress($after);
    }

    /** Đơn đang có tài xế mà đổi điểm lấy/giao: báo tài xế mở lại đơn. Lỗi gửi không được làm hỏng lần lưu. */
    private function notifyDriverOfNewAddress(array $after): void
    {
        $moved = array_filter(['điểm lấy' => 'pickup_address', 'điểm giao' => 'delivery_address'],
            fn (string $key) => (string) ($this->before[$key] ?? '') !== (string) ($after[$key] ?? ''));
        $driver = $this->record->driver;
        if (! $moved || ! in_array($this->record->status, ['assigned', 'processing'], true) || ! $driver?->fcm_token) {
            return;
        }

        try {
            FCMService::getInstance()->sendDriverNotice(
                $driver->fcm_token,
                "Đơn #{$this->record->code} vừa đổi ".implode(' và ', array_keys($moved)),
                (string) $this->record->{reset($moved)},
                ['type' => 'order_updated', 'order_id' => (string) $this->record->id]
            );
        } catch (\Throwable $e) {
            Log::warning('[EditOrder] address-change notice failed', ['order_id' => $this->record->id, 'message' => $e->getMessage()]);
        }
    }
}
