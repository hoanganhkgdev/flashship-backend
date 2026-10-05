<?php

namespace App\Filament\Resources\BankListResource\Widgets;

use App\Filament\Resources\DriverResource;
use App\Services\BankCodeService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** KPI + tài khoản cần cập nhật đầu trang Danh sách ngân hàng. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class BankHealthWidget extends Widget
{
    protected static string $view = 'filament.resources.bank-list-resource.widgets.bank-health';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $legacy = BankCodeService::legacyAccounts();

        return [
            'total' => DB::table('bank_lists')->count(),
            'active' => DB::table('bank_lists')->where('is_active', true)->count(),
            'accounts' => DB::table('banks')->count(),
            'legacy' => $legacy->map(fn ($a) => (array) $a + [
                'match' => ($m = BankCodeService::match($a)) ? $m->name : null,
                'url' => DriverResource::getUrl('view', ['record' => $a->user_id]),
            ]),
        ];
    }
}
