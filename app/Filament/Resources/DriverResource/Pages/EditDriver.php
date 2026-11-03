<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Services\DriverAccountService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin cá nhân, phương tiện và ca làm việc.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Xem hồ sơ')->icon('heroicon-o-eye'),
        ];
    }

    /** Giá trị trước khi sửa, để ghi lịch sử các thay đổi nhạy cảm sau khi lưu. */
    private array $before = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Ô Thành phố là select quan hệ belongsTo: Filament đã gán khu vực mới vào bản ghi (chưa lưu) trong lúc lấy
        // dữ liệu form, nên phải so với giá trị GỐC. So với $this->record->city_id thì luôn thấy "không đổi".
        $r = $this->record;
        $this->before = ['city_id' => $r->getOriginal('city_id'), 'phone' => $r->getOriginal('phone'), 'debt_enabled' => (bool) $r->getOriginal('debt_enabled')];

        if ((int) ($data['city_id'] ?? $r->city_id) !== (int) $this->before['city_id']) {
            $busy = $this->record->is_online || Order::where('delivery_man_id', $this->record->id)
                ->whereIn('status', ['assigned', 'processing'])->exists();
            if ($busy) {
                throw ValidationException::withMessages(['data.city_id' => 'Không thể đổi khu vực khi tài xế đang online hoặc còn đơn chưa hoàn thành.']);
            }
        }

        return $data;
    }

    /** Chạy sau khi lưu cả quan hệ (ca), nên việc gỡ ca của khu vực cũ không bị form ghi đè lại. */
    protected function afterSave(): void
    {
        app(DriverAccountService::class)->recordProfileChanges($this->record->refresh(), $this->before, auth()->id());
    }
}
