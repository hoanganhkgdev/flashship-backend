<?php

namespace App\Filament\Traits;

use App\Support\AdminAccess;

trait RestrictToFinance
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::FINANCE_VIEW);
    }
}
