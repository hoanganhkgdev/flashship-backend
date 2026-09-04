<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Facades\Filament;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật trạng thái, phí duy trì và toạ độ khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Xoá khu vực')
                ->before(function (City $record, DeleteAction $action) {
                    if (Filament::getTenant()?->is($record)) {
                        Notification::make()->danger()
                            ->title('Không thể xoá khu vực đang chọn')
                            ->body('Hãy chuyển sang khu vực khác trước khi thao tác.')
                            ->send();
                        $action->halt();
                    }

                    $userCount = DB::table('users')->where('city_id', $record->id)->count();
                    $orderCount = DB::table('orders')->where('city_id', $record->id)->count();
                    if ($userCount > 0 || $orderCount > 0) {
                        Notification::make()->danger()
                            ->title('Không thể xoá khu vực này')
                            ->body("Còn {$userCount} tài khoản và {$orderCount} đơn hàng thuộc khu vực này.")
                            ->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
