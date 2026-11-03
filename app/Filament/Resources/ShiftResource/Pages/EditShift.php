<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use App\Services\ShiftService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShift extends EditRecord
{
    protected static string $resource = ShiftResource::class;

    private array $before = [];

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Đổi giờ hoặc bật lại ca đang chạy thì thời gian vắng chỉ tính từ lúc đổi, không phạt ngược. Mọi thay đổi được ghi nhật ký.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Xoá ca')
                ->visible(fn () => ShiftService::undeletableReason($this->record) === null)
                ->modalDescription('Chỉ xóa được ca chưa có tài xế đăng ký và chưa từng phạt/chấm điểm tài xế.')
                ->after(fn () => ShiftService::log($this->record->id, 'deleted', auth()->id(), $this->record->name)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $r = $this->record;
        $this->before = ['start' => substr($r->start_time, 0, 5), 'end' => substr($r->end_time, 0, 5), 'active' => (bool) $r->is_active];

        // Ca đang bật mà đổi giờ (hoặc vừa được bật lại) không được làm tài xế đang đăng ký bị trùng giờ với ca khác.
        $start = substr((string) $data['start_time'], 0, 5);
        $end = substr((string) $data['end_time'], 0, 5);
        $active = (bool) ($data['is_active'] ?? false);
        $changed = $start !== $this->before['start'] || $end !== $this->before['end'] || (! $this->before['active'] && $active);
        if ($active && $changed && ($err = ShiftService::conflictMessage($r, $start, $end))) {
            Notification::make()->danger()->title('Chưa lưu được')->body($err)->persistent()->send();
            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $r = $this->record->refresh();
        $by = auth()->id();
        $start = substr($r->start_time, 0, 5);
        $end = substr($r->end_time, 0, 5);

        if ($start !== $this->before['start'] || $end !== $this->before['end']) {
            ShiftService::log($r->id, 'time_changed', $by, $this->before['start'].'–'.$this->before['end'].' → '.$start.'–'.$end);
        }
        if ((bool) $r->is_active !== $this->before['active']) {
            ShiftService::log($r->id, $r->is_active ? 'activated' : 'deactivated', $by);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
