<?php

namespace App\Filament\Support;

use Filament\Facades\Filament;

/** Khu vực đang chọn trên thanh trên cùng. Không có khu vực thì là 0: truy vấn ra rỗng, không bao giờ là "mọi khu vực". */
trait TenantCity
{
    protected function cityId(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }
}
