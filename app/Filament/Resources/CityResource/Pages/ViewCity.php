<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\ShiftResource;
use App\Services\CatalogService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;

class ViewCity extends ViewRecord
{
    protected static string $resource = CityResource::class;

    protected static string $view = 'filament.resources.city-resource.pages.view-city';

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Sức khỏe, độ phủ giá và nhật ký thay đổi của khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa')->icon('heroicon-o-pencil-square'),
            CityResource::toggleAction(Actions\Action::make('toggle'))->after(fn () => $this->record->refresh()),
        ];
    }

    public function getOverview(): array
    {
        $c = $this->record;
        $health = CatalogService::health()[$c->id];
        $services = CatalogService::activeServices();
        $matrix = CatalogService::pricingMatrix();
        $logs = CatalogService::logs('city', $c->id);
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');
        $labels = ['created' => 'Tạo khu vực', 'updated' => 'Chỉnh sửa', 'activated' => 'Bật khu vực', 'deactivated' => 'Tắt khu vực', 'deleted' => 'Xóa'];

        return [
            'h' => $health,
            'pricing' => $services->map(fn ($label, $key) => ['label' => $label, 'ok' => (bool) ($matrix[$c->id][$key] ?? false)])->values(),
            'shifts' => Shift::where('city_id', $c->id)->orderBy('start_time')->get(),
            'shiftsUrl' => ShiftResource::getUrl('index', tenant: $c),
            'logs' => $logs->map(fn ($l) => [
                'label' => $labels[$l->action] ?? $l->action, 'detail' => $l->detail, 'when' => $l->created_at,
                'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
            ]),
        ];
    }
}
