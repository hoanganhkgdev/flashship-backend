<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDriverLeaveRequest extends CreateRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
